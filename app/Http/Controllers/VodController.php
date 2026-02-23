<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\VodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VodController extends Controller
{
    public function getLink(Request $request, string $ulid)
    {
        $video = Video::with('streams')
            ->where('ulid', $ulid)
            ->firstOrFail();

        if ($video->type == 'download') {
            $downloadStream = $video->streams->where('type', 'download')->first();

            if (!$downloadStream) {
                abort(404, 'Download stream not found');
            }

            return [
                'url' => Storage::temporaryUrl($downloadStream->path, now()->addHours(4)),
                'type' => $video->output_format,
            ];
        }

        $service = new VodService;

        $signedUrl = $service->generateVodSignedUrl(
            config('node.base_url') . "/hls/$ulid/master.m3u8",
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
