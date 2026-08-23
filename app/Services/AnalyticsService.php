<?php

namespace App\Services;

use App\Data\Stream\DownloadStreamData;
use App\Jobs\IngestBandwidthJob;
use ClickHouseDB\Client;

class AnalyticsService
{
    /**
     * The metrics of `usage` that measure delivered bytes. `usage` holds every metric in one
     * `value` column — bytes here, seconds for `encoding_cpu` — so a bandwidth query that did not
     * constrain this would silently add seconds to bytes and return a number that means nothing.
     *
     * Interpolated rather than bound because the client cannot bind an `Array(String)` parameter,
     * and because this is a class constant: no caller-supplied text ever reaches it. Every value a
     * caller CAN influence stays a bound parameter below.
     *
     * `bandwidth_bytes` is the generic one: the zone-less fallback, and what the pre-merge history
     * was carried over under ({@see database/clickhouse-migrations}).
     */
    private const BANDWIDTH_METRICS = "'streaming_bytes', 'download_bytes', 'asset_bytes', 'bandwidth_bytes'";

    private Client $client;

    public function __construct()
    {
        $this->client = app(Client::class);
    }

    /**
     * The `usage` predicate shared by every bandwidth query, plus the bound parameters it needs.
     * The metric constraint is not optional: without it these queries would read upload volume and
     * encoding seconds as if they were delivered bytes.
     *
     * The filters are optional and every one is bound, never interpolated — `video_ulid` and `tid`
     * are written from CDN access logs, so their values are caller-controlled text that must never
     * reach the statement itself.
     *
     * @param  array<string, mixed>  $params  filled in place with the bindings this clause adds
     * @return string the WHERE body, without the `WHERE` keyword
     */
    private function bandwidthFilter(string $from, string $to, ?string $video, ?string $tid, array &$params, ?string $metric = null): string
    {
        $where = ['date >= {from:Date}', 'date <= {to:Date}', 'metric IN ('.self::BANDWIDTH_METRICS.')'];
        $params += ['from' => $from, 'to' => $to];

        // Narrowing to a single delivery metric — streaming alone, downloads alone. Bound, and
        // still inside the constant set above, so an unknown metric simply matches nothing rather
        // than reaching across into upload or encoding rows.
        if ($metric !== null && $metric !== '') {
            $where[] = 'metric = {metric:String}';
            $params['metric'] = $metric;
        }

        if ($video !== null && $video !== '') {
            $where[] = 'video_ulid = {video:String}';
            $params['video'] = $video;
        }

        // An empty string is a legitimate value here — it is the column default and means "traffic
        // that carried no tracking id" — so the filter is applied on `!== null`, not on emptiness.
        // Asking for it is how a caller isolates its unattributed traffic.
        if ($tid !== null) {
            $where[] = 'tid = {tid:String}';
            $params['tid'] = $tid;
        }

        return implode(' AND ', $where);
    }

