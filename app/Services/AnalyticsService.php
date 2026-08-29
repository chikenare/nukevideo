<?php

namespace App\Services;

use App\Data\Analytics\MetricsQueryData;
use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Data\Stream\DownloadStreamData;
use App\Enums\MetricDimension;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MetricsController;
use App\Jobs\IngestBandwidthJob;
use ClickHouseDB\Client;
use ClickHouseDB\Query\Degeneration\Bindings;
use Illuminate\Validation\ValidationException;

class AnalyticsService
{
    /**
     * The top-N size every flat breakdown falls back to. Public and named because those sizes are
     * request parameters now, so the controller and the queries would otherwise each carry their
     * own copy of the same 10 and drift.
     */
    public const TOP_N_DEFAULT = 10;

    /**
     * The default for {@see bandwidthByVideo()}, smaller for a reason: that one is a time series,
     * so its row count is the limit TIMES the length of the range, where the flat breakdowns
     * return the limit itself.
     */
    public const SERIES_TOP_N_DEFAULT = 5;

    private Client $client;

    public function __construct()
    {
        $this->client = app(Client::class);
    }

    /**
     * {@see UsageMetric::delivery()} as a quoted SQL list. Interpolated rather than bound: the set
     * comes from an enum, so no caller-supplied text ever reaches it, and `select()` cannot carry a
     * list anyway ({@see batchBytes()}). Every value a caller CAN influence stays a bound
     * parameter.
     */
    private static function bandwidthMetricList(): string
    {
        return "'".implode("', '", UsageMetric::delivery())."'";
    }

    /**
     * The `usage` predicate shared by every bandwidth query, plus the bound parameters it needs.
     * The metric constraint is not optional: without it these queries would read upload volume and
     * encoding seconds as if they were delivered bytes.
     *
     * The filters are optional and every one is bound, never interpolated — `video_ulid` and `tracking_id`
     * are written from CDN access logs, so their values are caller-controlled text that must never
     * reach the statement itself.
     *
     * @param  array<string, mixed>  $params  filled in place with the bindings this clause adds
     * @return string the WHERE body, without the `WHERE` keyword
     */
    private function bandwidthFilter(string $from, string $to, ?string $video, ?string $trackingId, array &$params, ?string $metric = null, ?int $projectId = null): string
    {
        $where = ['date >= {from:Date}', 'date <= {to:Date}', 'metric IN ('.self::bandwidthMetricList().')'];
        $params += ['from' => $from, 'to' => $to];

        // The only clause that can narrow this table to one tenant. Everything else here is a
        // filter a caller chooses; this one is imposed on it.
        if ($projectId !== null) {
            $where[] = 'project_id = {project:UInt32}';
            $params['project'] = $projectId;
        }

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
        if ($trackingId !== null) {
            $where[] = 'tracking_id = {tracking_id:String}';
            $params['tracking_id'] = $trackingId;
        }

        return implode(' AND ', $where);
    }

