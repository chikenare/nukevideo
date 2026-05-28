<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Http\Requests\VodRequest;
use App\Models\Node;
use App\Models\Output;
use App\Services\VodService;
use App\Services\VodSessionService;
use App\Settings\GeneralSettings;
use Exception;
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
            throw new Exception('Node not available');
        }

        $service = new VodService;
        $schema = app()->isLocal() ? 'http://' : 'https://';
        $sessionId = Str::uuid();
        $ip = $validated['ip'] ?? $request->ip() ?? '0.0.0.0';

        $video = $request->user()
            ->videos()
            ->with('outputs')
            ->where('ulid', $ulid)
            ->where('status', VideoStatus::COMPLETED->value)
            ->firstOrFail();

        $links = $video
            ->outputs
            ->map(function (Output $output) use ($service, $schema, $node, $validated, $ip, $sessionId, $ulid, $video) {
                VodSessionService::create(
                    sessionId: (string) $sessionId,
                    userId: $video->user_id,
                    videoUlid: $ulid,
                    outputUlid: $output->ulid,
                    externalResourceId: $validated['external_resource_id'] ?? '',
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
        ?string $ip,
        string $sessionId
    ): array {
        $format = $output->format->value;
        $manifest = self::FORMAT_MANIFEST[$format];

        $resourceId = $output->ulid;
        if ($resolution) {
            $resourceId .= "_$resolution";
        }

        $url = $service->generateVodSignedUrl(
            "$schema$node->hostname/$format/$resourceId/$sessionId/$manifest",
            $resourceId,
            $format,
            $ip
        );

        return [
            'ulid' => $output->ulid,
            'format' => $format,
            'url' => $url,
        ];
    }

    public function getConfig(Request $request, string $resourceId, string $session)
    {
        $data = explode('_', $resourceId);
        $ulid = $data[0];
        $resolution = $data[1] ?? 0;

        if (! $ulid) {
            abort(403, "Invalid id $ulid");
        }

        $includeSubtitles = app(GeneralSettings::class)->include_subtitles;

        $output = Output::with('streams')
            ->where('ulid', $ulid)
            ->firstOrFail();

        $streams = $output->streams;

        if ($includeSubtitles) {
            $subtitles = $output->video->streams()->where('type', 'subtitle')->get();
            $streams = $streams->merge($subtitles);
        }

        $sequences = $streams
            ->filter(function ($s) use ($resolution) {
                if (! in_array($s->type, ['video', 'audio', 'subtitle'])) {
                    return false;
                }

                return $s->type !== 'video' || $resolution === 0 || $s->height <= $resolution;
            })
            ->map(function ($s) {
                $data = [
                    'label' => $s->name ?? $s->height ?? 'und',
                    'clips' => [
                        [
                            'type' => 'source',
                            'path' => $s->path,
                        ],
                    ],
                ];
                if ($s->language) {
                    $data['language'] = $s->language;
                }

                return $data;
            })
            ->values();

        return response()->json([
            'sequences' => $sequences->take(32),
        ]);
    }

    public function subtitles(Request $request, string $ulid)
    {
        $video = $request->user()
            ->videos()
            ->with(['streams' => fn ($q) => $q->where('type', 'subtitle')])
            ->where('ulid', $ulid)
            ->firstOrFail();

        $subtitles = $video->streams->map(fn ($s) => [
            'name' => $s->name ?? $s->language ?? 'und',
            'language' => $s->language,
            'url' => Storage::url($s->path),
        ]);

        return response()->json(['data' => $subtitles]);
    }
}
