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
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every read over `usage_daily`. Written against the query builder and nothing database-specific:
 * the table lives in MariaDB and the suite runs it on SQLite, so a function only one of them has
 * is a query only one of them can test.
 */
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

    private static function usage(string $from, string $to): Builder
    {
        return DB::table(UsageService::TABLE)->whereBetween('date', [$from, $to]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return $query->get()->map(fn (object $row) => (array) $row)->all();
    }

    /**
     * The predicate shared by every bandwidth query. The metric constraint is not optional: without
     * it these queries would read upload volume and encoding seconds as if they were delivered
     * bytes.
     *
     * The filters are optional and every one is bound — `video_ulid` and `tracking_id` are written
     * from CDN access logs, so their values are caller-controlled text that must never reach the
     * statement itself.
     */
    private function bandwidth(string $from, string $to, ?string $video, ?string $trackingId, ?string $metric = null, ?int $projectId = null): Builder
    {
        $query = self::usage($from, $to)->whereIn('metric', UsageMetric::delivery());

        // The only clause that can narrow this table to one tenant. Everything else here is a
        // filter a caller chooses; this one is imposed on it.
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        // Narrowing to a single delivery metric — streaming alone, downloads alone. Still inside
        // the constant set above, so an unknown metric simply matches nothing rather than reaching
        // across into upload or encoding rows.
        if ($metric !== null && $metric !== '') {
            $query->where('metric', $metric);
        }

        if ($video !== null && $video !== '') {
            $query->where('video_ulid', $video);
        }

        // An empty string is a legitimate value here — it is the column default and means "traffic
        // that carried no tracking id" — so the filter is applied on `!== null`, not on emptiness.
        // Asking for it is how a caller isolates its unattributed traffic.
        if ($trackingId !== null) {
            $query->where('tracking_id', $trackingId);
        }

        return $query;
    }

    public function summary(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $row = $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
            ->selectRaw('sum(value) as total_bytes')
            ->selectRaw('count(distinct video_ulid) as unique_videos')
            ->selectRaw('count(distinct ip) as unique_ips')
            ->selectRaw('count(distinct tracking_id) as unique_tracking_ids')
            ->first();

        return [
            'total_bytes' => (int) ($row->total_bytes ?? 0),
            'unique_videos' => (int) ($row->unique_videos ?? 0),
            'unique_ips' => (int) ($row->unique_ips ?? 0),
            'unique_tracking_ids' => (int) ($row->unique_tracking_ids ?? 0),
        ];
    }

    public function bandwidthOverTime(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        return self::rows(
            $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
                ->select('date')
                ->selectRaw('sum(value) as bytes')
                ->selectRaw('count(distinct ip) as sessions')
                ->groupBy('date')
                ->orderBy('date')
        );
    }

    public function topIps(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        return self::rows(
            $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
                ->select('ip')
                ->selectRaw('sum(value) as bytes')
                ->selectRaw('count(distinct video_ulid) as sessions')
                ->groupBy('ip')
                ->orderByDesc('bytes')
                ->limit($limit)
        );
    }

    /**
     * Bandwidth broken down by the caller-supplied tracking id a download link carried
     * ({@see DownloadStreamData}). This is the read side of that feature: without it the id is
     * stored and never surfaces anywhere.
     *
     * Traffic that carried no id is reported under an empty tracking id rather than dropped — it
     * is real bandwidth, and hiding it would make the breakdown fail to add up to the total.
     */
    public function bandwidthByTrackingId(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        return self::rows(
            $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
                ->select('tracking_id')
                ->selectRaw('sum(value) as bytes')
                ->selectRaw('count(distinct video_ulid) as videos')
                ->selectRaw('count(distinct ip) as unique_ips')
                ->groupBy('tracking_id')
                ->orderByDesc('bytes')
                ->limit($limit)
        );
    }

    /**
     * The general read over `usage_daily`: sum `value` grouped by the dimensions the caller named.
     *
     * Every named endpoint on this service answers one question that was worth naming. This answers
     * the ones that were not — and the ones nobody has asked yet, which for an API that other
     * projects embed is most of them. What keeps it from being an open query surface is that the
     * caller picks {@see MetricDimension} cases and value lists, never column names or SQL: the
     * dimensions become a class-controlled column each, and every value the caller supplies is
     * bound.
     *
     * Authorization is NOT here. Which dimensions a caller may name, and whether the query is
     * pinned to its account or its project, is decided before the call ({@see MetricsController})
     * and arrives as `$accountId` and already-narrowed lists. A service that also policed this
     * would be the second place to get it wrong.
     *
     * The metric constraint is the one thing that is never optional. `value` is a shared double
     * whose unit lives in the metric name, so an unconstrained sum adds encoding seconds to bytes;
     * with no `$metrics` the default is the delivery set, exactly like every other read here.
     *
     * @param  list<MetricDimension>  $dimensions
     * @param  array<string, list<string>>  $filters  keyed by `usage_daily` column
     * @param  list<string>  $metrics  empty for the delivery set
     * @return array<int, array<string, mixed>>
     */
    public function query(string $from, string $to, array $dimensions, array $filters = [], array $metrics = [], ?int $accountId = null, ?int $projectId = null): array
    {
        $query = self::usage($from, $to)
            ->whereIn('metric', $metrics === [] ? UsageMetric::delivery() : array_values($metrics));

        // Pinned to one account when the caller is not entitled to the instance. The column is the
        // owning user resolved from the video at ingest, so it is the same boundary `/api/usage`
        // enforces and it cannot be talked out of by anything in the URL.
        if ($accountId !== null) {
            $query->where('user_id', $accountId);
        }

        // Narrower than the account, and the reason the identifier dimensions can be offered to a
        // tenant at all: an account holds many projects, and `project_id` is what tells them apart.
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        // The column names are keys this class recognises, not caller text; an unknown one is
        // ignored rather than passed on.
        foreach (['video_ulid', 'tracking_id', 'external_user_id'] as $column) {
            $values = array_values(array_unique($filters[$column] ?? []));

            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        foreach ($dimensions as $dimension) {
            $query->addSelect($dimension->selection())
                ->groupBy($dimension->alias())
                ->orderBy($dimension->alias());
        }

        // One row past the cap, so an oversized result can be refused rather than silently
        // truncated ({@see MetricsQueryData::MAX_ROWS}).
        $rows = self::rows($query->selectRaw('sum(value) as value')->limit(MetricsQueryData::MAX_ROWS + 1));

        // Refused here rather than in each controller: every read that goes through this method is
        // capped by the same number, including the batch endpoints, whose daily granularity can
        // otherwise multiply a thousand values by the length of a year.
        if (count($rows) > MetricsQueryData::MAX_ROWS) {
            throw ValidationException::withMessages([
                'dimensions' => 'This query returns more than '.MetricsQueryData::MAX_ROWS
                    .' rows. Narrow the date range, drop a dimension, or filter the lists.',
            ]);
        }

        return $rows;
    }

    /**
     * Delivered bytes for a batch of tracking ids, split by delivery metric — the ids an integrator
     * minted its playback and download links with ({@see DownloadStreamData}), which is how it
     * meters a per-subscriber bandwidth quota.
     *
     * This is the billing read, and it exists in the shape it does because the dashboard queries
     * answer the wrong question for an invoice. {@see bandwidthByTrackingId()} and
     * {@see topVideos()} answer "who used the most", which is a top-N; billing needs the totals for
     * the values the CALLER names, all of them, whether or not they are in anyone's top N. Doing
     * that through {@see summary()} means one HTTP call and one full `AnalyticsData` per value per
     * period, to read a single number out of each.
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
     * Bounded by construction at `count($values) × count(UsageMetric::delivery())`, times the range
     * when `$daily`, and the request caps the list ({@see TrackingIdBytesQueryData}).
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
     * That is what `project_id` bought — `video_ulid` here is a string parsed from a public request
     * path and says nothing about who owns it ({@see AnalyticsController::videos()}).
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
     * Built on {@see query()} rather than beside it: two query builders over one table is how the
     * metric constraint ends up on only one of them. What is left here is what is genuinely
     * theirs: the empty short-circuit, and `value` renamed to `bytes`, which is what their
     * published response calls it and what a delivery-only read can honestly call it.
     *
     * @param  string  $column  the `usage_daily` column the values are matched against. A
     *                          class-controlled name, never caller input — the caller chooses the
     *                          endpoint, not the column.
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
        // `sessions` and `unique_ips` are the same count under the names the response has always
        // carried. Counted once: a distinct count is the expensive part of this query, and asking
        // for the same one twice made the database do it twice.
        $rows = self::rows(
            $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
                ->where('video_ulid', '!=', '')
                ->select('video_ulid as video')
                ->selectRaw("'' as external_resource_id")
                ->selectRaw('sum(value) as bytes')
                ->selectRaw('count(distinct ip) as sessions')
                ->groupBy('video_ulid')
                ->orderByDesc('bytes')
                ->limit($limit)
        );

        return array_map(fn (array $row) => $row + ['unique_ips' => $row['sessions']], $rows);
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = self::SERIES_TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        // Joined rather than `IN (subquery)`: MariaDB refuses a LIMIT inside one. The column takes
        // an alias of its own so the outer filters, which name `video_ulid` bare, stay unambiguous.
        $top = $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
            ->where('video_ulid', '!=', '')
            ->select('video_ulid as top_video')
            ->groupBy('video_ulid')
            ->orderByRaw('sum(value) desc')
            ->limit($limit);

        return self::rows(
            $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId)
                ->joinSub($top, 'top', 'top.top_video', '=', UsageService::TABLE.'.video_ulid')
                ->select('date', 'video_ulid as video')
                ->selectRaw('sum(value) as bytes')
                ->groupBy('date', 'video_ulid')
                ->orderBy('date')
                ->orderBy('video_ulid')
        );
    }

    /**
     * Per proxy node: bytes served, bytes fetched from S3 and the cache hit ratio. The three
     * numbers that say whether a node needs more disk (ratio falling with a full pool) or the
     * fleet needs another node (ratio fine, egress at the node's limit).
     *
     * The ratio counts only the lookups the cache was asked about: `BYPASS` (manifests, on an
     * edge from before they were cached), `OFF` (downloads) and '' (an edge from before the
     * field) are left out rather than counted as misses, or a node serving many downloads would
     * look like it had no cache at all. `REVALIDATED` counts as a hit: the bytes came off the
     * pool, and S3 only answered a 304 — which is every manifest each 30 seconds. Origin
     * bytes live under account 0 and their own metric ({@see IngestBandwidthJob}), so
     * the grouping is on the node alone. Node 0 — rows that predate the dimension — is dropped.
     *
     * @return array<int, array{node_id: int, delivered_bytes: float, origin_bytes: float, hit_ratio: float|null}>
     */
    public function edgeDelivery(string $from, string $to): array
    {
        $delivery = UsageMetric::delivery();
        $in = implode(', ', array_fill(0, count($delivery), '?'));

        $rows = self::usage($from, $to)
            ->where('node_id', '>', 0)
            ->select('node_id')
            ->selectRaw("sum(case when metric in ({$in}) then value else 0 end) as delivered_bytes", $delivery)
            ->selectRaw('sum(case when metric = ? then value else 0 end) as origin_bytes', [IngestBandwidthJob::ORIGIN_METRIC])
            ->selectRaw("sum(case when metric in ({$in}) and cache in ('HIT', 'REVALIDATED') then value else 0 end) as hit_bytes", $delivery)
            ->selectRaw("sum(case when metric in ({$in}) and cache not in ('', 'BYPASS', 'OFF') then value else 0 end) as cached_bytes", $delivery)
            ->groupBy('node_id')
            ->orderBy('node_id')
            ->get();

        $edges = [];
        foreach ($rows as $row) {
            $cached = (float) $row->cached_bytes;
            $edges[] = [
                'node_id' => (int) $row->node_id,
                'delivered_bytes' => (float) $row->delivered_bytes,
                'origin_bytes' => (float) $row->origin_bytes,
                'hit_ratio' => $cached > 0 ? round((float) $row->hit_bytes / $cached, 4) : null,
            ];
        }

        return $edges;
    }

    public function encodingUsage(string $from, string $to): array
    {
        $total = self::usage($from, $to)->where('metric', UsageMetric::ENCODING_CPU->value)->sum('value');

        return ['cpu' => round((float) $total, 2)];
    }

    public function usageSummary(string $from, string $to, ?int $projectId = null): array
    {
        $usage = ['upload_bytes' => 0, 'encoding_cpu' => 0];

        // Named rather than filtered afterwards: the two metrics are a handful of rows against the
        // range's millions of delivery rows, and only the metric-led index can skip those.
        $query = self::usage($from, $to)
            ->whereIn('metric', array_keys($usage))
            ->select('metric')->selectRaw('sum(value) as total')->groupBy('metric');

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        foreach ($query->get() as $row) {
            if (isset($usage[$row->metric])) {
                $usage[$row->metric] = round((float) $row->total, 2);
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
        $query = self::usage($from, $to)
            ->where('metric', UsageMetric::UPLOAD_BYTES->value)
            ->where('external_user_id', '!=', '')
            ->select('external_user_id')
            ->selectRaw('sum(value) as bytes')
            ->groupBy('external_user_id')
            ->orderByDesc('bytes')
            ->limit($limit);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return self::rows($query);
    }

    public function encodingUsageOverTime(string $from, string $to): array
    {
        return self::rows(
            self::usage($from, $to)
                ->where('metric', UsageMetric::ENCODING_CPU->value)
                ->select('date')
                ->selectRaw("'cpu' as device")
                ->selectRaw('sum(value) as seconds')
                ->groupBy('date')
                ->orderBy('date')
        );
    }

    /**
     * One account's own usage, a row per metric, customer label and day — what `/api/usage`
     * answers with. `$accountId` is the boundary and is never optional: the column is the owning
     * user, written at ingest from the video, so nothing in the request can widen it.
     *
     * @return array<int, array{metric: string, external_user_id: string, value: float, date: string}>
     */
    public function accountUsage(int $accountId, string $from, string $to, ?string $metric = null, ?string $externalUserId = null): array
    {
        $query = self::usage($from, $to)
            ->where('user_id', $accountId)
            ->select('metric', 'external_user_id')
            ->selectRaw('sum(value) as value')
            ->addSelect('date')
            ->groupBy('metric', 'external_user_id', 'date')
            ->orderBy('date');

        if ($metric !== null && $metric !== '') {
            $query->where('metric', $metric);
        }

        if ($externalUserId !== null && $externalUserId !== '') {
            $query->where('external_user_id', $externalUserId);
        }

        return self::rows($query);
    }
}
