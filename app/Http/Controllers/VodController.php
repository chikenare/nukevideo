<?php

namespace App\Http\Controllers;

use App\Data\SubtitleData;
use App\Data\VodOutputData;
use App\Enums\VideoStatus;
use App\Http\Requests\VodRequest;
use App\Models\Node;
use App\Models\Output;
use App\Models\Video;
use App\Services\VodSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VodController extends Controller
{
    public function getOutputLink(VodRequest $request, string $ulid)
    {
        $validated = $request->validated();

        $output = Output::with('video', 'streams')
            ->whereHas('video', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->where('status', VideoStatus::COMPLETED->value);
            })
            ->where('ulid', $ulid)
            ->firstOrFail();

        $video = $output->video;

        $node = Node::findProxyForVideo($video->ulid);

        if (! $node) {
            abort(503, 'No node available');
        }

        $schema = app()->isLocal() ? 'http://' : 'https://';
        $sessionId = (string) Str::uuid();

        VodSessionService::create(
            sessionId: $sessionId,
            userId: $video->user_id,
            videoUlid: $video->ulid,
            outputUlid: $output->ulid,
            externalResourceId: $validated['external_resource_id'] ?? '',
            externalUserId: $validated['external_user_id'] ?? '',
        );

        $link = $this->buildLink(
            output: $output,
            schema: $schema,
            node: $node,
            sessionId: $sessionId,
            videoUlid: $video->ulid,
        );

        return response()->json(['data' => $link]);
    }

    private function buildLink(
        Output $output,
        string $schema,
        Node $node,
        string $sessionId,
        string $videoUlid,
    ): VodOutputData {
        $format = $output->formats()[0] ?? 'hls';

        $url = "{$schema}{$node->hostname}/{$output->manifestPath($format)}?s={$sessionId}";

        return VodOutputData::fromOutput($output, $url, $videoUlid);
    }

    public function subtitles(Request $request, string $ulid)
    {
        $video = Video::with(['streams' => fn ($q) => $q->where('type', 'subtitle')])
            ->where('ulid', $ulid)
            ->firstOrFail();

        // Subtitles are served as sidecar VTT (not packaged into the CMAF manifest). Route them
        // through the proxy like the media, so the private bucket is read via aws-auth.
        $node = Node::findProxyForVideo($video->ulid);
        $schema = app()->isLocal() ? 'http://' : 'https://';

        $subtitles = $video->streams->map(fn ($s) => new SubtitleData(
            name: $s->name ?? $s->language ?? 'und',
            language: $s->language,
            url: $node ? "{$schema}{$node->hostname}/{$s->path}" : Storage::url($s->path),
        ));

        return response()->json(['data' => $subtitles]);
    }
}
