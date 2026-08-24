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
     * Aggregated events keyed by video + ip + tracking id + day, or null on API failure so the
     * cursor stays put and the whole window is retried next run.
     *
     * @return array<string, array{video_ulid: string, ip: string, bytes: int, date: string, tracking_id: string, zone: string}>|null
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

                // The v2 log's `path` carries the query string, which is the only reason a
                // download link's `tid` can be attributed at all ({@see \App\Services\Cdn\BunnyProvider::downloadUrl}).
                $trackingId = $this->trackingId((string) ($line['path'] ?? ''));

                // The zone directory that follows the ULID is what separates a playback segment
                // from a downloaded master ({@see \App\Jobs\IngestBandwidthJob}); the log path is
                // the only place that distinction survives.
                $zone = $this->zone((string) ($line['path'] ?? ''), $match[1]);

                // Everything that distinguishes one stored row from another joins the key, for the
                // same reason it does in Vector's reduce: summing across zones, tracking ids or a
                // midnight boundary would collapse rows that have to stay apart.
                $key = "{$match[1]}|{$ip}|{$trackingId}|{$date}|{$zone}";
                $events[$key] ??= [
                    'video_ulid' => $match[1],
                    'ip' => $ip,
                    'bytes' => 0,
                    'date' => $date,
                    'tracking_id' => $trackingId,
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
     * The `tid` query parameter of a logged request, or '' when the link carried none.
     *
     * Clamped to the alphabet the request validation accepts rather than trusted: the value is
     * echoed into a URL by an API client and read back out of a log line. An id that did not
     * survive that round trip intact is reported as unattributed — never dropped, because the
     * bytes behind it were really delivered and really cost money.
     */
    private function trackingId(string $path): string
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        $trackingId = (string) ($query['tid'] ?? '');

        return preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $trackingId) === 1 ? $trackingId : '';
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
