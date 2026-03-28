<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Node;
use App\Models\Output;
use App\Services\VodService;
use Exception;
use Illuminate\Http\Request;

class VodController extends Controller
{
    private const FORMAT_MANIFEST = [
        'hls' => 'master.m3u8',
        'dash' => 'manifest.mpd',
        'mp4' => 'video.mp4',
    ];


    public function getSources(Request $request, string $ulid)
    {
        $node = Node::proxy()->active()->first();

        if (!$node) {
            throw new Exception('Node not available');
        }

        $service = new VodService;
        $schema = app()->isProduction() ? 'https://' : 'http://';

        $video = $request->user()
            ->videos()
            ->with('outputs')
            ->where('ulid', $ulid)
            ->where('status', VideoStatus::COMPLETED->value)
            ->firstOrFail();

        $links = $video
            ->outputs
            ->map(fn(Output $output) => $this->buildLink($output, $service, $schema, $node));

        return response()->json(['data' => $links]);
    }

    private function buildLink(Output $output, VodService $service, string $schema, Node $node): array
    {
        $format = $output->format->value;
        $manifest = self::FORMAT_MANIFEST[$format];

        return [
            'ulid' => $output->ulid,
            'format' => $format,
            'url' => $service->generateVodSignedUrl(
                "$schema$node->hostname/$format/$output->ulid/$manifest",
                $output->ulid,
                $format,
            ),
        ];
    }

    public function getConfig(Request $request, string $ulid)
    {
        $output = Output::with([
            'video.streams' => function ($q) {
                $q->whereIn('type', ['video', 'audio', 'subtitle']);
            },
        ])
            ->where('ulid', $ulid)
            ->firstOrFail();

        $sequences = [];

        foreach ($output->video->streams as $s) {
            $sequences[] = [
                'language' => $s->language,
                'label' => $s->name ?? $s->height ?? 'und',
                'clips' => [
                    [
                        'type' => 'source',
                        'path' => $s->path,
                    ],
                ],
            ];
        }

        return response()->json([
            'sequences' => array_slice($sequences, 0, 32),
        ]);
    }
}
