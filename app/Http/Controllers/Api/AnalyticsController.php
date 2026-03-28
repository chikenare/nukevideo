<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        return response()->json([
            'data' => [
                'node_count' => Node::count(),
                'summary' => $this->analyticsService->summary($from, $to),
                'bandwidth_over_time' => $this->analyticsService->bandwidthOverTime($from, $to),
                'top_ips' => $this->analyticsService->topIps($from, $to),
                'top_videos' => $this->analyticsService->topVideos($from, $to),
                'bandwidth_by_video' => $this->analyticsService->bandwidthByVideo($from, $to),
            ],
        ]);
    }
}
