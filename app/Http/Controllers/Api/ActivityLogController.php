<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = Activity::where('causer_type', \App\Models\User::class)
            ->where('causer_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $activities->items(),
            'currentPage' => $activities->currentPage(),
            'perPage' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }
}
