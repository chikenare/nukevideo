<?php

namespace App\Http\Controllers;

use App\Data\VodData;
use App\Data\VodOutputData;
use App\Enums\VideoStatus;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Output;
use App\Models\Video;
use App\Services\Cdn\CdnProvider;
use Illuminate\Http\Request;

class VodController extends Controller
{
    public function __construct(private CdnProvider $cdn) {}

    public function getOutputLink(Request $request, VodData $data, string $ulid)
    {
        $output = Output::with('video', 'streams')
            ->whereHas('video', function ($query) use ($request) {
                $query->where('project_id', $request->project()->id)
                    ->where('status', VideoStatus::COMPLETED->value);
            })
            ->where('ulid', $ulid)
            ->firstOrFail();

        if (empty($output->formats())) {
            abort(422, 'Output has no playable formats');
        }

        $video = $output->video;

        $formats = $output->formats();
        $requestedFormat = $data->format ?? 'dash';
        $format = in_array($requestedFormat, $formats, true)
            ? $requestedFormat
            : $formats[0];

        $link = $this->buildLink(
            output: $output,
            video: $video,
            ip: $request->ip(),
            format: $format,
            cap: $output->resolveCap($data->resolution),
        );

        return response()->json(['data' => $link]);
    }

    private function buildLink(
        Output $output,
        Video $video,
        string $ip,
        string $format,
        ?int $cap = null,
    ): VodOutputData {
        try {
            $url = $this->cdn->manifestUrl(
                $video,
                $output->manifestPath($format, $cap),
                $ip,
                app()->isLocal(),
            );
        } catch (NoCdnNodeAvailableException) {
            abort(503, 'No node available');
        }

        return VodOutputData::fromOutput($output, $url, $video->ulid);
    }
}
