<?php

/**
 * A GOP pinned in frames only means something against a frame rate, but a template is written once
 * and applied to whatever gets uploaded. Deriving it keeps the keyframe interval in seconds — and
 * therefore a whole number of GOPs per CMAF segment, which is the only form the packager can cut on.
 */

use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

function derivedGop(string $avgFrameRate): ?int
{
    $service = new CreateVideoStreamsService;
    $source = new FFStream(['avg_frame_rate' => $avgFrameRate]);

    return (fn () => $this->deriveGopSize($this->sourceFrameRate($source)))->call($service);
}

/**
 * How far past the configured target the packager's segment lands. It closes a segment on the first
 * keyframe at or after the target, so the real length is always the next whole GOP up, never short
 * — the 10.01s segments in a shipped manifest are exactly this.
 */
function segmentDrift(int $gop, float $fps): float
{
    $target = (float) config('packager.segment_duration');
    $gopSeconds = $gop / $fps;

    return round(ceil(round($target / $gopSeconds, 6)) * $gopSeconds - $target, 4);
}

describe('deriveGopSize', function () {
    beforeEach(fn () => config(['packager.segment_duration' => 10]));

    it('keeps the packager within a frame of the segment length it was configured for', function (string $rate, float $fps, int $expected) {
        // Exactness is not on offer at 24000/1001 — no integer frame count divides 10s. Within one
        // frame is, and one frame is the whole budget a keyframe cut has to play with.
        expect(derivedGop($rate))->toBe($expected)
            ->and(segmentDrift($expected, $fps))->toBeLessThanOrEqual(1 / $fps);
    })->with([
        'film, 23.976' => ['24000/1001', 23.976, 48],
        'PAL broadcast, 25' => ['25/1', 25.0, 50],
        'cinema, 24' => ['24/1', 24.0, 48],
        'NTSC, 29.97' => ['30000/1001', 29.97, 60],
        'high frame rate, 60' => ['60/1', 60.0, 120],
    ]);

    it('earns its keep on the rates a pinned 60 cannot divide', function () {
        // 25fps is where a frame-pinned GOP actually costs something: 60 frames is 2.4s, and five
        // of those overshoot a 10s segment by two whole seconds. At 23.976 both land on 10.01s —
        // for most of the catalog this changes the number without changing the outcome.
        expect(segmentDrift(60, 25.0))->toBe(2.0)
            ->and(segmentDrift(derivedGop('25/1'), 25.0))->toBe(0.0)
            ->and(segmentDrift(60, 23.976))->toBe(segmentDrift(derivedGop('24000/1001'), 23.976));
    });

    it('follows the configured segment length rather than assuming ten seconds', function (int $segment, int $expected) {
        config(['packager.segment_duration' => $segment]);

        expect(derivedGop('24/1'))->toBe($expected)
            ->and(segmentDrift($expected, 24.0))->toBe(0.0);
    })->with(['6s segments' => [6, 48], '4s segments' => [4, 48], '2s segments' => [2, 48]]);

    it('leaves the encoder its own default when the rate is unreadable', function (string $rate) {
        expect(derivedGop($rate))->toBeNull();
    })->with(['no rate' => '0/0', 'malformed' => 'N/A']);
});

describe('what the variant ends up with', function () {
    beforeEach(fn () => config(['packager.segment_duration' => 10]));

    it('never overrides a GOP the template pinned', function () {
        $service = new CreateVideoStreamsService;
        $source = new FFStream(['avg_frame_rate' => '25/1', 'width' => 1920, 'height' => 1080]);
        $variants = [['width' => 1920, 'height' => 1080, 'gop_size' => 60]];

        $resolved = (function () use ($source, $variants) {
            $config = $this->filterVariants($source, $variants)[0];
            $derived = $this->deriveGopSize($this->sourceFrameRate($source));

            return empty($config['gop_size']) && $derived ? [...$config, 'gop_size' => $derived] : $config;
        })->call($service);

        expect($resolved['gop_size'])->toBe(60);
    });
});
