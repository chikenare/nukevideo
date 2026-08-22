<?php

namespace App\Jobs;

use App\Models\Video;
use ClickHouseDB\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a batch of aggregated bandwidth events to ClickHouse `usage`, the single metrics table.
 *
 * The video and IP come straight from the edge log (the URL path + request); the owning account and
 * the integrator's own customer are resolved here, from the video, in one batched lookup. That
 * resolution is the reason nothing has to travel in the URL to be trustworthy: a viewer can rewrite
 * a link all they like and it cannot change who the bytes are billed to.
 */
class IngestBandwidthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    /**
     * Which metric a request counts towards, by the zone directory its path sits in
     * ({@see Video::PLAY_DIR} and friends). Splitting delivery this way is the whole
     * point of folding `video_usage` into `usage`: the old table had one `bytes` column and no way
     * to tell a playback segment from a downloaded master.
     *
     * The unit lives in the metric name, deliberately — `value` is a shared Float64 that means
     * seconds for `encoding_cpu` and bytes here, and nothing in the schema says which.
     */
    private const ZONE_METRICS = [
        'play' => 'streaming_bytes',
        'download' => 'download_bytes',
        'assets' => 'asset_bytes',
    ];

    /**
     * Delivered bytes we cannot place in a zone. They are still counted, under a generic metric,
     * because the point of this pipeline is bandwidth: an unrecognised path costs its label, never
     * its bytes. Also the metric the pre-merge history was carried over under.
     */
    private const FALLBACK_METRIC = 'bandwidth_bytes';

    /** @param array<int, array{video_ulid?: string, ip?: string, bytes?: int|string, date?: string, tid?: string, zone?: string}> $events */
    public function __construct(public array $events) {}

    /**
     * The caller's own tracking id, as it survived a round trip through a CDN access log. Treated
     * as hostile text: it is echoed into a URL by an API client and read back out of a log line, so
     * it is clamped to the same alphabet the request validation accepts rather than trusted. An
     * empty string is the column's own default and simply means "not attributed".
     *
     * @param  array<string, mixed>  $event
     */
    private function trackingId(array $event): string
    {
        $tid = (string) ($event['tid'] ?? '');

        return preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $tid) === 1 ? $tid : '';
    }

    /** @param array<string, mixed> $event */
    private function metric(array $event): string
    {
        return self::ZONE_METRICS[(string) ($event['zone'] ?? '')] ?? self::FALLBACK_METRIC;
    }

    public function handle(): void
    {
        $valid = [];
        $ulids = [];

        foreach ($this->events as $event) {
            $videoUlid = (string) ($event['video_ulid'] ?? '');
            $bytes = (int) ($event['bytes'] ?? 0);
            $ip = (string) ($event['ip'] ?? '');

            // The ulid is parsed out of a public request path, so it is attacker-controlled text
            // until it matches the shape: anything else must never reach a stored column. The
            // address gets the same treatment for a blunter reason — the column is `IPv6`, which
            // cannot parse '' or '-', and ClickHouse rejects the whole block over one bad value.
            // Dropping the row loses one event; letting it through loses the entire batch.
            if (! Str::isUlid($videoUlid) || $bytes <= 0 || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $valid[] = [$videoUlid, $ip, $bytes, $this->eventDate($event), $this->trackingId($event), $this->metric($event)];
            $ulids[$videoUlid] = true;
        }

        if ($valid === []) {
            return;
        }

        // One batched lookup for both attributes the log cannot carry. A video that is gone leaves
        // its bytes under account 0 rather than dropping them.
        $videos = Video::whereIn('ulid', array_keys($ulids))
            ->get(['ulid', 'user_id', 'external_user_id'])
            ->keyBy('ulid');

        // Ingest time, not traffic time — the ingest runs every five minutes over a window that
        // ends two minutes in the past, so the run just after midnight books the tail of the old
        // day to the new one. `date` is the column every analytics query groups on and the
        // partition key, so that slice is misattributed permanently. Prefer a date carried on the
        // event; the fallback is only for events emitted before the edge started sending one.
        $ingestedOn = now()->format('Y-m-d');
        $columns = ['date', 'user_id', 'metric', 'external_user_id', 'video_ulid', 'ip', 'tid', 'value'];
        $rows = [];

        foreach ($valid as [$videoUlid, $ip, $bytes, $date, $tid, $metric]) {
            $video = $videos->get($videoUlid);

            $rows[] = [
                $date ?? $ingestedOn,
                (int) ($video->user_id ?? 0),
                $metric,
                (string) ($video->external_user_id ?? ''),
                $videoUlid,
                $ip,
                $tid,
                $bytes,
            ];
        }

        try {
            // ClickHouse is behind TLS everywhere but local dev — staging included. Keep this
            // rule in lockstep with UsageService.
            app(Client::class)
                ->https(! app()->isLocal())
                ->insert('usage', $rows, $columns);
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest bandwidth batch: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * The day the traffic actually happened, when the edge reports it. `usage` is a
     * SummingMergeTree, so a row can only ever be added — a date that drifts by a few minutes at
     * a boundary is not something a later correction can take back.
     *
     * @param  array<string, mixed>  $event
     */
    private function eventDate(array $event): ?string
    {
        foreach (['date', 'timestamp'] as $key) {
            if (empty($event[$key])) {
                continue;
            }

            $stamp = strtotime((string) $event[$key]);

            if ($stamp !== false) {
                return date('Y-m-d', $stamp);
            }
        }

        return null;
    }
}
