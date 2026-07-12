<?php

use App\Jobs\PrepareVideoJob;

// Reference: a 1080p source encoded to a 1080p rendition at 30fps holds the full 120s window.
const HD = 1920 * 1080;
const UHD = 3840 * 2160;
const UHD8K = 7680 * 4320;

describe('chunkWindowSeconds', function () {
    it('holds the reference window at 1080p30', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(HD, HD, 30.0))->toBe(120);
    });

    it('lengthens the window at lower frame rates', function () {
        // 120 * (30/24) = 150
        expect(PrepareVideoJob::chunkWindowSeconds(HD, HD, 24.0))->toBe(150);
    });

    it('shrinks the window for 4K so a single chunk still fits the timeout', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(UHD, UHD, 30.0))->toBe(30);
    });

    it('shrinks the window for a heavy source even when the top rendition is only 1080p', function () {
        // Every chunk job decodes a window of the source, so a 4K/8K master shortens the window on
        // its own — a 1080p-capped template used to get the full 120s here and blow the chunk timeout.
        expect(PrepareVideoJob::chunkWindowSeconds(HD, UHD, 30.0))->toBe(75)
            ->and(PrepareVideoJob::chunkWindowSeconds(HD, UHD8K, 30.0))->toBe(30);
    });

    it('floors 8K at the minimum window regardless of frame rate', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(UHD8K, UHD8K, 30.0))->toBe(8)
            ->and(PrepareVideoJob::chunkWindowSeconds(UHD8K, UHD8K, 60.0))->toBe(8);
    });

    it('caps the window for low-resolution renditions so chunks still parallelize', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(854 * 480, 854 * 480, 30.0))->toBe(300);
    });

    it('assumes the source matches its heaviest rendition when its dimensions were never recorded', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(HD, 0, 30.0))->toBe(120)
            ->and(PrepareVideoJob::chunkWindowSeconds(UHD, 0, 30.0))->toBe(30);
    });

    it('falls back to the reference window when nothing is known', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(0, 0, 30.0))->toBe(120);
    });

    it('ignores an unreadable or VFR-inflated frame rate', function () {
        expect(PrepareVideoJob::chunkWindowSeconds(HD, HD, 0.0))->toBe(120)
            ->and(PrepareVideoJob::chunkWindowSeconds(HD, HD, 1000.0))->toBe(120);
    });

    it('stays within the clamp and shrinks monotonically as pixels climb', function () {
        $prev = PHP_INT_MAX;

        foreach ([854 * 480, 1280 * 720, HD, 2560 * 1440, UHD, UHD8K] as $pixels) {
            $window = PrepareVideoJob::chunkWindowSeconds($pixels, $pixels, 30.0);

            expect($window)->toBeGreaterThanOrEqual(8)
                ->and($window)->toBeLessThanOrEqual(300)
                ->and($window)->toBeLessThanOrEqual($prev);

            $prev = $window;
        }
    });
});
