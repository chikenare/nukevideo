<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\AnalyticsCardData;
use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\BandwidthByVideoData;
use App\Data\Analytics\BandwidthPointData;
use App\Data\Analytics\EncodingPointData;
use App\Data\Analytics\TopExternalUserData;
use App\Data\Analytics\TopIpData;
use App\Data\Analytics\TopTrackingIdData;
use App\Data\Analytics\TopVideoData;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Video;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            'user_id' => 'nullable|integer|exists:users,id',
            // Narrow the bandwidth series to one video and/or one tracking id. Both are matched
            // against columns written from CDN access logs, so they are bound parameters in the
            // service, never interpolated; the shapes below are what those columns can hold.
            'video' => 'nullable|string|size:26|regex:/^[0-9A-HJKMNP-TV-Z]{26}$/',
            'tid' => 'nullable|string|max:64|regex:/^[A-Za-z0-9_-]*$/',
            // Which kind of delivery to report. Anything outside this list is refused rather than
            // passed through: `usage` also holds upload volume and encoding seconds in the same
            // `value` column, and letting one of those names reach the bandwidth queries would
            // report seconds as if they were bytes.
            'metric' => 'nullable|string|in:streaming_bytes,download_bytes,asset_bytes,bandwidth_bytes',
        ]);

        $from = $request->input('from');
        $to = $request->input('to');
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        $video = $request->input('video');

        // `has`, not `filled`: an empty `tid` is the value traffic with no tracking id carries, so
        // `?tid=` is a meaningful request — "show me only what was never attributed" — and must not
        // be flattened into "no filter at all".
        $tid = $request->has('tid') ? (string) $request->input('tid', '') : null;
        $metric = $request->input('metric');

        $encoding = $this->analyticsService->encodingUsage($from, $to);
        $summary = $this->analyticsService->summary($from, $to, $video, $tid, $metric);
        $usage = $this->analyticsService->usageSummary($from, $to, $userId);

        return response()->json([
            'data' => new AnalyticsData(
                cards: AnalyticsCardData::collect([
                    ['label' => 'Total Bandwidth', 'value' => $summary['total_bytes'], 'format' => 'bytes'],
                    ['label' => 'Unique IPs', 'value' => $summary['unique_ips'], 'format' => 'number'],
                    ['label' => 'Active Videos', 'value' => $summary['unique_videos'], 'format' => 'number'],
                    ['label' => 'Tracking IDs', 'value' => $summary['unique_tracking_ids'], 'format' => 'number'],
                    ['label' => 'Nodes', 'value' => Node::count(), 'format' => 'number'],
                    ['label' => 'CPU Encoding', 'value' => $encoding['cpu'], 'format' => 'seconds'],
                    ['label' => 'Upload Volume', 'value' => $usage['upload_bytes'], 'format' => 'bytes'],
                ]),
                bandwidthOverTime: BandwidthPointData::collect($this->analyticsService->bandwidthOverTime($from, $to, $video, $tid, $metric)),
                topIps: TopIpData::collect($this->analyticsService->topIps($from, $to, video: $video, tid: $tid, metric: $metric)),
                topVideos: TopVideoData::collect($this->analyticsService->topVideos($from, $to, video: $video, tid: $tid, metric: $metric)),
                topExternalUsers: TopExternalUserData::collect($this->analyticsService->topExternalUsers($from, $to, $userId)),
                topTrackingIds: TopTrackingIdData::collect($this->analyticsService->bandwidthByTrackingId($from, $to, video: $video, tid: $tid, metric: $metric)),
                bandwidthByVideo: BandwidthByVideoData::collect($this->analyticsService->bandwidthByVideo($from, $to, video: $video, tid: $tid, metric: $metric)),
                encodingOverTime: EncodingPointData::collect($this->analyticsService->encodingUsageOverTime($from, $to)),
            ),
        ]);
    }
}
