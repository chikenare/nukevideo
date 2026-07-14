<?php

namespace App\Http\Controllers\Api;

use App\Data\ActivityLogData;
use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $project = $request->project();

        $activities = Activity::whereHasMorph(
            'subject',
            [Video::class],
            fn ($query) => $query->where('project_id', $project->id),
        )
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => array_map(fn ($a) => ActivityLogData::fromModel($a), $activities->items()),
            'currentPage' => $activities->currentPage(),
            'perPage' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }
}
