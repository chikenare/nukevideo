<?php

namespace App\Http\Controllers\Api;

use App\Data\Node\UpdateNodeEnvironmentData;
use App\Http\Controllers\Controller;
use App\Settings\NodeSettings;
use Illuminate\Http\JsonResponse;

class NodeEnvironmentController extends Controller
{
    public function show(NodeSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => ['environment' => $settings->environment],
        ]);
    }

    public function update(UpdateNodeEnvironmentData $data, NodeSettings $settings): JsonResponse
    {
        $settings->environment = $data->environment ?? '';
        $settings->save();

        return response()->json([
            'data' => ['environment' => $settings->environment],
        ]);
    }
}
