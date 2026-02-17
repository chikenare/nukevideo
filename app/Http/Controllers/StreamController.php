<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function update(Request $request, string $ulid)
    {
        $validated = $request->validate(['name' => 'nullable|string|max:20']);

        $stream = Stream::with('video')->where('ulid', $ulid)->firstOrFail();

        $video = $stream->video;

        if (!$video || $video->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Not found'
            ], 404);
        }

        $stream->update($validated);

        return response()->json(['message' => 'Stream updated successfully']);
    }
    public function destroy(Request $request, string $ulid)
    {
        $stream = Stream::with('video')->where('ulid', $ulid)->firstOrFail();

        $video = $stream->video;

        if (!$video || $video->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Not found'
            ], 404);
        }

        if (!in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            return response()->json([
                'message' => 'You cannot delete any stream until the video is processed.'
            ], 400);
        }

        $stream->delete();

        return response()->json([
            'message' => 'Stream deleted successfully'
        ]);
    }
}
