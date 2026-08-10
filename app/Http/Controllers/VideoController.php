<?php

namespace App\Http\Controllers;

use App\Data\Video\UpdateVideoData;
use App\Data\VideoData;
use App\Models\Video;
use App\Services\VideoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct(protected VideoService $videoService) {}

    public function index(Request $request)
    {
        $query = $request->project()->videos()
            ->latest()
            ->with(['outputs.streams', 'streams']);

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($externalUserId = $request->input('external_user_id')) {
            $query->where('external_user_id', $externalUserId);
        }

        if ($externalResourceId = $request->input('external_resource_id')) {
            $query->where('external_resource_id', $externalResourceId);
        }

        // `paginate()` never reads the request, so the documented `per_page` was silently ignored
        // and every page came back at 15. Capped: the payload embeds each video's outputs and
        // streams, so an unbounded page is a heavy query and a heavy response.
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $videos = $query->paginate($perPage);

        return [
            'data' => array_map(fn ($v) => VideoData::fromModel($v), $videos->items()),
            'currentPage' => $videos->currentPage(),
            'perPage' => $videos->perPage(),
            'total' => $videos->total(),
        ];
    }

    public function show(Request $request, string $ulid)
    {
        $video = $request->project()->videos()
            ->with(['outputs.streams', 'streams'])
            ->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => VideoData::fromModel($video)]);
    }

    public function update(Request $request, UpdateVideoData $data, string $ulid)
    {
        $video = $this->videoService->update($ulid, $data->toDatabase(), $request->project());

        return response()->json([
            'message' => 'Video updated successfully',
            'data' => VideoData::fromModel($video->fresh()->load(['outputs.streams', 'streams'])),
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $this->videoService->destroy($ulid, $request->project());

        return response()->json([
            'message' => 'Video deleted successfully',
        ]);
    }

    public function getAsset(Request $request, string $ulid, string $filename)
    {
        $path = Video::assetPath($ulid, $filename);

        if (! Storage::exists($path)) {
            return response()->json([
                'message' => 'Thumbnail file not found',
            ], 404);
        }

        $file = Storage::get($path);
        $mimeType = Storage::mimeType($path);

        return response($file, 200, [
            'Content-Type' => $mimeType,
        ]);
    }
}
