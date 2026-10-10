<?php

namespace App\Console\Commands;

use App\Services\GeoIp;
use App\Services\UsageService;
use App\Support\IpAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Carries the history out of the ClickHouse `usage` table into `usage_daily`. Run once, after the
 * release that moved the writes: from that moment new traffic lands in MariaDB and ClickHouse
 * stops growing, so what it holds is exactly what is missing here — including the first part of
 * the day of the switch, which is why this ADDS to a day rather than replacing it.
 *
 * Adding is also what makes a second run dangerous: it would count the whole history twice. Each
 * day is therefore one transaction, and a day that committed is remembered and skipped, so a run
 * that died halfway is simply run again. `--force` is for a deliberate re-import into a table that
 * was emptied first, and for nothing else.
 *
 * It speaks ClickHouse's HTTP interface directly, with the connection passed in, so the client
 * package and its configuration did not have to outlive the migration for this one read. The
 * command itself can be deleted once every installation that had a ClickHouse has run it.
 */
class ImportClickhouseUsage extends Command
{
    protected $signature = 'usage:import-clickhouse
        {--url= : ClickHouse HTTP endpoint, e.g. http://clickhouse:8123}
        {--user=default : ClickHouse user}
        {--password= : ClickHouse password}
        {--database=default : Database holding the `usage` table}
        {--from= : First day to import (Y-m-d)}
        {--to= : Last day to import (Y-m-d)}
        {--force : Import days already imported once — this counts them twice unless usage_daily was emptied}';

    protected $description = 'One-off: copy the ClickHouse usage history into usage_daily';

    private const MARKER_PREFIX = 'usage:clickhouse-import:';

    // Rows handed to one write. A busy day is hundreds of thousands of them; this keeps the
    // command's memory flat however many there are.
    private const BATCH = 2000;

    private const READ_TIMEOUT_SECONDS = 600;

    public function __construct(private GeoIp $geoIp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('url')) {
            $this->error('Pass --url (and --user/--password/--database): the application no longer carries a ClickHouse connection of its own.');

            return self::FAILURE;
        }

        // The history gets its countries from today's database — the old table never had them.
        // Fetched here because this runs in whichever container it was started in, and that one
        // may not hold a copy. Best effort: without it the rows import with no country.
        try {
            if ($this->geoIp->missing()) {
                $this->geoIp->refresh();
            }
        } catch (\Throwable $e) {
            $this->warn('Could not download the country database: '.$e->getMessage());
        }

        if (! is_file($this->geoIp->path())) {
            $this->warn('No country database: the history is imported without countries.');
        }

        $days = $this->days();

        if ($days === []) {
            $this->info('Nothing to import: the ClickHouse table has no rows in that range.');

            return self::SUCCESS;
        }

        $imported = 0;

        foreach ($days as $day) {
            if (! $this->option('force') && Cache::has(self::MARKER_PREFIX.$day)) {
                $this->line("{$day}: already imported, skipped");

                continue;
            }

            // One transaction per day rather than one for the whole run: the ingest keeps writing
            // today's rows while this runs, and a single transaction would hold its locks for as
            // long as the entire history takes.
            $rows = DB::transaction(fn () => $this->importDay($day));

            Cache::forever(self::MARKER_PREFIX.$day, true);
            $imported += $rows;

            $this->line("{$day}: {$rows} rows");
        }

        $this->info("Imported {$imported} rows across ".count($days).' days.');

        return self::SUCCESS;
    }

    /**
     * @return list<string> the days ClickHouse holds rows for, oldest first
     */
    private function days(): array
    {
        $where = ['1'];

        // Validated to the one shape before they are spliced in: the HTTP interface is spoken raw
        // here, and these come off a command line.
        foreach (['from' => '>=', 'to' => '<='] as $option => $operator) {
            $value = $this->option($option);

            if ($value === null) {
                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
                throw new RuntimeException("--{$option} must be a date as Y-m-d.");
            }

            $where[] = "date {$operator} '{$value}'";
        }

        $days = [];

        foreach ($this->select('SELECT DISTINCT toString(date) AS date FROM usage WHERE '.implode(' AND ', $where).' ORDER BY date') as $row) {
            $days[] = $row['date'];
        }

        return $days;
    }

