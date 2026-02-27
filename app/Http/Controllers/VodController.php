<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Node;
use App\Models\Video;
use App\Services\VodService;
use Exception;
use Illuminate\Http\Request;

class VodController extends Controller
{
    public function getLink(Request $request, string $ulid)
    {
        $video = Video::with('streams')
            ->where('ulid', $ulid)
            ->firstOrFail();

        $node = Node::where('type', 'proxy')->first();

        if (!$node) {
            throw new Exception('Node not available');
        }


        $service = new VodService;

        $signedUrl = $service->generateVodSignedUrl(
            "$node->hostname/hls/$ulid/master.m3u8",
            $ulid,
        );

        return [
            'url' => $signedUrl
        ];
    }



    public function getConfig(Request $request, string $ulid)
    {
        $video = Video::with([
            'streams' => function ($q) {
                $q->whereIn('type', ['video', 'audio', 'subtitle']);
            }
        ])
            ->where('status', VideoStatus::COMPLETED->value)
            ->where('ulid', $ulid)
            ->firstOrFail();

        $sequences = [];

        foreach ($video->streams as $s) {
            $sequences[] = [
                'language' => $s->language,
                'label' => $s->name ?? $s->height ?? 'und',
                'clips' => [
                    [
                        'type' => 'source',
                        'path' => $s->path
                    ]
                ]
            ];
        }

        return response()->json([
            'sequences' => array_slice($sequences, 0, 32)
        ]);
    }
}
