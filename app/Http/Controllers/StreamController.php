<?php

namespace App\Http\Controllers;

use App\Http\Requests\Stream\UpdateStreamRequest;
use App\Http\Resources\StreamResource;
use App\Services\StreamManagementService;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(protected StreamManagementService $streamService) {}

    public function update(UpdateStreamRequest $request, string $ulid)
    {
        $stream = $this->streamService->update($ulid, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Stream updated successfully',
            'data' => new StreamResource($stream->fresh())
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $this->streamService->destroy($ulid, $request->user());

        return response()->json([
            'message' => 'Stream deleted successfully',
        ]);
    }
}
