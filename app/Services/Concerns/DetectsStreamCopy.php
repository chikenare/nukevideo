<?php

namespace App\Services\Concerns;

trait DetectsStreamCopy
{
    /**
     * ffprobe codec_name → ffmpeg encoder names
     */
    private const CODEC_ENCODER_MAP = [
        'h264' => ['libx264'],
        'hevc' => ['libx265'],
        'av1' => ['libsvtav1'],
        'aac' => ['aac', 'libfdk_aac'],
        'opus' => ['libopus'],
    ];

    private function shouldCopyVideo(): bool
    {
        [$source, $target] = $this->resolveVideoContext();

        if (! $source['codec'] || $source['bitrate'] <= 0) {
            return false;
        }

        if (! $this->codecMatchesEncoder($source['codec'], $target['codec'])) {
            return false;
        }

        if ($target['width'] && $target['width'] !== $source['width']) {
            return false;
        }

        if ($target['height'] && $target['height'] !== $source['height']) {
            return false;
        }

        return $this->sourceBitRateBelowTarget($source['bitrate'], [
            $target['constant_bitrate'],
            $target['maxrate'],
        ]);
    }

    private function shouldCopyAudio(): bool
    {
        [$source, $target] = $this->resolveAudioContext();

        if (! $source['codec'] || $source['bitrate'] <= 0) {
            return false;
        }

        if (! $this->codecMatchesEncoder($source['codec'], $target['codec'])) {
            return false;
        }

        if ($target['sample_rate'] && (string) $target['sample_rate'] !== (string) $source['sample_rate']) {
            return false;
        }

        if ($target['channels'] && (int) $target['channels'] !== (int) $source['channels']) {
            return false;
        }

        return $this->sourceBitRateBelowTarget($source['bitrate'], [
            $target['bitrate'],
        ]);
    }

    /**
     * Returns [source, target] context arrays for video comparison.
     */
    private function resolveVideoContext(): array
    {
        $meta = $this->stream->meta ?? [];
        $params = $this->stream->input_params ?? [];

        $source = [
            'codec' => $meta['source_codec'] ?? null,
            'bitrate' => $meta['source_bit_rate'] ?? 0,
            'height' => (int) ($meta['source_height'] ?? 0),
            'width' => (int) ($meta['source_width'] ?? 0),
        ];

        $target = [
            'codec' => $params['video_codec'] ?? null,
            'width' => isset($params['width']) ? (int) $params['width'] : null,
            'height' => isset($params['height']) ? (int) $params['height'] : null,
            'constant_bitrate' => $params['constant_bitrate'] ?? null,
            'maxrate' => $params['maxrate'] ?? null,
        ];

        return [$source, $target];
    }

    /**
     * Returns [source, target] context arrays for audio comparison.
     */
    private function resolveAudioContext(): array
    {
        $meta = $this->stream->meta ?? [];
        $params = $this->stream->input_params ?? [];

        $source = [
            'codec' => $meta['source_audio_codec'] ?? null,
            'bitrate' => $meta['source_audio_bit_rate'] ?? 0,
            'sample_rate' => $meta['source_sample_rate'] ?? null,
            'channels' => $meta['source_channels'] ?? null,
        ];

        $target = [
            'codec' => $params['audio_codec'] ?? null,
            'bitrate' => $params['audio_bitrate'] ?? null,
            'sample_rate' => $params['sample_rate'] ?? null,
            'channels' => $params['channels'] ?? null,
        ];

        return [$source, $target];
    }

    /**
     * Copy if source bitrate <= any explicit target. No target → re-encode.
     */
    private function sourceBitRateBelowTarget(int $sourceBitRate, array $targets): bool
    {
        foreach ($targets as $target) {
            if ($target !== null) {
                return $sourceBitRate <= $this->parseBitrateValue($target);
            }
        }

        return false;
    }

    private function codecMatchesEncoder(string $sourceCodec, ?string $targetEncoder): bool
    {
        if (! $targetEncoder) {
            return false;
        }

        return in_array($targetEncoder, self::CODEC_ENCODER_MAP[$sourceCodec] ?? []);
    }

    private function parseBitrateValue(string $value): int
    {
        if (str_ends_with(strtolower($value), 'k')) {
            return (int) $value * 1000;
        }

        return (int) $value;
    }
}
