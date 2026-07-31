<?php

namespace App\Http\Controllers;

use App\Data\Stream\UpdateStreamData;
use App\Data\StreamData;
use App\Services\StreamManagementService;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(protected StreamManagementService $streamService) {}

    public function update(Request $request, UpdateStreamData $data, string $ulid)
    {
        $stream = $this->streamService->update($ulid, $data, $request->project());

        return response()->json([
            'message' => 'Stream updated successfully',
            'data' => StreamData::fromModel($stream->fresh()),
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $this->streamService->destroy($ulid, $request->project());

        return response()->json([
            'message' => 'Stream deleted successfully',
        ]);
    }
}