    public function summary(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = [];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

        $result = $this->client->select(
            "SELECT
                sum(value) AS total_bytes,
                uniqExact(video_ulid) AS unique_videos,
                uniqExact(ip) AS unique_ips,
                uniqExact(tracking_id) AS unique_tracking_ids
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

    public function bandwidthOverTime(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = [];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

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

    public function topIps(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

        $result = $this->client->select(
            "SELECT
                IPv6NumToString(ip) AS ip,
                sum(value) AS bytes,
                uniqExact(video_ulid) AS sessions
             FROM usage
             WHERE {$where}
             GROUP BY ip
             ORDER BY bytes DESC
             LIMIT {limit:UInt16}",
            $params
        );

        return $result->rows();
    }

    /**
     * Bandwidth broken down by the caller-supplied tracking id a download link carried
     * ({@see DownloadStreamData}). This is the read side of that feature:
     * without it the id is written to ClickHouse and never surfaces anywhere.
     *
     * Traffic that carried no id is reported under an empty tracking id rather than dropped — it
     * is real bandwidth, and hiding it would make the breakdown fail to add up to the total.
     *
     * `$limit` is a request parameter, so it is bound as UInt16 rather than the UInt8 the other
     * top-N queries use: a caller with more than 255 tracking ids was silently reading a top 10
     * and had no way to see it, let alone widen it.
     */
    public function bandwidthByTrackingId(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

        $result = $this->client->select(
            "SELECT
                tracking_id,
                sum(value) AS bytes,
                uniqExact(video_ulid) AS videos,
                uniqExact(ip) AS unique_ips
             FROM usage
             WHERE {$where}
             GROUP BY tracking_id
             ORDER BY bytes DESC
             LIMIT {limit:UInt16}",
            $params
        );

        return $result->rows();
    }

    /**
     * The general read over `usage`: sum `value` grouped by the dimensions the caller named.
     *
     * Every named endpoint on this service answers one question that was worth naming. This answers
     * the ones that were not — and the ones nobody has asked yet, which for an API that other
     * projects embed is most of them. What keeps it from being an open query surface is that the
     * caller picks {@see MetricDimension} cases and value lists, never column names or SQL: the
     * dimensions become a class-controlled expression each, and every value the caller supplies is
     * a bound `Array(String)` parameter.
     *
     * Authorization is NOT here. Which dimensions a caller may name, and whether the query is
     * pinned to its account or its project, is decided before the call ({@see MetricsController})
     * and arrives as `$accountId` and already-narrowed lists. A service that also policed this
     * would be the second place to get it wrong.
     *
     * The metric constraint is the one thing that is never optional. `value` is a shared Float64
     * whose unit lives in the metric name, so an unconstrained sum adds encoding seconds to bytes;
     * with no `$metrics` the default is the delivery set, exactly like every other read here.
     *
     * @param  list<MetricDimension>  $dimensions
     * @param  array<string, list<string>>  $filters  keyed by `usage` column, values bound as arrays
     * @param  list<string>  $metrics  empty for the delivery set
     * @return array<int, array<string, mixed>>
     */
    public function query(string $from, string $to, array $dimensions, array $filters = [], array $metrics = [], ?int $accountId = null, ?int $projectId = null): array
    {
        $params = ['from' => $from, 'to' => $to];
        $where = ['date >= {from:Date}', 'date <= {to:Date}'];

        if ($metrics === []) {
            $where[] = 'metric IN ('.self::bandwidthMetricList().')';
        } else {
            $where[] = 'metric IN {metrics:Array(String)}';
            $params['metrics'] = array_values($metrics);
        }

        // Pinned to one account when the caller is not entitled to the instance. The column is the
        // owning user resolved from the video at ingest, so it is the same boundary `/api/usage`
        // enforces and it cannot be talked out of by anything in the URL.
        if ($accountId !== null) {
            $where[] = 'user_id = {account:UInt32}';
            $params['account'] = $accountId;
        }

        // Narrower than the account, and the reason the identifier dimensions can be offered to a
        // tenant at all: an account holds many projects, and `project_id` is what tells them apart.
        if ($projectId !== null) {
            $where[] = 'project_id = {project:UInt32}';
            $params['project'] = $projectId;
        }

        // One bound array per filter. The column names are keys this class recognises, not caller
        // text; an unknown one is ignored rather than concatenated.
        foreach (['video_ulid', 'tracking_id', 'external_user_id'] as $column) {
            $values = array_values(array_unique($filters[$column] ?? []));

            if ($values !== []) {
                // Named after the column it filters, so the statement stays readable in a slow
                // query log. The prefix keeps it clear of ClickHouse's own parameter namespace.
                $where[] = "{$column} IN {in_{$column}:Array(String)}";
                $params["in_{$column}"] = $values;
            }
        }

        $selection = implode(', ', array_map(fn (MetricDimension $d) => $d->selection(), $dimensions));
        $grouping = implode(', ', array_map(fn (MetricDimension $d) => $d->alias(), $dimensions));
        $predicate = implode(' AND ', $where);

        // One row past the cap, so an oversized result can be refused rather than silently
        // truncated ({@see MetricsQueryData::MAX_ROWS}).
        $limit = MetricsQueryData::MAX_ROWS + 1;

        $result = $this->client->selectWithParams(
            "SELECT {$selection}, sum(value) AS value
             FROM usage
             WHERE {$predicate}
             GROUP BY {$grouping}
             ORDER BY {$grouping}
             LIMIT {$limit}",
            $params
        );

        $rows = $result->rows();

        // Refused here rather than in each controller: every read that reaches ClickHouse through
        // this method is capped by the same number, including the batch endpoints, whose daily
        // granularity can otherwise multiply a thousand values by the length of a year.
        if (count($rows) > MetricsQueryData::MAX_ROWS) {
            throw ValidationException::withMessages([
                'dimensions' => 'This query returns more than '.MetricsQueryData::MAX_ROWS
                    .' rows. Narrow the date range, drop a dimension, or filter the lists.',
            ]);
        }

        return $rows;
    }

    /**
     * Delivered bytes for a batch of values on one dimension of `usage`, split by delivery metric.
     *
     * This is the billing read, and it exists in the shape it does because the dashboard queries
     * answer the wrong question for an invoice. {@see bandwidthByTrackingId()} and
     * {@see topVideos()} answer "who used the most", which is a top-N; billing needs the totals for
     * the values the CALLER names, all of them, whether or not they are in anyone's top N. Doing
     * that through {@see summary()} means one HTTP call and one full `AnalyticsData` per value per
     * period, to read a single number out of each.
     *
     * It is also the cheaper shape for ClickHouse. Neither `tracking_id` nor `video_ulid` is near
     * the front of the sorting key, so a filter on one alone is never a prefix scan — it is
     * partition pruning and then a scan. N single-value queries re-read the same partitions N times
     * over, where this reads them once.
     *
     * Split by metric rather than pre-summed, because streaming and the downloads that reupload to
     * a viewer's own file host are not the same line on an invoice; a caller that wants one number
     * adds the rows up, and one that pre-summed could never take them apart again. Values with no
     * traffic in the range produce no row: absence is the answer, and padding a thousand-value
     * request with zeros would make most of the response filler.
     *
     * `$daily` adds the date to the grouping, for a period a subscriber joined or left halfway
     * through. Opt-in, because it multiplies the response by the length of the range.
     *
     * `selectWithParams()`, not `select()`: the latter routes bindings through {@see Bindings},
     * whose URL params go through `http_build_query`, so a list arrives as
     * `param_batch[0]=…` and ClickHouse never sees the parameter at all. This one converts the
     * list into an `Array(String)` literal for server-side substitution, which is what keeps caller
     * text out of the statement — these values arrive over HTTP like anything else.
     *
     * No LIMIT, deliberately: the result is bounded by construction at
     * `count($values) × count(UsageMetric::delivery())`, times the range when `$daily`, and the
     * request caps the list ({@see TrackingIdBytesQueryData}). A LIMIT here could only truncate an
     * answer the caller asked for in full, which for billing is worse than a large response.
     *
     * @param  string  $column  the dimension to group on. A class-controlled column name, never
     *                          caller input — the caller chooses the endpoint, not the column.
     * @param  list<string>  $values
     * @return array<int, array{metric: string, bytes: float, date?: string}>
     */
    /**
     * Delivered bytes for a batch of tracking ids — the ids an integrator minted its playback and
     * download links with ({@see DownloadStreamData}), which is how it meters a per-subscriber
     * bandwidth quota.
     *
     * Instance-wide unless a project is passed, and the endpoint above it deliberately does not
     * pass one: traffic whose video has been deleted keeps its tracking id but loses its project,
     * and dropping it would under-report what a subscriber actually consumed. Passing
     * `''` among them asks for the traffic whose id did not survive the round trip through the CDN
     * log, which is how a caller reconciles its own ids against the total.
     *
     * @param  list<string>  $trackingIds
     * @return array<int, array{tracking_id: string, metric: string, bytes: float, date?: string}>
     */
    public function bytesByTrackingIds(string $from, string $to, array $trackingIds, ?string $metric = null, bool $daily = false, ?int $projectId = null): array
    {
        return $this->batch(MetricDimension::TRACKING_ID, 'tracking_id', $from, $to, $trackingIds, $metric, $daily, $projectId);
    }

    /**
     * Delivered bytes for a batch of videos, by ULID — the per-title reporting read.
     *
     * Pass `$projectId` and the list needs no vetting: a ULID from another tenant matches no row.
     * That is what `project_id` bought — this used to require resolving the caller's own ULIDs out
     * of MariaDB first, because `video_ulid` here is a string parsed from a public request path and
     * says nothing about who owns it ({@see AnalyticsController::videos()}).
     *
     * @param  list<string>  $videoUlids
     * @return array<int, array{video: string, metric: string, bytes: float, date?: string}>
     */
    public function bytesByVideos(string $from, string $to, array $videoUlids, ?string $metric = null, bool $daily = false, ?int $projectId = null): array
    {
        // The dimension aliases itself to `video`, the name the other video breakdowns already
        // answer with ({@see topVideos()}), so one payload does not spell it two ways.
        return $this->batch(MetricDimension::VIDEO, 'video_ulid', $from, $to, $videoUlids, $metric, $daily, $projectId);
    }

    /**
     * The shared body of the two batch reads: one dimension, its own values, split by metric.
     *
     * Built on {@see query()} rather than beside it. These endpoints predate the general one and
     * had their own copy of the same SELECT, WHERE and GROUP BY — two query builders over one table
     * is how the metric constraint ends up on only one of them. What is left here is what is
     * genuinely theirs: the empty short-circuit, and `value` renamed to `bytes`, which is what their
     * published response calls it and what a delivery-only read can honestly call it.
     *
     * @param  string  $column  the `usage` column the values are matched against
     * @param  list<string>  $values
     * @return array<int, array<string, mixed>>
     */
    private function batch(MetricDimension $dimension, string $column, string $from, string $to, array $values, ?string $metric, bool $daily, ?int $projectId = null): array
    {
        if ($values === []) {
            return [];
        }

        $dimensions = $daily
            ? [$dimension, MetricDimension::METRIC, MetricDimension::DATE]
            : [$dimension, MetricDimension::METRIC];

        $rows = $this->query(
            $from,
            $to,
            $dimensions,
            [$column => $values],
            $metric === null || $metric === '' ? [] : [$metric],
            projectId: $projectId,
        );

        return array_map(function (array $row) {
            $row['bytes'] = $row['value'];
            unset($row['value']);

            return $row;
        }, $rows);
    }

    public function topVideos(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

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
             LIMIT {limit:UInt16}",
            $params
        );

        return $result->rows();
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = self::SERIES_TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $params = ['limit' => $limit];
        $where = $this->bandwidthFilter($from, $to, $video, $trackingId, $params, $metric, $projectId);

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
                   LIMIT {limit:UInt16}
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
                sumIf(value, metric IN ('.self::bandwidthMetricList().')) AS delivered_bytes,
                sumIf(value, metric = {origin:String}) AS origin_bytes,
                sumIf(value, metric IN ('.self::bandwidthMetricList().") AND cache = 'HIT') AS hit_bytes,
                sumIf(value, metric IN (".self::bandwidthMetricList().") AND cache NOT IN ('', 'BYPASS', 'OFF')) AS cached_bytes
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

    public function usageSummary(string $from, string $to, ?int $projectId = null): array
    {
        $where = 'date >= {from:Date} AND date <= {to:Date}';
        $params = ['from' => $from, 'to' => $to];

        if ($projectId !== null) {
            $where .= ' AND project_id = {project:UInt32}';
            $params['project'] = $projectId;
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

    /**
     * The integrator's own customer labels, by upload volume. A list of identifiers, so it is only
     * offered narrowed: unscoped it returns every tenant's customers mixed together.
     */
    public function topExternalUsers(string $from, string $to, ?int $projectId = null, int $limit = self::TOP_N_DEFAULT): array
    {
        $where = "metric = 'upload_bytes' AND date >= {from:Date} AND date <= {to:Date} AND external_user_id != ''";
        $params = ['from' => $from, 'to' => $to, 'limit' => $limit];

        if ($projectId !== null) {
            $where .= ' AND project_id = {project:UInt32}';
            $params['project'] = $projectId;
        }

        $result = $this->client->select(
            "SELECT external_user_id, sum(value) AS bytes
             FROM usage
             WHERE {$where}
             GROUP BY external_user_id
             ORDER BY bytes DESC
             LIMIT {limit:UInt16}",
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
