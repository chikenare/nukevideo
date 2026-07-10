<?php

namespace App\Services;

use App\Models\Stream;
use Illuminate\Support\Collection;

class EncodeCommandBuilder
{
    /**
     * Assemble a multi-output ffmpeg command reading one `$source`, one output per stream.
     * Pass `$start`/`$end` for a video chunk: `-ss/-to` before `-i` plus re-encode cuts exactly
     * `[start, end)` frame-accurately (a `-c copy` extract can't — it snaps back to the previous
     * keyframe and the windows overlap). Omit them for audio/subtitle sidecars (whole source).
     *
     * @param  Collection<int,Stream>  $streams
     * @param  array<int,string>  $outputPaths  stream id → local output path
     */
    public static function build(Collection $streams, string $source, array $outputPaths, ?float $start = null, ?float $end = null): string
    {
        $windowed = $start !== null && $end !== null;

        $seek = $windowed
            ? sprintf('-ss %.4f -to %.4f ', $start, $end)
            : '';

        $parts = [sprintf('ffmpeg -hide_banner -y %s-i "%s"', $seek, $source)];

        foreach ($streams as $stream) {
            $svc = new ChunkTranscodeService($stream);
            $path = $outputPaths[$stream->id];

            $parts[] = match ($stream->type) {
                'video' => sprintf('-fps_mode passthrough %s -f %s "%s"', $svc->buildVideoArguments($windowed), $svc->outputFormat(), $path),
                'audio' => sprintf('%s -movflags +faststart -f %s "%s"', $svc->buildAudioArguments(), $svc->outputFormat(), $path),
                'subtitle' => sprintf('-map %s -c:s webvtt -f %s "%s"', $svc->mapTarget(), $svc->outputFormat(), $path),
            };
        }

        return implode(' ', $parts);
    }
}
