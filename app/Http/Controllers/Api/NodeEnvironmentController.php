<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Settings\NodeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeEnvironmentController extends Controller
{
    public function show(NodeSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => ['environment' => $settings->environment],
        ]);
    }

    public function update(Request $request, NodeSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['required', 'string'],
        ]);

        $settings->environment = $validated['environment'];
        $settings->save();

        return response()->json([
            'data' => ['environment' => $settings->environment],
        ]);
    }
}
