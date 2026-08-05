<?php

use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

/** The template's rung set from prod: 1080p + 720p + 4K, each with its own quality knobs. */
const LADDER = [
    ['width' => 1920, 'height' => 1080, 'qsv_global_quality' => 22],
    ['width' => 1280, 'height' => 720, 'qsv_global_quality' => 24],
    ['width' => 3840, 'height' => 2160, 'qsv_global_quality' => 22],
];

function keptVariants(int $sourceWidth, int $sourceHeight, array $variants = LADDER): array
{
    $service = new CreateVideoStreamsService;
    $source = new FFStream(['width' => $sourceWidth, 'height' => $sourceHeight]);

    return (fn () => $this->filterVariants($source, $variants))->call($service);
}

describe('filterVariants', function () {
    it('keeps the 4K rung for a cinema-cropped master a few pixels short of it', function () {
        // The prod case: a 3832x1596 (2.40:1, mod-8) source lost its 4K rung to `3832 >= 3840`.
        // The variant resolves to 3832x1596 — the source's own size, no upscale — so it stays.
        $kept = keptVariants(3832, 1596);

        expect($kept)->toHaveCount(3)
            ->and(collect($kept)->pluck('width')->all())->toBe([1920, 1280, 3840]);
    });

    it('keeps the full ladder for an exact 4K source', function () {
        expect(keptVariants(3840, 2160))->toHaveCount(3);
    });

    it('collapses variants that would produce the same pixels, keeping the tuned one', function () {
        // A 720p source resolves BOTH the 1080p and the 720p rung to 1280x720; encoding it twice
        // with different quality knobs would ship two identical-sized renditions.
        $kept = keptVariants(1280, 720);

        expect($kept)->toHaveCount(1)
            ->and($kept[0]['width'])->toBe(1280)
            ->and($kept[0]['qsv_global_quality'])->toBe(24);
    });

    it('keeps exactly one rung when the source is below the whole ladder', function () {
        $kept = keptVariants(854, 480);

        expect($kept)->toHaveCount(1)
            ->and($kept[0]['width'])->toBe(1280);
    });

    it('preserves the template order of the surviving variants', function () {
        // Selection walks smallest-first, but the ladder ships in the template author's order.
        expect(collect(keptVariants(3840, 2160))->pluck('width')->all())->toBe([1920, 1280, 3840]);
    });
});
