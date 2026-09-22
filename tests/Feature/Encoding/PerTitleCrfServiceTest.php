<?php

use App\Models\Stream;
use App\Services\PerTitleCrfService;
use App\Services\SampleEncode;
use Illuminate\Support\Facades\Process;

describe('chooseCrf', function () {
    it('interpolates the crf that hits the target vmaf', function () {
        // 30 → 97.0, 38 → 93.5: slope -0.4375, target 94 lands at ~36.9
        expect(PerTitleCrfService::chooseCrf([30 => 97.0, 38 => 93.5], 94, 63))->toBe(37);
    });

    it('extrapolates below the base crf when the source needs more bits', function () {
        expect(PerTitleCrfService::chooseCrf([23 => 90.0, 31 => 85.0], 94, 51))->toBe(19);
    });

    it('never extrapolates upward past the top anchor', function () {
        // Interpolation would land far above the anchors; only what was measured counts.
        expect(PerTitleCrfService::chooseCrf([30 => 99.9, 38 => 99.5], 80, 63))->toBe(38)
            // A barely sloped curve extrapolated 26 → 38 before; the top anchor is the limit.
            ->and(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 93.7], 93, 63))->toBe(34);
    });

    it('bounds the downward swing around the base crf', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 80.0, 38 => 70.0], 94, 63))->toBe(26);
    });

    it('falls back to the top anchor on a saturated flat curve', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 99.5, 38 => 99.4], 94, 63))->toBe(38);
    });

    it('keeps the base crf on a flat curve sitting right at the target', function () {
        // Video 6275: a starved VBV scored both anchors alike and the probe jumped to base+8.
        expect(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 94.19], 94, 63))->toBe(26)
            ->and(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 94.19], 92, 63))->toBe(26);
    });

    it('keeps the base crf on a flat curve that misses the target', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 80.0, 38 => 80.1], 94, 63))->toBe(30);
    });

    it('never exceeds the codec crf ceiling', function () {
        expect(PerTitleCrfService::chooseCrf([48 => 99.0, 51 => 96.0], 90, 51))->toBe(51);
    });
});

describe('sample windows', function () {
    it('uses three windows for a short feature and four for a long one', function () {
        expect(SampleEncode::windows(1500.0))->toHaveCount(3)
            ->and(SampleEncode::windows(6800.0))->toHaveCount(4);
    });

    it('keeps every window inside the runtime', function () {
        foreach ([130.0, 1500.0, 6800.0] as $duration) {
            foreach (SampleEncode::windows($duration) as $start) {
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

    it('does nothing for a codec outside target_vmaf, even when a legacy template carries the field', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'av1_qsv', 'qsv_global_quality' => 22, 'target_vmaf' => 94],
            ['source_codec' => 'h264', 'source_pix_fmt' => 'yuv420p', 'source_width' => 1920, 'source_height' => 800],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing when the base crf sits at the codec ceiling', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 63, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('measures the anchors against the template VBV, not the source-tightened one', function () {
        Process::fake();

        // Video 6275's shape: a 1.8 Mbps source under a 7000k/14000k SVT-AV1 template.
        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 26, 'target_vmaf' => 94, 'maxrate' => '7000k', 'bufsize' => '14000k'],
            ['source_codec' => 'h264', 'source_bit_rate' => 1_807_276, 'source_width' => 1920, 'source_height' => 1080],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-fps_mode passthrough')
            && str_contains($process->command, '-maxrate 7000k')
            && str_contains($process->command, '-bufsize 14000k'));
        Process::assertDidntRun(fn ($process) => str_contains($process->command, '-fps_mode passthrough')
            && ! str_contains($process->command, '-maxrate 7000k'));
    });

    it('does not treat a lossless crf of zero as missing', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libx264', 'crf' => 0, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-fps_mode passthrough'));
    });
});
