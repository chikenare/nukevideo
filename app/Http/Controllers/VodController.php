<?php

namespace App\Http\Controllers;

use App\Data\SubtitleData;
use App\Data\VodOutputData;
use App\Enums\VideoStatus;
use App\Http\Requests\VodRequest;
use App\Models\Node;
use App\Models\Output;
use App\Services\VodService;
use App\Services\VodSessionService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VodController extends Controller
{
    private const FORMAT_MANIFEST = [
        'hls' => 'master.m3u8',
        'dash' => 'manifest.mpd',
        'mp4' => 'video.mp4',
    ];

    public function getOutputs(VodRequest $request, string $ulid)
    {
        $validated = $request->validated();

        $node = Node::findProxyForVideo($ulid);

        if (! $node) {
            abort(503, 'No node available');
        }

        $service = new VodService;
        $schema = app()->isLocal() ? 'http://' : 'https://';
        $ip = $validated['ip'] ?? $request->ip() ?? '0.0.0.0';

        $video = $request->user()
            ->videos()
            ->with('outputs')
            ->where('ulid', $ulid)
            ->where('status', VideoStatus::COMPLETED->value)
            ->firstOrFail();

        $links = $video
            ->outputs
            ->map(function (Output $output) use ($service, $schema, $node, $validated, $ip, $ulid, $video) {
                $sessionId = (string) Str::uuid();

                VodSessionService::create(
                    sessionId: $sessionId,
                    userId: $video->user_id,
                    videoUlid: $ulid,
                    outputUlid: $output->ulid,
                    externalResourceId: $validated['external_resource_id'] ?? '',
                    externalUserId: $validated['external_user_id'] ?? '',
                );

                return $this->buildLink(
                    output: $output,
                    service: $service,
                    schema: $schema,
                    node: $node,
                    resolution: $validated['resolution'] ?? null,
                    ip: $ip,
                    sessionId: $sessionId,
                );
            });

        return response()->json(['data' => $links]);
    }

    private function buildLink(
        Output $output,
        VodService $service,
        string $schema,
        Node $node,
        ?int $resolution,
        string $ip,
        string $sessionId,
    ): VodOutputData {
        $format = $output->format->value;
        $manifest = self::FORMAT_MANIFEST[$format];

        $resourceId = $output->ulid;
        if ($resolution) {
            $resourceId .= "_$resolution";
        }

        $hostname = $node->hostname;
        $url = $service->generateVodSignedUrl(
            "$schema$hostname/$format/$resourceId/$sessionId/$manifest",
            $resourceId,
            $format,
            $ip,
        );

        return new VodOutputData(
            ulid: $output->ulid,
            format: $format,
            url: $url,
        );
    }

    public function getConfig(Request $request, string $resourceId, string $session)
    {
        [$ulid, $resolution] = [...explode('_', $resourceId, 2), null];
        $resolution = (int) ($resolution ?? 0);

        $includeSubtitles = app(GeneralSettings::class)->include_subtitles;

        $with = ['streams'];
        if ($includeSubtitles) {
            $with['video.streams'] = fn ($q) => $q->where('type', 'subtitle');
        }

        $output = Output::with($with)->where('ulid', $ulid)->firstOrFail();

        $streams = $output->streams;

        if ($includeSubtitles) {
            $streams = $streams->merge($output->video->streams);
        }

        $sequences = $streams
            ->filter(fn ($s) => \in_array($s->type, ['video', 'audio', 'subtitle'])
                && ($s->type !== 'video' || $resolution === 0 || $s->height <= $resolution))
            ->map(function ($s) {
                $entry = [
                    'label' => $s->name ?? $s->height ?? 'und',
                    'clips' => [['type' => 'source', 'path' => $s->path]],
                ];

                if ($s->language) {
                    $entry['language'] = $s->language;
                }

                return $entry;
            })
            ->values();

        return response()->json(['sequences' => $sequences->take(32)]);
    }

    public function subtitles(Request $request, string $ulid)
    {
        $video = $request->user()
            ->videos()
            ->with(['streams' => fn ($q) => $q->where('type', 'subtitle')])
            ->where('ulid', $ulid)
            ->firstOrFail();

        $subtitles = $video->streams->map(fn ($s) => new SubtitleData(
            name: $s->name ?? $s->language ?? 'und',
            language: $s->language,
            url: Storage::url($s->path),
        ));

        return response()->json(['data' => $subtitles]);
    }
}
