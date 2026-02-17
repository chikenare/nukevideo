<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Http\Resources\VideoResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function index(Request $request)
    {
        $videos = $request->user()->videos()
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
            ->with('streams')
            ->where('ulid', $ulid)->firstOrFail();

        return new VideoResource($video);
    }

    public function update(Request $request, string $ulid)
    {
        $validated = $request->validate(['name' => 'required|max:255']);

        $video = $request->user()->videos()->where('ulid', $ulid)->firstOrFail();

        $video->update($validated);

        return response()->json([
            'message' => 'Video updated successfully'
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $video = $request->user()->videos()->where('ulid', $ulid)->firstOrFail();

        if ($video->status != VideoStatus::COMPLETED->value) {
            return response()->json([
                'message' => 'You cannot delete a video if it is still in progress.'
            ], 400);
        }

        $video->delete();

        return response()->json([
            'message' => 'Video deleted successfully'
        ]);
    }

    public function getAsset(Request $request, string $ulid, string $filename)
    {
        $path = "$ulid/$filename";

        if (!Storage::exists($path)) {
            return response()->json([
                'message' => 'Thumbnail file not found'
            ], 404);
        }

        $file = Storage::get($path);
        $mimeType = Storage::mimeType($path);

        return response($file, 200, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=31536000'
        ]);
    }
}
