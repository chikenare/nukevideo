<?php

namespace App\Http\Controllers;

use App\Data\VodOutputData;
use App\Enums\VideoStatus;
use App\Http\Requests\VodRequest;
use App\Models\Node;
use App\Models\Output;
use App\Services\VodService;
use App\Services\VodSessionService;

class VodController extends Controller
{
    public function __construct(private VodService $vod) {}

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

        if (empty($output->formats())) {
            abort(422, 'Output has no playable formats');
        }

        $video = $output->video;

        $node = Node::findProxyForVideo($video->ulid);

        if (! $node) {
            abort(503, 'No node available');
        }

        $formats = $output->formats();
        $requestedFormat = $validated['format'] ?? 'dash';
        $format = in_array($requestedFormat, $formats, true)
            ? $requestedFormat
            : $formats[0];

        $session = VodSessionService::create(
            userId: $video->user_id,
            videoUlid: $video->ulid,
            outputUlid: $output->ulid,
            externalResourceId: $validated['external_resource_id'] ?? '',
            externalUserId: $validated['external_user_id'] ?? '',
        );

        $link = $this->buildLink(
            output: $output,
            node: $node,
            sessionId: $session,
            videoUlid: $video->ulid,
            ip: $request->ip(),
            format: $format,
            cap: $output->resolveCap($validated['resolution'] ?? null),
        );

        return response()->json(['data' => $link]);
    }

    private function buildLink(
        Output $output,
        Node $node,
        string $sessionId,
        string $videoUlid,
        string $ip,
        string $format,
        ?int $cap = null,
    ): VodOutputData {
        $schema = app()->isLocal() ? 'http://' : 'https://';

        $url = "{$schema}{$node->hostname}/{$sessionId}/{$output->manifestPath($format, $cap)}";
        $url = $this->vod->signUrl($url, $ip);

        return VodOutputData::fromOutput($output, $url, $videoUlid);
    }
}
