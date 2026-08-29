<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\AnalyticsCardData;
use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\BandwidthByVideoData;
use App\Data\Analytics\BandwidthPointData;
use App\Data\Analytics\EdgeDeliveryData;
use App\Data\Analytics\EdgeDeliveryQueryData;
use App\Data\Analytics\EncodingPointData;
use App\Data\Analytics\TopExternalUserData;
use App\Data\Analytics\TopIpData;
use App\Data\Analytics\TopTrackingIdData;
use App\Data\Analytics\TopVideoData;
use App\Data\Analytics\TrackingIdBytesData;
use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Data\Analytics\VideoBytesData;
use App\Data\Analytics\VideoBytesQueryData;
use App\Enums\MetricUnit;
use App\Enums\UsageGranularity;
use App\Enums\UsageMetric;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Video;
use App\Services\AnalyticsService;
use App\Support\TrackingId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
    ) {}

    public function queueStatus(): JsonResponse
    {
        $statuses = [
            VideoStatus::PENDING,
            VideoStatus::RUNNING,
            VideoStatus::FAILED,
        ];

        $counts = Video::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $queue = [];
        foreach ($statuses as $status) {
            $queue[$status->value] = $counts[$status->value] ?? 0;
        }

        return response()->json(['data' => $queue]);
    }

    /**
     * Per-node delivery for the nodes page. Admin-only, unlike the rest of this controller: it
     * names the operator's infrastructure and its origin egress, which no project key has any
     * business reading.
     */
    public function edges(EdgeDeliveryQueryData $data): JsonResponse
    {
        return response()->json([
            'data' => EdgeDeliveryData::collect($this->analyticsService->edgeDelivery($data->from, $data->to)),
        ]);
    }

    /**
     * Delivered bytes for a batch of tracking ids, split by delivery metric. The endpoint an
     * integrator bills a per-subscriber bandwidth quota from: `index()` can already narrow to one
     * id, but that is one request and one full `AnalyticsData` per subscriber per period, to read
     * one number out of each.
     *
     * `GET` and `POST` both, for one reason: the input is a list. A thousand ids do not fit in a
     * query string, and a caller batching at that size has nowhere to put them but a body. Nothing
     * is written either way — the action reads.
     *
     * Reachable with a project API key, like the rest of this controller bar `edges()`, and for
     * the same reason: reading these numbers back is what an integrating backend holds a key for.
     * It reads no `$request->user()`, which for a project key is a Project and not a User.
     */
    public function trackingIds(TrackingIdBytesQueryData $data): JsonResponse
    {
        return response()->json([
            'data' => TrackingIdBytesData::collect($this->analyticsService->bytesByTrackingIds(
                $data->from,
                $data->to,
                $data->ids(),
                $data->metric,
                $data->granularity === UsageGranularity::DAILY,
            )),
        ]);
    }

    /**
     * Delivered bytes for a batch of the project's videos — per-title reporting, the same shape as
     * `trackingIds()` on the other dimension.
     *
     * The one batch read scoped to a tenant, and the only one that CAN be: `usage` has no project
     * column, but a video does, so the list is narrowed to the caller's own videos before it ever
     * reaches ClickHouse. That is why this action sits behind `resolve.project` while the rest of
     * the metrics do not.
     *
     * A ULID that belongs to someone else and one that moved no bytes produce the same answer — no
     * row — deliberately: answering differently would turn this into a way to find out which
     * videos exist.
     */
    public function videos(Request $request, VideoBytesQueryData $data): JsonResponse
    {
        $owned = $request->project()->ownedVideoUlids($data->videos);

        return response()->json([
            'data' => VideoBytesData::collect($this->analyticsService->bytesByVideos(
                $data->from,
                $data->to,
                $owned,
                $data->metric,
                $data->granularity === UsageGranularity::DAILY,
            )),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            // Admin only, and enforced below rather than here: `exists:users,id` says the account
            // is real, never that the caller may read it.
            'user_id' => 'nullable|integer|exists:users,id',
            // Narrow the bandwidth series to one video and/or one tracking id. Both are matched
            // against columns written from CDN access logs, so they are bound parameters in the
            // service, never interpolated; the shapes below are what those columns can hold.
            'video' => 'nullable|string|size:26|regex:/^[0-9A-HJKMNP-TV-Z]{26}$/',
            'tracking_id' => TrackingId::rules(),
            // Which kind of delivery to report. Anything outside this list is refused rather than
            // passed through: `usage` also holds upload volume and encoding seconds in the same
            // `value` column, and letting one of those names reach the bandwidth queries would
            // report seconds as if they were bytes.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::delivery())],
            // How many rows each flat top-N list returns — `topIps`, `topVideos`, `topTrackingIds`
            // and `topExternalUsers`. They were fixed at 10 with no way to see or widen it, so an
            // account with more than ten of anything was reading a top 10 that looked like the
            // whole breakdown. Capped at the batch endpoints' list size; past a few hundred rows
            // those are the right endpoints anyway.
            'limit' => 'nullable|integer|min:1|max:'.TrackingIdBytesQueryData::MAX_BATCH,
            // `bandwidthByVideo` gets its own, and a smaller one: it is a time series, so its row
            // count is this times the length of the range.
            'video_series_limit' => 'nullable|integer|min:1|max:100',
        ]);

        $from = $request->input('from');
        $to = $request->input('to');
        // Upload volume and the customer breakdown are keyed by account, and `external_user_id`
        // IS the integrator's own customer label. Reporting either across accounts hands one
        // tenant another's customer identifiers — a different thing entirely from the aggregate
        // bandwidth this endpoint deliberately shares, which names nobody.
        //
        // So a project key or a plain user reads its own account and only its own, whatever it
        // asks for; `user_id` used to be taken at face value from anyone, which made every
        // account's upload figures and customer labels readable by guessing an id. An operator
        // keeps both the instance-wide view (no `user_id`) and the ability to name an account it
        // can already read through `/api/users` anyway.
        $userId = $request->isAdmin()
            ? ($request->input('user_id') ? (int) $request->input('user_id') : null)
            : $request->accountId();
        $video = $request->input('video');

        // `has`, not `filled`: an empty string is the value traffic with no tracking id carries,
        // so `?tracking_id=` is a meaningful request — "show me only what was never attributed" —
        // and must not be flattened into "no filter at all".
        $trackingId = $request->has('tracking_id') ? (string) $request->input('tracking_id', '') : null;
        $metric = $request->input('metric');
        $limit = (int) $request->input('limit', AnalyticsService::TOP_N_DEFAULT);
        // Whether this caller may read the breakdowns that name things. See the collections below.
        $identifiers = $request->isAdmin();
        $seriesLimit = (int) $request->input('video_series_limit', AnalyticsService::SERIES_TOP_N_DEFAULT);

        $encoding = $this->analyticsService->encodingUsage($from, $to);
        $summary = $this->analyticsService->summary($from, $to, $video, $trackingId, $metric);
        $usage = $this->analyticsService->usageSummary($from, $to, $userId);

        return response()->json([
            'data' => new AnalyticsData(
                // Keys, not English. A client maps these to its own words; the API has no opinion
                // about what a card is called or in which language.
                cards: AnalyticsCardData::collect([
                    ['key' => 'total_bandwidth', 'value' => $summary['total_bytes'], 'unit' => MetricUnit::BYTES],
                    ['key' => 'unique_ips', 'value' => $summary['unique_ips'], 'unit' => MetricUnit::COUNT],
                    ['key' => 'active_videos', 'value' => $summary['unique_videos'], 'unit' => MetricUnit::COUNT],
                    ['key' => 'tracking_ids', 'value' => $summary['unique_tracking_ids'], 'unit' => MetricUnit::COUNT],
                    ['key' => 'nodes', 'value' => Node::count(), 'unit' => MetricUnit::COUNT],
                    ['key' => 'cpu_encoding', 'value' => $encoding['cpu'], 'unit' => MetricUnit::SECONDS],
                    ['key' => 'upload_volume', 'value' => $usage['upload_bytes'], 'unit' => MetricUnit::BYTES],
                ]),
                bandwidthOverTime: BandwidthPointData::collect($this->analyticsService->bandwidthOverTime($from, $to, $video, $trackingId, $metric)),
                // Withheld from anyone but the operator. A total is instance-wide and names nobody,
                // which is the trade this endpoint has always documented; a LIST OF IDENTIFIERS is
                // a different thing entirely — these three enumerate other tenants' viewer
                // addresses, viewer labels and video ULIDs, and the limit that caps them is the
                // caller's. A tenant reads the same numbers scoped, through `/api/metrics` and
                // `analytics/videos`, by naming what it owns.
                topIps: TopIpData::collect($identifiers ? $this->analyticsService->topIps($from, $to, limit: $limit, video: $video, trackingId: $trackingId, metric: $metric) : []),
                topVideos: TopVideoData::collect($identifiers ? $this->analyticsService->topVideos($from, $to, limit: $limit, video: $video, trackingId: $trackingId, metric: $metric) : []),
                topExternalUsers: TopExternalUserData::collect($this->analyticsService->topExternalUsers($from, $to, $userId, $limit)),
                topTrackingIds: TopTrackingIdData::collect($identifiers ? $this->analyticsService->bandwidthByTrackingId($from, $to, limit: $limit, video: $video, trackingId: $trackingId, metric: $metric) : []),
                bandwidthByVideo: BandwidthByVideoData::collect($identifiers ? $this->analyticsService->bandwidthByVideo($from, $to, limit: $seriesLimit, video: $video, trackingId: $trackingId, metric: $metric) : []),
                encodingOverTime: EncodingPointData::collect($this->analyticsService->encodingUsageOverTime($from, $to)),
            ),
        ]);
    }
}
