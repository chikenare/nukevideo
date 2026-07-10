<?php

use App\Models\Stream;
use App\Services\PerTitleCrfService;
use Illuminate\Support\Facades\Process;

describe('chooseCrf', function () {
    it('interpolates the crf that hits the target vmaf', function () {
        // 30 → 97.0, 38 → 93.5: slope -0.4375, target 94 lands at ~36.9
        expect(PerTitleCrfService::chooseCrf([30 => 97.0, 38 => 93.5], 94, 63))->toBe(37);
    });

    it('extrapolates below the base crf when the source needs more bits', function () {
        expect(PerTitleCrfService::chooseCrf([23 => 90.0, 31 => 85.0], 94, 51))->toBe(19);
    });

    it('bounds the swing around the base crf', function () {
        // Interpolation would land far above base+12; clamp wins.
        expect(PerTitleCrfService::chooseCrf([30 => 99.9, 38 => 99.5], 80, 63))->toBe(42);
    });

    it('falls back to the top anchor on a saturated flat curve', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 99.5, 38 => 99.4], 94, 63))->toBe(38);
    });

    it('keeps the base crf on a flat curve that misses the target', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 80.0, 38 => 80.1], 94, 63))->toBe(30);
    });

    it('never exceeds the codec crf ceiling', function () {
        expect(PerTitleCrfService::chooseCrf([48 => 99.0, 51 => 96.0], 90, 51))->toBe(51);
    });
});

describe('sampleWindows', function () {
    it('uses three windows for a short feature and four for a long one', function () {
        expect(PerTitleCrfService::sampleWindows(1500.0))->toHaveCount(3)
            ->and(PerTitleCrfService::sampleWindows(6800.0))->toHaveCount(4);
    });

    it('keeps every window inside the runtime', function () {
        foreach ([130.0, 1500.0, 6800.0] as $duration) {
            foreach (PerTitleCrfService::sampleWindows($duration) as $start) {
                expect($start)->toBeGreaterThanOrEqual(0.0)
                    ->and($start)->toBeLessThanOrEqual($duration - 20);
            }
        }
    });
});

describe('apply guards', function () {
    function perTitleStream(array $inputParams, array $meta = []): Stream
    {
        return (new Stream)->forceFill([
            'id' => 1,
            'type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'input_params' => $inputParams,
            'meta' => $meta,
        ]);
    }

    it('does nothing without target_vmaf', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing in constant bitrate mode', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94, 'constant_bitrate' => '2500k',
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing when a previous attempt already resolved the stream', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94],
            ['per_title' => ['chosen_crf' => 36]],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing for very short videos', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 60.0);

        Process::assertNothingRan();
    });

    it('probes copy-eligible sources too, since video chunks always re-encode', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94, 'maxrate' => '3000k'],
            ['source_codec' => 'av1', 'source_bit_rate' => 2_000_000, 'source_width' => 1920, 'source_height' => 1080],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, 'libvmaf')
            || str_contains($process->command, '-fps_mode passthrough'));
    });

    it('does nothing when the base crf sits at the codec ceiling', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 63, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does not treat a lossless crf of zero as missing', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libx264', 'crf' => 0, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-fps_mode passthrough'));
    });
});
