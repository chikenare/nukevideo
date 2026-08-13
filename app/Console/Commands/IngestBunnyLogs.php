<?php

namespace App\Console\Commands;

use App\Data\BunnyConfigData;
use App\Enums\CdnDriver;
use App\Jobs\IngestBandwidthJob;
use App\Settings\CdnSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pulls request logs from Bunny's Logging API into the same bandwidth pipeline the
 * self-hosted edges feed (IngestBandwidthJob → ClickHouse `video_usage`). Polling
 * beats Bunny's UDP log forwarding here: no listener to run, and the API is a
 * complete record where UDP is best-effort.
 */
class IngestBunnyLogs extends Command
{
    protected $signature = 'bunny:ingest-logs {--from= : Window start (ISO 8601), overrides the stored cursor}';

    protected $description = 'Pull Bunny CDN request logs into bandwidth analytics';

    // Where the last ingested window ended; the next run starts there.
    private const CURSOR_KEY = 'bunny-ingest-logs:cursor';

    // Bunny publishes logs "near real-time": trail behind so a window is only read once complete.
    private const LAG_SECONDS = 120;

    // Without a cursor (first run, flushed cache) reach back only this far: re-reading a wide
    // window would double-count into the SummingMergeTree.
    private const DEFAULT_LOOKBACK_SECONDS = 600;

    private const PAGE_SIZE = 1000;

    public function handle(CdnSettings $settings): int
    {
        if ($settings->provider !== CdnDriver::Bunny->value) {
            return self::SUCCESS;
        }

        $config = BunnyConfigData::from($settings->providers['bunny'] ?? []);

        if ($config->apiKey === '' || $config->pullZoneId === '') {
            $this->warn('Bunny API key or pull zone id missing from the CDN settings — nothing to ingest.');

            return self::SUCCESS;
        }

        $to = now()->subSeconds(self::LAG_SECONDS);
        $from = $this->windowStart($to);

        if ($from->gte($to)) {
            return self::SUCCESS;
        }

        $events = $this->fetch($config, $from, $to);

        if ($events === null) {
            return self::FAILURE;
        }

        if ($events !== []) {
            IngestBandwidthJob::dispatch(array_values($events));
        }

        Cache::forever(self::CURSOR_KEY, $to->toIso8601ZuluString());
        $this->info(sprintf('Ingested %d aggregated event(s) from %s to %s.', count($events), $from->toIso8601ZuluString(), $to->toIso8601ZuluString()));

        return self::SUCCESS;
    }

    private function windowStart(Carbon $to): Carbon
    {
        $cursor = $this->option('from') ?? Cache::get(self::CURSOR_KEY);
        $from = $cursor ? Carbon::parse($cursor) : $to->copy()->subSeconds(self::DEFAULT_LOOKBACK_SECONDS);

        // Bunny retains logs for 3 days; a cursor older than that leaves a gap we cannot close.
        $floor = now()->subDays(3)->addHour();

        if ($from->lt($floor)) {
            $this->warn("Cursor {$from->toIso8601ZuluString()} is beyond Bunny's log retention — older traffic is lost.");

            return $floor;
        }

        return $from;
    }

    /**
     * Aggregated events keyed by video + ip, or null on API failure so the cursor
     * stays put and the whole window is retried next run.
     *
     * @return array<string, array{video_ulid: string, ip: string, bytes: int}>|null
     */
    private function fetch(BunnyConfigData $config, Carbon $from, Carbon $to): ?array
    {
        $events = [];
        $offset = 0;

        do {
            $response = Http::withHeaders(['AccessKey' => $config->apiKey])
                ->acceptJson()
                ->get("https://logging.bunnycdn.com/v2/pullzones/{$config->pullZoneId}/logs", [
                    'from' => $from->toIso8601ZuluString(),
                    'to' => $to->toIso8601ZuluString(),
                    'status' => '2xx',
                    'order' => 'asc',
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                ]);

            if ($response->failed()) {
                $this->error("Bunny logging API answered {$response->status()} — keeping the cursor to retry the window.");

                return null;
            }

            foreach ($response->json('data', []) as $line) {
                $bytes = (int) ($line['bytesSent'] ?? 0);
                $ip = (string) ($line['remoteIp'] ?? '');

                // The ULID sits between slashes; the bcdn_token prefix segment contains `=`/`&`
                // so it can never match. No ULID means the request wasn't for a video.
                if ($bytes <= 0 || $ip === '' || ! preg_match('#/([0-9A-Za-z]{26})/#', (string) ($line['path'] ?? ''), $match)) {
                    continue;
                }

                // Dated by the window, not by the moment of ingestion. This sweep runs every five
                // minutes over a window ending two minutes in the past, so the run just after
                // midnight covers the tail of the previous day — and `date` is what the analytics
                // group on, the partition key and the TTL key, inside a SummingMergeTree no later
                // correction can take back. The window is minutes wide, so its start stands in
                // unambiguously for every event within it.
                // The v2 log's `path` carries the query string, which is the only reason a
                // download link's `tid` can be attributed at all ({@see \App\Services\Cdn\BunnyProvider::downloadUrl}).
                // It is part of the grouping key: summing across tracking ids would merge two
                // customers' traffic into one row and lose exactly what the id was added for.
                $tid = $this->trackingId((string) ($line['path'] ?? ''));

                $key = "{$match[1]}|{$ip}|{$tid}";
                $events[$key] ??= [
                    'video_ulid' => $match[1],
                    'ip' => $ip,
                    'bytes' => 0,
                    'date' => $from->toDateString(),
                    'tid' => $tid,
                ];
                $events[$key]['bytes'] += $bytes;
            }

            $offset += self::PAGE_SIZE;
        } while ($response->json('pagination.hasMore') === true);

        return $events;
    }

    /** The `tid` query parameter of a logged request, or '' when the link carried none. */
    private function trackingId(string $path): string
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        $tid = (string) ($query['tid'] ?? '');

        return preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $tid) === 1 ? $tid : '';
    }
}
