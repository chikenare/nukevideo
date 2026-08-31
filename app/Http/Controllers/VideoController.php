<?php

namespace App\Http\Controllers;

use App\Data\Video\DownloadVideoTracksData;
use App\Data\Video\IndexVideosData;
use App\Data\Video\UpdateVideoData;
use App\Data\VideoData;
use App\Models\Stream;
use App\Models\Video;
use App\Services\DownloadLinkService;
use App\Services\VideoService;
use App\Support\TrackingId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct(
        protected VideoService $videoService,
        protected DownloadLinkService $downloads,
    ) {}

    public function index(Request $request, IndexVideosData $data)
    {
        $query = $request->project()->videos()
            ->with(['outputs.streams', 'streams']);

        if ($data->search) {
            $query->where('name', 'like', "%{$data->search}%");
        }

        if ($data->externalUserId) {
            $query->where('external_user_id', $data->externalUserId);
        }

        if ($data->externalResourceId) {
            $query->where('external_resource_id', $data->externalResourceId);
        }

        if ($statuses = $data->statuses()) {
            $query->whereIn('status', $statuses);
        }

        // `size` is not a column: it is what the listing shows, the sum of every stream's package
        // and file bytes ({@see VideoData::fromModel}), so it is ordered by the same sum as a
        // correlated subquery. The others are plain columns. `id` is the tie-breaker on every
        // sort: without it two equal names could swap between pages as the planner pleases.
        $sort = $data->sort === 'size'
            ? Stream::selectRaw('COALESCE(SUM(package_size + file_size), 0)')->whereColumn('video_id', 'videos.id')
            : $data->sort;

        $query->orderBy($sort, $data->direction)->orderBy('id', $data->direction);

        $videos = $query->paginate($data->perPage);

        return [
            'data' => array_map(fn ($v) => VideoData::fromModel($v), $videos->items()),
            'currentPage' => $videos->currentPage(),
            'perPage' => $videos->perPage(),
            'total' => $videos->total(),
        ];
    }

    /**
     * Signed links for a video's tracks in one pass.
     *
     * Keyed by video rather than by an arbitrary list of streams: what the batch hoists — the
     * status check, the proxy node, the caller's auth — is per video, and the route is what makes
     * that invariant true instead of a rule someone has to remember.
     */
    public function downloads(Request $request, DownloadVideoTracksData $data, string $ulid)
    {
        $video = $request->project()->videos()->where('ulid', $ulid)->firstOrFail();

        return response()->json([
            'data' => $this->downloads->forVideo(
                $video,
                $data->streamUlids,
                TrackingId::resolve($data->trackingId, $request->user()),
            ),
        ]);
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

    /**
     * The URL keeps the flat `{ulid}/{filename}` shape ({@see Video::assetPath}) wherever the object
     * actually sits, so cached links and API consumers never break; the zone is resolved here, at the
     * cost of one indexed query on a cache miss.
     *
     * The flat key is tried second rather than skipped, because the two writers of these objects run
     * on worker nodes from a baked image with no code mount: a worker still on an older build keeps
     * publishing `{ulid}/thumbnail.jpg` after this deploys, and nothing ever re-runs those jobs. The
     * route caches responses for a week and Laravel's cache-header middleware does not spare error
     * responses, so a single miss here would pin a public 404 for seven days.
     */
    public function getAsset(Request $request, string $ulid, string $filename)
    {
        $video = Video::where('ulid', $ulid)->first();
        $flat = Video::assetPath($ulid, $filename);

        $path = $video?->assetKey($filename) ?? $flat;

        if ($path !== $flat && ! Storage::exists($path)) {
            $path = $flat;
        }

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
