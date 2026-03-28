<?php

namespace App\Http\Controllers;

use App\Http\Requests\Video\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Services\VideoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct(protected VideoService $videoService) {}

    public function index(Request $request)
    {
        $videos = $request->user()->videos()
            ->with('streams')
            ->paginate();

        return [
            'data' => VideoResource::collection($videos->items()),
            'currentPage' => $videos->currentPage(),
            'perPage' => $videos->perPage(),
            'total' => $videos->total(),
        ];
    }

    public function show(Request $request, string $ulid)
    {
        $video = $request->user()->videos()
            ->with(['outputs.streams', 'streams'])
            ->where('ulid', $ulid)->firstOrFail();

        return new VideoResource($video);
    }

    public function update(UpdateVideoRequest $request, string $ulid)
    {
        $video = $this->videoService->update($ulid, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Video updated successfully',
            'data' => new VideoResource($video->fresh())
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $this->videoService->destroy($ulid, $request->user());

        return response()->json([
            'message' => 'Video deleted successfully',
        ]);
    }

    public function getAsset(Request $request, string $ulid, string $filename)
    {
        $path = "$ulid/$filename";

        if (! Storage::exists($path)) {
            return response()->json([
                'message' => 'Thumbnail file not found',
            ], 404);
        }

        $file = Storage::get($path);
        $mimeType = Storage::mimeType($path);

        return response($file, 200, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
