<?php

namespace App\Http\Controllers\Api;

use App\Data\Node\UpdateNodeEnvironmentData;
use App\Http\Controllers\Controller;
use App\Settings\NodeSettings;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Optional;

class NodeEnvironmentController extends Controller
{
    public function show(NodeSettings $settings): JsonResponse
    {
        return $this->respond($settings);
    }

    public function update(UpdateNodeEnvironmentData $data, NodeSettings $settings): JsonResponse
    {
        $settings->environment = $data->environment ?? '';
        if (! $data->chunkStoreAddress instanceof Optional) {
            $settings->chunk_store_address = $data->chunkStoreAddress ?? '';
        }
        $settings->save();

        return $this->respond($settings);
    }

    private function respond(NodeSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => ['environment' => $settings->environment, 'chunkStoreAddress' => $settings->chunk_store_address],
        ]);
    }
}