    private function importDay(string $day): int
    {
        // Grouped on every dimension, so what arrives is already what a fully merged
        // SummingMergeTree would hold, whether or not its parts ever were merged.
        $query = <<<SQL
            SELECT
                user_id, project_id, metric, external_user_id, video_ulid,
                IPv6NumToString(ip) AS ip, tracking_id, node_id, cache,
                sum(value) AS value
            FROM usage
            WHERE date = '{$day}'
            GROUP BY user_id, project_id, metric, external_user_id, video_ulid, ip, tracking_id, node_id, cache
        SQL;

        $batch = [];
        $count = 0;

        foreach ($this->select($query) as $row) {
            $batch[] = [
                'date' => $day,
                'project_id' => (int) $row['project_id'],
                'user_id' => (int) $row['user_id'],
                'metric' => $row['metric'],
                'external_user_id' => $row['external_user_id'],
                'video_ulid' => $row['video_ulid'],
                'ip' => $this->ip($row['ip']),
                'tracking_id' => $row['tracking_id'],
                'node_id' => (int) $row['node_id'],
                'cache' => $row['cache'],
                'value' => (float) $row['value'],
                // The old table never counted them. 0 is "unknown" — a made-up 1 per row would be
                // summed with the real counts that follow and be impossible to tell from them.
                'requests' => 0,
            ];
            $count++;

            if (count($batch) === self::BATCH) {
                $this->write($batch);
                $batch = [];
            }
        }

        $this->write($batch);

        return $count;
    }

    /** @param list<array<string, mixed>> $batch */
    private function write(array $batch): void
    {
        $countries = $this->geoIp->countries(array_column($batch, 'ip'));

        foreach ($batch as &$row) {
            $row['country'] = $countries[$row['ip']] ?? '';
        }

        UsageService::add($batch);
    }

    /**
     * The address as the new table spells it. `::` is ClickHouse's zero for the column — what an
     * upload or an encoding row, which has no viewer, was stored with — and means "none" here.
     */
    private function ip(string $stored): string
    {
        return $stored === '::' ? '' : (IpAddress::canonical($stored) ?? '');
    }

    /**
     * Runs a query and yields its rows one at a time off the response stream, so a day of any size
     * is never held in memory whole.
     *
     * ClickHouse answers 200 and then reports a mid-query failure as text in the body, so a line
     * that is not a JSON object is an error rather than something to skip: skipping it would
     * commit a truncated day and mark it done.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function select(string $query): \Generator
    {
        $response = Http::withHeaders([
            'X-ClickHouse-User' => (string) $this->option('user'),
            'X-ClickHouse-Key' => (string) $this->option('password'),
        ])
            ->withQueryParameters(['database' => (string) $this->option('database')])
            // The grouped read of a busy day can take ClickHouse a while to start answering, far
            // longer than the client's default 30 seconds.
            ->connectTimeout(10)
            ->timeout(self::READ_TIMEOUT_SECONDS)
            ->withOptions(['stream' => true])
            ->withBody($query.' FORMAT JSONEachRow', 'text/plain')
            ->post(rtrim((string) $this->option('url'), '/').'/')
            ->throw();

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(65536);

            while (($end = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);

                if ($line !== '') {
                    yield $this->decode($line);
                }
            }
        }

        if (trim($buffer) !== '') {
            yield $this->decode($buffer);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $line): array
    {
        $row = json_decode($line, true);

        if (! is_array($row)) {
            throw new RuntimeException('ClickHouse did not answer with a row: '.substr($line, 0, 300));
        }

        return $row;
    }
}
