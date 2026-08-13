<?php

namespace App\Http\Controllers;

use App\Data\Stream\DownloadStreamData;
use App\Data\Stream\UpdateStreamData;
use App\Data\StreamData;
use App\Services\DownloadLinkService;
use App\Services\StreamManagementService;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(
        protected StreamManagementService $streamService,
        protected DownloadLinkService $downloads,
    ) {}

    public function update(Request $request, UpdateStreamData $data, string $ulid)
    {
        $stream = $this->streamService->update($ulid, $data, $request->project());

        return response()->json([
            'message' => 'Stream updated successfully',
            'data' => StreamData::fromModel($stream->fresh()),
        ]);
    }

    /**
     * One signed link for one track. Callers ask per track and mux the pieces themselves: the
     * packaged video renditions carry no audio, so there is nothing single-file to hand back.
     */
    public function download(Request $request, DownloadStreamData $data, string $ulid)
    {
        return response()->json([
            'data' => $this->downloads->forStreamUlid($ulid, $request->project(), $data->tid),
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
