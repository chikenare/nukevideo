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

/** ffprobe's `packet=pts_time,flags` CSV: one "<pts_time>,<flags>" line per packet. */
function packets(array $lines): string
{
    return implode("\n", array_map(
        fn (array $packet) => sprintf('%.6f,%s', $packet[0], $packet[1] ? 'K__' : '___'),
        $lines
    ))."\n";
}

describe('windowsFromPackets', function () {
    it('ends the last window where the video track does, not where the container does', function () {
        // The bug that failed a 1h52m MKV forever: eac3 ran 55s past the picture, so the container
        // duration asked the last chunk for video that did not exist and the truncation guard
        // rejected a complete encode on every retry.
        $windows = PrepareVideoJob::windowsFromPackets(
            packets([[0.0, true], [100.0, true], [200.0, true], [240.5, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 200.0], [200.0, 241.0]]);
    });

    it('keeps the container duration when the picture runs to the end', function () {
        $windows = PrepareVideoJob::windowsFromPackets(
            packets([[0.0, true], [100.0, true], [200.0, true], [299.8, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 200.0], [200.0, 300.0]]);
    });

    it('reads the tail off the highest pts, not the last line', function () {
        // B-frames arrive out of order.
        $windows = PrepareVideoJob::windowsFromPackets(
            packets([[0.0, true], [100.0, true], [140.2, false], [139.9, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 140.7]]);
    });

    it('falls back to the container duration when the probe reports no timestamps', function () {
        expect(PrepareVideoJob::windowsFromPackets("N/A,K__\nN/A,___\n", 300.0, 100))
            ->toBe([[0.0, 300.0]])
            ->and(PrepareVideoJob::windowsFromPackets('', 300.0, 100))
            ->toBe([[0.0, 300.0]]);
    });

    it('covers the picture with contiguous, non-empty windows', function () {
        $packets = [];
        for ($t = 0.0; $t < 240.0; $t += 4.0) {
            $packets[] = [$t, fmod($t, 20.0) === 0.0];
        }

        $windows = PrepareVideoJob::windowsFromPackets(packets($packets), 300.0, 100);
        $cursor = 0.0;

        foreach ($windows as [$start, $end]) {
            expect($start)->toBe($cursor)->and($end)->toBeGreaterThan($start);
            $cursor = $end;
        }

        expect($cursor)->toBe(236.5);
    });
});
