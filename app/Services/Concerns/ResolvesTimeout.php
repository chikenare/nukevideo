<?php

namespace App\Services\Concerns;

trait ResolvesTimeout
{
    /**
     * Hard ceiling for a single ffmpeg run. Must stay below ProcessStreamJob's
     * $timeout (21000) — and therefore the queue's retry_after — so an encode
     * that overruns is killed by us (clean failure with ffmpeg's own error)
     * rather than by the worker timeout, which would orphan the ffmpeg child
     * and let the queue redeliver the job mid-encode.
     */
    private const MAX_ENCODE_SECONDS = 20000;

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

        if (in_array($codec, $slowCodecs, true)) {
            $multiplier *= 1.5;
        }

        // $fps = $this->stream->video->fps ?? 30;
        // if ($fps > 30) {
        //     $multiplier *= ($fps / 30);
        // }

        return (int) min(max(300, $duration * $multiplier), self::MAX_ENCODE_SECONDS);
    }
}
