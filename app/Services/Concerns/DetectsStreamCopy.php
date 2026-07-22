<?php

namespace App\Services\Concerns;

trait DetectsStreamCopy
{
    /**
     * ffprobe codec_name → ffmpeg encoder names
     */
    private const CODEC_ENCODER_MAP = [
        'h264' => ['libx264', 'h264_qsv', 'h264_nvenc'],
        'hevc' => ['libx265', 'hevc_qsv', 'hevc_nvenc'],
        'av1' => ['libsvtav1', 'av1_qsv', 'av1_nvenc'],
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

    /** @return array{0: array, 1: array} [source, target] context for comparison */
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

    /** Copy if source bitrate <= the first explicit target; no target → re-encode. */
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
        return match (true) {
            str_ends_with(strtolower($value), 'k') => (int) round((float) $value * 1000),
            str_ends_with(strtolower($value), 'm') => (int) round((float) $value * 1000000),
            default => (int) $value,
        };
    }
}
