<?php

namespace App\Console\Commands;

use App\Data\BunnyConfigData;
use App\Enums\CdnDriver;
use App\Jobs\IngestBandwidthJob;
use App\Services\Cdn\BunnyProvider;
use App\Settings\CdnSettings;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pulls request logs from Bunny's Logging API into the same bandwidth pipeline the
 * self-hosted edges feed (IngestBandwidthJob → ClickHouse `usage`). Polling
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

    // The widest window one request covers. A run that has fallen behind (an outage, a cursor
    // stuck behind a failing window) walks the backlog in slices of this size and commits the
    // cursor after each one, instead of asking for the whole gap at once: the API gets slower the
    // wider the range and the deeper the offset — a 90-minute window timed out on its second page
    // with 0 bytes received — and a window that always fails keeps the cursor pinned, so the next
    // run asks for an even wider one and never catches up until the retention floor eats the gap.
    private const MAX_WINDOW_SECONDS = 600;

    // Bunny has been seen not to answer within Laravel's default 30s. Generous, because a slow
    // page costs a few seconds, while a timed-out one throws away its whole slice for this run.
    private const TIMEOUT_SECONDS = 60;

    // Stop opening new slices past this point of a run: the scheduler's overlap lock expires after
    // 10 minutes, and a second run reading the same cursor would count the same traffic twice into
    // a SummingMergeTree. Whatever is left is the next run's (five minutes later) to pick up.
    private const RUN_BUDGET_SECONDS = 240;

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

        $deadline = now()->addSeconds(self::RUN_BUDGET_SECONDS);

        while ($from->lt($to) && now()->lt($deadline)) {
            $sliceEnd = $from->copy()->addSeconds(self::MAX_WINDOW_SECONDS)->min($to);
            $events = $this->fetch($config, $from, $sliceEnd);

            // The cursor stays at the end of the last slice that made it: the failed one is
            // retried next run, and the slices before it are never read twice.
            if ($events === null) {
                return self::FAILURE;
            }

            if ($events !== []) {
                IngestBandwidthJob::dispatch(array_values($events));
            }

            Cache::forever(self::CURSOR_KEY, $sliceEnd->toIso8601ZuluString());
            $this->info(sprintf('Ingested %d aggregated event(s) from %s to %s.', count($events), $from->toIso8601ZuluString(), $sliceEnd->toIso8601ZuluString()));

            $from = $sliceEnd;
        }

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
     * Aggregated events keyed by video + ip + token hash + day + zone, or null on API failure so
     * the cursor stays put and the whole window is retried next run.
     *
     * @return array<string, array{video_ulid: string, ip: string, bytes: int, date: string, token_hash: string, zone: string}>|null
     */
    private function fetch(BunnyConfigData $config, Carbon $from, Carbon $to): ?array
    {
        $events = [];
        $offset = 0;

        do {
            try {
                $response = Http::withHeaders(['AccessKey' => $config->apiKey])
                    ->acceptJson()
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get("https://logging.bunnycdn.com/v2/pullzones/{$config->pullZoneId}/logs", [
                        'from' => $from->toIso8601ZuluString(),
                        'to' => $to->toIso8601ZuluString(),
                        'status' => '2xx',
                        'order' => 'asc',
                        'limit' => self::PAGE_SIZE,
                        'offset' => $offset,
                    ]);
            } catch (ConnectionException $e) {
                // A timeout is a failed window like any 5xx, not a crash: without this it escaped
                // as an uncaught exception, and every run died the same way on the same window.
                $this->error("Bunny logging API unreachable ({$e->getMessage()}) — keeping the cursor to retry the window.");

                return null;
            }

            if ($response->failed()) {
                $this->error("Bunny logging API answered {$response->status()} — keeping the cursor to retry the window.");

                return null;
            }

            foreach ($response->json('data', []) as $line) {
                $bytes = (int) ($line['bytesSent'] ?? 0);
                $ip = (string) ($line['remoteIp'] ?? '');

                // `status=2xx` above is a server-side filter, and it is the only thing standing
                // between an error page's bytes and a customer's bill. Re-check it here: Bunny
                // hands us `statusCode` on every line, and a parameter the API someday renames or
                // ignores would otherwise book every 403 from an expired token as delivered
                // traffic — into a SummingMergeTree, so nothing takes it back.
                $status = (int) ($line['statusCode'] ?? 0);

                if ($status < 200 || $status >= 300) {
                    continue;
                }

                // The ULID sits between slashes; the bcdn_token prefix segment contains `=`/`&`
                // so it can never match. No ULID means the request wasn't for a video.
                if ($bytes <= 0 || $ip === '' || ! preg_match('#/([0-9A-Za-z]{26})/#', (string) ($line['path'] ?? ''), $match)) {
                    continue;
                }

                // Dated by the line's own timestamp, not by the window or the moment of ingestion.
                // `date` is what the analytics group on, the partition key and the TTL key, inside
                // a SummingMergeTree no later correction can take back — and a window CAN straddle
                // midnight (the sweep runs every five minutes, and `--from` or a cursor recovered
                // after an outage widens it to hours or days). Stamping the whole window with its
                // start booked post-midnight traffic to the previous day, permanently. The edge
                // pipeline already dates per line ({@see vector/vector.yaml}); this matches it.
                $date = $this->eventDate($line) ?? $from->toDateString();

                // The hash of the token the request carried, the same way the self-hosted edge
                // ships it ({@see vector/vector.yaml}): the job resolves it to the tracking id the
                // link was minted for, and the token itself goes no further than this loop.
                $tokenHash = $this->tokenHash((string) ($line['path'] ?? ''));

                // The zone directory that follows the ULID is what separates a playback segment
                // from a downloaded master ({@see \App\Jobs\IngestBandwidthJob}); the log path is
                // the only place that distinction survives.
                $zone = $this->zone((string) ($line['path'] ?? ''), $match[1]);

                // Everything that distinguishes one stored row from another joins the key, for the
                // same reason it does in Vector's reduce: summing across zones, tokens or a
                // midnight boundary would collapse rows that have to stay apart.
                $key = "{$match[1]}|{$ip}|{$tokenHash}|{$date}|{$zone}";
                $events[$key] ??= [
                    'video_ulid' => $match[1],
                    'ip' => $ip,
                    'bytes' => 0,
                    'date' => $date,
                    'token_hash' => $tokenHash,
                    'zone' => $zone,
                ];
                $events[$key]['bytes'] += $bytes;
            }

            $offset += self::PAGE_SIZE;
        } while ($response->json('pagination.hasMore') === true);

        return $events;
    }

    /**
     * The zone directory a logged request sits in — `play`, `download`, `assets` — taken from the
     * segment that follows the video ULID, or '' when the path has none. Anchored on the ULID's own
     * position rather than searched for, so a directory named `play` further down a path (or inside
     * the `bcdn_token=` prefix) cannot be mistaken for the zone.
     */
    private function zone(string $path, string $videoUlid): string
    {
        $position = strpos($path, "/{$videoUlid}/");

        if ($position === false) {
            return '';
        }

        $rest = substr($path, $position + strlen($videoUlid) + 2);
        $slash = strpos($rest, '/');

        return $slash === false ? '' : substr($rest, 0, $slash);
    }

    /**
     * The SHA-256 of the token a logged request carried, or '' when it carried none. Two
     * carriers, one per link kind ({@see BunnyProvider}): a playback link's token is the
     * `bcdn_token=` path prefix every segment inherits, a download link's is the `token` query
     * parameter — and the v2 log's `path` keeps both. The bytes must match what the mint hashed,
     * so the value is taken as it sits in the URL: Bunny's token alphabet is URL-safe, and a
     * value outside it could not have come from our signer, so it is treated as no token rather
     * than decoded into something the registry never saw.
     */
    private function tokenHash(string $path): string
    {
        if (preg_match('#^/bcdn_token=([A-Za-z0-9_-]+)#', $path, $match) === 1) {
            return hash('sha256', $match[1]);
        }

        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');

        return preg_match('/^[A-Za-z0-9_-]+\z/', $token) === 1 ? hash('sha256', $token) : '';
    }

    /**
     * The UTC day a logged request happened, or null when the line carries no usable timestamp and
     * the caller has to fall back to the window.
     *
     * Normalised to UTC rather than taken as written: Bunny stamps its lines with an offset
     * (`2026-08-21T22:53:39.963+00:00`), and a zone-local reading would shift a day boundary that
     * the partition key can never be corrected across.
     *
     * @param  array<string, mixed>  $line
     */
    private function eventDate(array $line): ?string
    {
        if (empty($line['timestamp'])) {
            return null;
        }

        try {
            return Carbon::parse((string) $line['timestamp'])->utc()->toDateString();
        } catch (\Throwable) {
            // A line we cannot date is still a line we can count; the window date stands in.
            return null;
        }
    }
}