    public function summary(string $from, string $to, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = [];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        $result = $this->client->select(
            "SELECT
                sum(value) AS total_bytes,
                uniqExact(video_ulid) AS unique_videos,
                uniqExact(ip) AS unique_ips,
                uniqExact(tid) AS unique_tracking_ids
             FROM usage
             WHERE {$where}",
            $params
        );

        $row = $result->fetchOne();

        return [
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'unique_videos' => (int) ($row['unique_videos'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
            'unique_tracking_ids' => (int) ($row['unique_tracking_ids'] ?? 0),
        ];
    }

    public function bandwidthOverTime(string $from, string $to, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = [];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        $result = $this->client->select(
            "SELECT
                date,
                sum(value) AS bytes,
                uniqExact(ip) AS sessions
             FROM usage
             WHERE {$where}
             GROUP BY date
             ORDER BY date",
            $params
        );

        return $result->rows();
    }

    public function topIps(string $from, string $to, int $limit = 10, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        $result = $this->client->select(
            "SELECT
                IPv6NumToString(ip) AS ip,
                sum(value) AS bytes,
                uniqExact(video_ulid) AS sessions
             FROM usage
             WHERE {$where}
             GROUP BY ip
             ORDER BY bytes DESC
             LIMIT {limit:UInt8}",
            $params
        );

        return $result->rows();
    }

    /**
     * Bandwidth broken down by the caller-supplied tracking id a download link carried
     * ({@see DownloadStreamData}). This is the read side of that feature:
     * without it the id is written to ClickHouse and never surfaces anywhere.
     *
     * Traffic that carried no id is reported under an empty `tid` rather than dropped — it is real
     * bandwidth, and hiding it would make the breakdown fail to add up to the total.
     */
    public function bandwidthByTrackingId(string $from, string $to, int $limit = 10, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        $result = $this->client->select(
            "SELECT
                tid,
                sum(value) AS bytes,
                uniqExact(video_ulid) AS videos,
                uniqExact(ip) AS unique_ips
             FROM usage
             WHERE {$where}
             GROUP BY tid
             ORDER BY bytes DESC
             LIMIT {limit:UInt8}",
            $params
        );

        return $result->rows();
    }

    public function topVideos(string $from, string $to, int $limit = 10, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        $result = $this->client->select(
            "SELECT
                video_ulid AS video,
                '' AS external_resource_id,
                sum(value) AS bytes,
                uniqExact(ip) AS sessions,
                uniqExact(ip) AS unique_ips
             FROM usage
             WHERE {$where} AND video_ulid != ''
             GROUP BY video
             ORDER BY bytes DESC
             LIMIT {limit:UInt8}",
            $params
        );

        return $result->rows();
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = 5, ?string $video = null, ?string $tid = null, ?string $metric = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $tid, $params, $metric);

        // The top-N stays a subquery rather than a round-trip: video_ulid is written from the edge
        // logs unvalidated, so feeding those values back into a second statement would mean
        // interpolating attacker-controlled text into SQL.
        $result = $this->client->select(
            "SELECT
                date,
                video_ulid AS video,
                sum(value) AS bytes
             FROM usage
             WHERE {$where}
               AND video_ulid IN (
                   SELECT video_ulid
                   FROM usage
                   WHERE {$where} AND video_ulid != ''
                   GROUP BY video_ulid
                   ORDER BY sum(value) DESC
                   LIMIT {limit:UInt8}
               )
             GROUP BY date, video
             ORDER BY date, video",
            $params
        );

        return $result->rows();
    }

    /**
     * Per proxy node: bytes served, bytes fetched from S3 and the cache hit ratio. The three
     * numbers that say whether a node needs more disk (ratio falling with a full pool) or the
     * fleet needs another node (ratio fine, egress at the node's limit).
     *
     * The ratio counts only the lookups the cache was asked about: `BYPASS` (manifests), `OFF`
     * (downloads) and '' (an edge from before the field) are left out rather than counted as
     * misses, or a node serving many downloads would look like it had no cache at all. Origin
     * bytes live under account 0 and their own metric ({@see IngestBandwidthJob}), so
     * the join is on the node alone. Node 0 — rows that predate the dimension — is dropped.
     *
     * @return array<int, array{node_id: int, delivered_bytes: float, origin_bytes: float, hit_ratio: float|null}>
     */
    public function edgeDelivery(string $from, string $to): array
    {
        $result = $this->client->select(
            'SELECT
                node_id,
                sumIf(value, metric IN ('.self::BANDWIDTH_METRICS.')) AS delivered_bytes,
                sumIf(value, metric = {origin:String}) AS origin_bytes,
                sumIf(value, metric IN ('.self::BANDWIDTH_METRICS.") AND cache = 'HIT') AS hit_bytes,
                sumIf(value, metric IN (".self::BANDWIDTH_METRICS.") AND cache NOT IN ('', 'BYPASS', 'OFF')) AS cached_bytes
             FROM usage
             WHERE date >= {from:Date} AND date <= {to:Date} AND node_id > 0
             GROUP BY node_id
             ORDER BY node_id",
            ['from' => $from, 'to' => $to, 'origin' => IngestBandwidthJob::ORIGIN_METRIC]
        );

        $edges = [];
        foreach ($result->rows() as $row) {
            $cached = (float) $row['cached_bytes'];
            $edges[] = [
                'node_id' => (int) $row['node_id'],
                'delivered_bytes' => (float) $row['delivered_bytes'],
                'origin_bytes' => (float) $row['origin_bytes'],
                'hit_ratio' => $cached > 0 ? round((float) $row['hit_bytes'] / $cached, 4) : null,
            ];
        }

        return $edges;
    }

    public function encodingUsage(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                replaceOne(metric, 'encoding_', '') AS device,
                sum(value) AS total_seconds
            FROM usage
            WHERE metric = 'encoding_cpu'
              AND date >= {from:Date} AND date <= {to:Date}
            GROUP BY metric
        SQL, ['from' => $from, 'to' => $to]);

        $usage = ['cpu' => 0];

        foreach ($result->rows() as $row) {
            $usage[$row['device']] = round($row['total_seconds'], 2);
        }

        return $usage;
    }

    public function usageSummary(string $from, string $to, ?int $userId = null): array
    {
        $where = 'date >= {from:Date} AND date <= {to:Date}';
        $params = ['from' => $from, 'to' => $to];

        if ($userId) {
            $where .= ' AND user_id = {user_id:UInt32}';
            $params['user_id'] = $userId;
        }

        $result = $this->client->select(
            "SELECT metric, sum(value) AS total FROM usage WHERE {$where} GROUP BY metric",
            $params
        );

        $usage = ['upload_bytes' => 0, 'encoding_cpu' => 0];

        foreach ($result->rows() as $row) {
            if (isset($usage[$row['metric']])) {
                $usage[$row['metric']] = round($row['total'], 2);
            }
        }

        return $usage;
    }

    public function topExternalUsers(string $from, string $to, ?int $userId = null, int $limit = 10): array
    {
        $where = "metric = 'upload_bytes' AND date >= {from:Date} AND date <= {to:Date} AND external_user_id != ''";
        $params = ['from' => $from, 'to' => $to, 'limit' => $limit];

        if ($userId) {
            $where .= ' AND user_id = {user_id:UInt32}';
            $params['user_id'] = $userId;
        }

        $result = $this->client->select(
            "SELECT external_user_id, sum(value) AS bytes
             FROM usage
             WHERE {$where}
             GROUP BY external_user_id
             ORDER BY bytes DESC
             LIMIT {limit:UInt8}",
            $params
        );

        return $result->rows();
    }

    public function encodingUsageOverTime(string $from, string $to): array
    {
        $result = $this->client->select(<<<'SQL'
            SELECT
                date,
                replaceOne(metric, 'encoding_', '') AS device,
                sum(value) AS seconds
            FROM usage
            WHERE metric = 'encoding_cpu'
              AND date >= {from:Date} AND date <= {to:Date}
            GROUP BY date, metric
            ORDER BY date
        SQL, ['from' => $from, 'to' => $to]);

        return $result->rows();
    }
}
