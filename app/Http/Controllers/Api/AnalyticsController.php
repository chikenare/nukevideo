<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\AnalyticsCardData;
use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\BandwidthByVideoData;
use App\Data\Analytics\BandwidthPointData;
use App\Data\Analytics\EncodingPointData;
use App\Data\Analytics\TopExternalUserData;
use App\Data\Analytics\TopIpData;
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
        ]);

        $from = $request->input('from');
        $to = $request->input('to');
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

        $encoding = $this->analyticsService->encodingUsage($from, $to);
        $summary = $this->analyticsService->summary($from, $to);
        $usage = $this->analyticsService->usageSummary($from, $to, $userId);

        return response()->json([
            'data' => new AnalyticsData(
                cards: AnalyticsCardData::collect([
                    ['label' => 'Total Bandwidth', 'value' => $summary['total_bytes'], 'format' => 'bytes'],
                    ['label' => 'Unique IPs', 'value' => $summary['unique_ips'], 'format' => 'number'],
                    ['label' => 'Active Videos', 'value' => $summary['unique_videos'], 'format' => 'number'],
                    ['label' => 'Nodes', 'value' => Node::count(), 'format' => 'number'],
                    ['label' => 'CPU Encoding', 'value' => $encoding['cpu'], 'format' => 'seconds'],
                    ['label' => 'Upload Volume', 'value' => $usage['upload_bytes'], 'format' => 'bytes'],
                ]),
                bandwidthOverTime: BandwidthPointData::collect($this->analyticsService->bandwidthOverTime($from, $to)),
                topIps: TopIpData::collect($this->analyticsService->topIps($from, $to)),
                topVideos: TopVideoData::collect($this->analyticsService->topVideos($from, $to)),
                topExternalUsers: TopExternalUserData::collect($this->analyticsService->topExternalUsers($from, $to, $userId)),
                bandwidthByVideo: BandwidthByVideoData::collect($this->analyticsService->bandwidthByVideo($from, $to)),
                encodingOverTime: EncodingPointData::collect($this->analyticsService->encodingUsageOverTime($from, $to)),
            ),
        ]);
    }
}
