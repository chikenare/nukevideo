<?php

namespace App\Services\Concerns;

trait ResolvesTimeout
{
    private function resolveProcessTimeout(bool $isCopy = false): int
    {
        $duration = (float) ($this->stream->video->duration ?? 0);

        if ($duration <= 0) {
            return 300;
        }

        if ($isCopy) {
            return (int) min(
                max(300, $duration * 0.75),
                3600
            );
        }

        $meta = $this->stream->meta ?? [];

        $height = max(
            $this->stream->height ?? 0,
            $meta['source_height'] ?? 0
        );

        $multiplier = match (true) {
            $height >= 2160 => 12,
            $height >= 1440 => 9,
            $height >= 1080 => 6,
            $height >= 720 => 4,
            $height >= 480 => 2.5,
            default => 2,
        };

        $params = $this->stream->input_params ?? [];
        $codec = $params['video_codec'] ?? '';

        $slowCodecs = ['libx265', 'libsvtav1'];
        $fastHwCodecs = ['hevc_nvenc', 'av1_nvenc', 'h264_nvenc'];

        if (in_array($codec, $slowCodecs, true)) {
            $multiplier *= 1.5;
        } elseif (in_array($codec, $fastHwCodecs, true)) {
            $multiplier *= 0.7;
        }

        // $fps = $this->stream->video->fps ?? 30;
        // if ($fps > 30) {
        //     $multiplier *= ($fps / 30);
        // }

        return (int) max(300, $duration * $multiplier);
    }
}
