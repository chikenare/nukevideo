<?php

namespace App\Services;

class CodecService
{
    public static function gpuCodecs(): array
    {
        return collect(config('ffmpeg.codecs'))
            ->where('requires_gpu', true)
            ->pluck('codec')
            ->all();
    }

    public static function isGpuCodec(?string $codec): bool
    {
        return $codec && in_array($codec, static::gpuCodecs());
    }

    public static function outputsRequireGpu(array $outputs): bool
    {
        foreach ($outputs as $output) {
            foreach ($output['variants'] ?? [] as $variant) {
                if (static::isGpuCodec($variant['video_codec'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }
}
