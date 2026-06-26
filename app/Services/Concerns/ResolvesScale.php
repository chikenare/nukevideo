<?php

namespace App\Services\Concerns;

use InvalidArgumentException;

trait ResolvesScale
{
    private function buildScaleFilter(int $width, int $height): ?string
    {
        if ($width > 0 && $height > 0) {
            return "-vf scale={$width}:{$height}";
        }

        return null;
    }

    private function resolveOutputDimensions(array $params, int $sourceWidth, int $sourceHeight): array
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new InvalidArgumentException("Invalid source dimensions: {$sourceWidth}x{$sourceHeight}");
        }

        $hasWidth = isset($params['width']);
        $hasHeight = isset($params['height']);

        if ($hasWidth && ! $hasHeight) {
            $scale = min($params['width'] / $sourceWidth, 1.0);
        } elseif (! $hasWidth && $hasHeight) {
            $scale = min($params['height'] / $sourceHeight, 1.0);
        } else {
            $scale = min(
                ($params['width'] ?? $sourceWidth) / $sourceWidth,
                ($params['height'] ?? $sourceHeight) / $sourceHeight,
                1.0,
            );
        }

        return [
            $this->roundEven((int) round($sourceWidth * $scale)),
            $this->roundEven((int) round($sourceHeight * $scale)),
        ];
    }

    private function roundEven(int $value): int
    {
        return max(2, $value % 2 === 0 ? $value : $value - 1);
    }
}
