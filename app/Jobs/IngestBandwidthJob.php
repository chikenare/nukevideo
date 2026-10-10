<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Services\GeoIp;
use App\Services\UsageService;
use App\Support\IpAddress;
use App\Support\TrackingId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a batch of aggregated bandwidth events to `usage_daily`, the single metrics table.
 *
 * The video and IP come straight from the edge log (the URL path + request); the owning account and
 * the integrator's own customer are resolved here, from the video, in one batched lookup, and the
 * tracking id from the hash of the link's token, in another ({@see TrackingRegistry}). That
 * resolution is the reason nothing has to travel in the URL to be trustworthy: a viewer can rewrite
 * a link all they like and it cannot change who the bytes are billed to, nor whose label they
 * land under.
 */
class IngestBandwidthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    /**
     * Which metric a request counts towards, by the zone directory its path sits in
     * ({@see Video::PLAY_DIR} and friends). Splitting delivery this way is the whole
     * point of keeping delivery in the metrics table: one `bytes` column would have no way to tell
     * a playback segment from a downloaded master.
     *
     * The unit lives in the metric name, deliberately — `value` is a shared double that means
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

    /**
     * Bytes the edge had to fetch from S3 to serve a request — the cost side of the cache. Booked
     * under account 0, the same bucket the delivery of deleted videos lands in: it is the
     * operator's number, not the customer's, and account 0 is what `/api/usage` can never be
     * asked for. Anything reading account 0 therefore has to filter by metric, since it holds
     * both populations.
     */
    public const ORIGIN_METRIC = 'origin_bytes';

    /**
     * What nginx can say about a cache lookup. Clamped to the set rather than stored as received,
     * because the value is parsed out of a log line: an unknown word becomes '' — "unknown" —
     * which the hit-ratio query leaves out, and never a string wider than the column. `OFF` is
     * the download location (no cache), `BYPASS` the manifests.
     */
    private const CACHE_STATUSES = ['HIT', 'MISS', 'EXPIRED', 'STALE', 'UPDATING', 'REVALIDATED', 'BYPASS', 'OFF'];

    /** @param array<int, array{video_ulid?: string, ip?: string, bytes?: int|string, date?: string, token_hash?: string, tracking_id?: string, zone?: string, cache?: string, origin?: int|string, node?: int|string, requests?: int|string}> $events */
    public function __construct(public array $events) {}

    /**
     * The tracking ids of a batch, keyed by token hash. A producer sends either `token_hash` (the
     * self-hosted edge: Vector hashes the token off the log line, and the mapping the mint
     * recorded is only reachable from here) or an already resolved `tracking_id` (a producer that
     * resolved it itself); an event with neither, or with a hash nothing was recorded under, is
     * unattributed. One cache round trip per batch, not per row: a session is hundreds of lines
     * under one token.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, string|null>
     */
    private function resolveTrackingIds(array $events, TrackingRegistry $registry): array
    {
        $hashes = [];

        foreach ($events as $event) {
            $hash = (string) ($event['token_hash'] ?? '');

            // The hash is computed by our own Vector, but it still arrives over HTTP: anything
            // that is not a hex SHA-256 never becomes a cache key.
            if (preg_match('/^[a-f0-9]{64}\z/', $hash) === 1) {
                $hashes[] = $hash;
            }
        }

        return $hashes === [] ? [] : $registry->resolveMany($hashes);
    }

    /**
     * The caller's own tracking id for one event. Resolved from the token hash when the event
     * carries one, else taken as sent. Either way it is clamped to the alphabet the request
     * validation accepts rather than trusted: a mapped value was validated at the mint, but a
     * `tracking_id` sent as-is is text that crossed a log and a queue. An empty string is the
     * column's own default and simply means "not attributed".
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, string|null>  $resolved
     */
    private function trackingId(array $event, array $resolved): string
    {
        $id = $resolved[(string) ($event['token_hash'] ?? '')] ?? (string) ($event['tracking_id'] ?? '');

        return TrackingId::isValid($id) ? $id : '';
    }

    /** @param array<string, mixed> $event */
    private function metric(array $event): string
    {
        return self::ZONE_METRICS[(string) ($event['zone'] ?? '')] ?? self::FALLBACK_METRIC;
    }

    /** @param array<string, mixed> $event */
    private function cacheStatus(array $event): string
    {
        $status = strtoupper((string) ($event['cache'] ?? ''));

        return in_array($status, self::CACHE_STATUSES, true) ? $status : '';
    }

    /**
     * The edge's node id, 0 when the line predates the field. An unsigned smallint in the table,
     * and a node id comes out of an auto-increment, so the clamp only guards the parse.
     *
     * @param  array<string, mixed>  $event
     */
    private function nodeId(array $event): int
    {
        return max(0, min(65535, (int) ($event['node'] ?? 0)));
    }

    public function handle(TrackingRegistry $registry, GeoIp $geoIp): void
    {
        $valid = [];
        $ulids = [];
        $resolved = $this->resolveTrackingIds($this->events, $registry);

        foreach ($this->events as $event) {
            $videoUlid = (string) ($event['video_ulid'] ?? '');
            $bytes = (int) ($event['bytes'] ?? 0);
            $ip = IpAddress::canonical((string) ($event['ip'] ?? ''));

            // The ulid is parsed out of a public request path, so it is attacker-controlled text
            // until it matches the shape: anything else must never reach a stored column. The
            // address gets the same treatment: '' and '-' are what a log line carries when there
            // was none, and a row with no viewer behind it would only inflate the distinct counts.
            if (! Str::isUlid($videoUlid) || $bytes <= 0 || $ip === null) {
                continue;
            }

            $valid[] = [
                $videoUlid, $ip, $bytes, $this->eventDate($event), $this->trackingId($event, $resolved), $this->metric($event),
                $this->nodeId($event), $this->cacheStatus($event), max(0, (int) ($event['origin'] ?? 0)), $this->requests($event),
            ];
            $ulids[$videoUlid] = true;
        }

        if ($valid === []) {
            return;
        }

        // One batched lookup for every attribute the log cannot carry. A video that is gone leaves
        // its bytes under account 0 and project 0 rather than dropping them.
        $videos = Video::whereIn('ulid', array_keys($ulids))
            ->get(['ulid', 'user_id', 'project_id', 'external_user_id'])
            ->keyBy('ulid');

        // Ingest time, not traffic time — the ingest runs every five minutes over a window that
        // ends two minutes in the past, so the run just after midnight books the tail of the old
        // day to the new one. `date` is the column every analytics query groups on, so that slice
        // is misattributed permanently. Prefer a date carried on the event; the fallback is only
        // for events emitted before the edge started sending one.
        $ingestedOn = now()->format('Y-m-d');
        $rows = [];

        // The viewer's country, resolved here and stored: a report by country is then a GROUP BY,
        // not a lookup per stored address. Empty when there is no database or no match.
        $countries = $geoIp->countries(array_column($valid, 1));

        // This batch goes without; the next one finds the file. Never downloaded inline, and
        // never allowed to fail the batch: the bytes must not depend on a queue push for a file
        // they can do without.
        rescue(fn () => RefreshGeoIpJob::dispatchIfMissing($geoIp));

        foreach ($valid as [$videoUlid, $ip, $bytes, $date, $trackingId, $metric, $nodeId, $cache, $origin, $requests]) {
            $video = $videos->get($videoUlid);

            $rows[] = [
                'date' => $date ?? $ingestedOn,
                'user_id' => (int) ($video->user_id ?? 0),
                'project_id' => (int) ($video->project_id ?? 0),
                'metric' => $metric,
                'external_user_id' => (string) ($video->external_user_id ?? ''),
                'video_ulid' => $videoUlid,
                'ip' => $ip,
                'country' => $countries[$ip] ?? '',
                'tracking_id' => $trackingId,
                'node_id' => $nodeId,
                'cache' => $cache,
                'value' => $bytes,
                'requests' => $requests,
            ];

            // The origin's side, as its own row: `value` is the one summed column, so the bytes
            // fetched from S3 cannot ride along on the delivery row. Keyed by node and video —
            // what the operator asks about — and by nothing a customer is billed on.
            if ($origin > 0) {
                // Project 0 as well as account 0: origin egress is the operator's cost, not any
                // tenant's traffic, and must not fall inside a project-scoped read. No requests of
                // its own either — they are the ones already counted on the delivery row.
                $rows[] = [
                    'date' => $date ?? $ingestedOn,
                    'metric' => self::ORIGIN_METRIC,
                    'video_ulid' => $videoUlid,
                    'ip' => $ip,
                    'country' => $countries[$ip] ?? '',
                    'node_id' => $nodeId,
                    'value' => $origin,
                    'requests' => 0,
                ];
            }
        }

        try {
            UsageService::add($rows);
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest bandwidth batch: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * How many requests the event stands for. The edge sums them alongside the bytes; one that
     * predates the field, or a producer that does not count, is at least the one request that
     * moved the bytes — an undercount, never a zero that would read as "nothing was asked for".
     *
     * @param  array<string, mixed>  $event
     */
    private function requests(array $event): int
    {
        return max(1, (int) ($event['requests'] ?? 1));
    }

    /**
     * The day the traffic actually happened, when the edge reports it. The row is summed into its
     * day's total, so a date that drifts by a few minutes at a boundary is not something a later
     * correction can pick back out.
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
