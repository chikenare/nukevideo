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

        // A rung's box is a size class, not an orientation: "1920x1080" is 1080p for a vertical
        // source too, so it turns to 1080x1920. Fitted as written, a 1080x1920 phone video got a
        // 608x1080 "1080p" rung and a 2160x3840 master could never keep a 4K one — the template's
        // own limits stop at 2160 tall. Only a full box turns: a lone width or height still means
        // that edge, as it always has.
        if ($hasWidth && $hasHeight && $this->orientedAgainst((int) $params['width'], (int) $params['height'], $sourceWidth, $sourceHeight)) {
            [$params['width'], $params['height']] = [$params['height'], $params['width']];
        }

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

    /** Whether the box and the source face different ways; a square on either side faces both. */
    private function orientedAgainst(int $boxWidth, int $boxHeight, int $sourceWidth, int $sourceHeight): bool
    {
        if ($boxWidth === $boxHeight || $sourceWidth === $sourceHeight) {
            return false;
        }

        return ($boxWidth > $boxHeight) !== ($sourceWidth > $sourceHeight);
    }

    private function roundEven(int $value): int
    {
        return max(2, $value % 2 === 0 ? $value : $value - 1);
    }
}
