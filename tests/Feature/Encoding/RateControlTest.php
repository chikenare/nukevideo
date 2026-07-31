<?php

/**
 * Rate control is resolved in exactly one place ({@see App\Services\Concerns\ResolvesRateControl}),
 * so this covers every mode it can pick, per codec: quality mode capped by the encoder's own VBV,
 * quality mode on an encoder blind to one, QSV's QVBR steering, and pinned ABR — each asserted on
 * the command that actually reaches ffmpeg.
 */

use App\Services\ChunkTranscodeService;
use App\Services\QualityBitrateProbe;
use Illuminate\Support\Facades\Process;

function rateArgs(array $params, array $meta = LIGHT_SOURCE, int $width = 1920, int $height = 1080): string
{
    return (new ChunkTranscodeService(matrixStream($params, meta: $meta, width: $width, height: $height)))
        ->buildVideoArguments(windowed: true);
}

describe('uncapped quality templates', function () {
    it('adds no ceiling of its own to a template that asked for none', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');

        // nvenc's CQ needs its bitrate target zeroed; nothing else may pin an average.
        expect($args)->when(! str_ends_with($codec, '_nvenc'), fn ($args) => $args->not->toContain('-b:v'));
    })->with(array_keys(QUALITY_KNOBS));

    it('keeps av1_qsv in ICQ while the quality mode fits under the source', function (int $measured) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => $measured]);

        expect($args)->toContain('-global_quality 22')
            ->toContain('-extbrc 1 -look_ahead_depth 16')
            ->not->toContain('-maxrate')
            ->not->toContain('-b:v');
    })->with([
        'well under the cap' => 700_000,
        'just under it' => 1_263_598,
        // Sample windows are the busy middle of a runtime, so a small overshoot is tolerated.
        'inside the tolerance' => 1_500_000,
    ]);
});

describe('av1_qsv, blind to a VBV', function () {
    it('falls back to capped VBR once the quality mode really outgrows the source', function () {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497]);

        expect($args)->toContain('-b:v 948k')
            ->toContain('-maxrate 1264k')
            ->toContain('-bufsize 2527k')
            // A quality knob next to a pinned -b:v fails the encode outright on QSV, and the AV1
            // runtime rejects the BRC extensions under VBR (it writes a 0-byte file).
            ->not->toContain('-global_quality')
            ->not->toContain('-extbrc')
            ->not->toContain('-look_ahead_depth');
    });

    it('scales the ceiling to each rendition of the same ladder', function (int $width, int $height, string $expected) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497], $width, $height);

        expect($args)->toContain($expected);
    })->with([
        '1080p' => [1920, 1080, '-b:v 948k -maxrate 1264k'],
        '720p' => [1280, 720, '-b:v 516k -maxrate 688k'],
        '480p' => [854, 480, '-b:v 281k -maxrate 375k'],
    ]);

    it('strips a template VBV instead of capping with it, since -maxrate would select CQP', function () {
        $args = rateArgs(qualityTemplate('av1_qsv', ['maxrate' => '4000k', 'bufsize' => '8000k']));

        expect($args)->toContain('-global_quality 22')
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');
    });

    it('keeps a pinned ABR template, dropping only the knob the runtime refuses next to it', function () {
        $args = rateArgs(qualityTemplate('av1_qsv', [
            'constant_bitrate' => '2500k', 'maxrate' => '3000k', 'bufsize' => '6000k',
        ]), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497]);

        expect($args)->toContain('-b:v 2500k')
            ->toContain('-maxrate 3000k')
            ->toContain('-bufsize 6000k')
            // ICQ next to a pinned -b:v is "Invalid argument"; the BRC extensions are VBR-only.
            ->not->toContain('-global_quality')
            ->not->toContain('-extbrc');
    });

    it('stays in ICQ when the source gives nothing to cap against', function (array $meta) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...$meta, 'quality_bitrate' => 3_176_497]);

        expect($args)->toContain('-global_quality 22')->not->toContain('-maxrate');
    })->with([
        'no source bitrate' => [['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080]],
        'no source resolution' => [['source_codec' => 'h264', 'source_bit_rate' => 1_263_599]],
        'a bitrate too low to be real' => [[...LIGHT_SOURCE, 'source_bit_rate' => 40_000]],
    ]);
});

describe('encoders that cap themselves', function () {
    it('tightens a template VBV to the source ceiling', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, ['maxrate' => '4000k', 'bufsize' => '8000k']));

        expect($args)->toContain('-maxrate 1264k')->toContain('-bufsize 2527k');
    })->with(['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'av1_nvenc']);

    it('scales that ceiling sublinearly for a downscaled rendition', function () {
        // (1280*720 / 1920*1080)^0.75 ≈ 0.544
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '4000k', 'bufsize' => '8000k']), width: 1280, height: 720))
            ->toContain('-maxrate 688k')->toContain('-bufsize 1376k');
    });

    it('keeps a strict VBV strict while tightening it', function () {
        expect(rateArgs(qualityTemplate('libx264', ['maxrate' => '4000k', 'bufsize' => '4000k'])))
            ->toContain('-maxrate 1264k')->toContain('-bufsize 1264k');
    });

    it('leaves a template already under the source ceiling alone', function () {
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '800k', 'bufsize' => '1600k'])))
            ->toContain('-maxrate 800k')->toContain('-bufsize 1600k');
    });

    it('parses an M-suffixed template VBV when tightening it', function () {
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '4M', 'bufsize' => '8M'])))
            ->toContain('-maxrate 1264k')->toContain('-bufsize 2527k');
    });

    it('ignores a bogus sub-100k source bitrate instead of emitting a 0k ceiling', function () {
        $args = rateArgs(
            qualityTemplate('libx264', ['maxrate' => '4000k', 'bufsize' => '8000k']),
            [...LIGHT_SOURCE, 'source_bit_rate' => 800],
        );

        expect($args)->toContain('-maxrate 4000k')->not->toContain('-maxrate 0k');
    });

    it('never tightens against a source more efficient than the target codec', function () {
        $args = rateArgs(
            qualityTemplate('libx264', ['maxrate' => '4000k', 'bufsize' => '8000k']),
            [...LIGHT_SOURCE, 'source_codec' => 'av1'],
        );

        expect($args)->toContain('-maxrate 4000k')->toContain('-bufsize 8000k');
    });

    it('steers a capped QSV quality mode into QVBR with an average below the cap', function (string $codec, string $expected) {
        $args = rateArgs(qualityTemplate($codec, ['maxrate' => '4000k', 'bufsize' => '8000k']));

        expect($args)->toContain('-maxrate 1264k')
            ->toContain($expected)
            ->toContain(QUALITY_KNOBS[$codec]['flag'])
            // QVBR is a bitrate mode: the AV1-only BRC extensions have no place in it.
            ->toContain('-mbbrc 1');
    })->with([
        ['h264_qsv', '-b:v 948k'],
        ['hevc_qsv', '-b:v 948k'],
    ]);

    it('does not invent a QVBR average for an uncapped QSV template', function () {
        expect(rateArgs(qualityTemplate('h264_qsv')))->toContain('-global_quality 23')->not->toContain('-b:v');
    });
});

describe('ABR templates the encoder would refuse', function () {
    it('drops the CRF and the VBV for SVT-AV1, which takes a max bitrate in CRF mode only', function () {
        $args = rateArgs(qualityTemplate('libsvtav1', [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        // "Target Bitrate only supported when --rc is 1/2" / "Max Bitrate only supported with CRF
        // mode" — either aborts the encode with nothing written.
        expect($args)->toContain('-b:v 1500k')
            ->not->toContain('-crf')
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');
    });

    it('keeps the QSV quality knob in ABR for the codecs where that pairing is QVBR', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->toContain('-b:v 1500k')
            ->toContain('-maxrate 2000k');
    })->with(['h264_qsv', 'hevc_qsv']);

    it('keeps the nvenc quality target in ABR, where it reads as VBR quality', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->toContain('-b:v 1500k')
            // -b:v 0 exists to free CQ from nvenc's default target; a pinned average replaces it.
            ->not->toContain('-b:v 0');
    })->with(['h264_nvenc', 'av1_nvenc']);

    it('leaves the x264/x265 pairing alone, where the encoder resolves it without erroring', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, ['constant_bitrate' => '1500k', 'maxrate' => '2000k']));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])->toContain('-b:v 1500k');
    })->with(['libx264', 'libx265']);
});

describe('every resolved mode is a well-formed command', function () {
    it('emits no duplicate or foreign flag in any rate-control mode', function (array $params, array $meta) {
        $args = rateArgs($params, $meta);
        $flags = matrixFlags($args);

        expect(array_diff_assoc($flags, array_unique($flags)))->toBe([]);
        expect(array_diff($flags, matrixAllowedFlags($params['video_codec'], 'video')))->toBe([]);
    })->with(function () {
        $cases = [];

        foreach (array_keys(QUALITY_KNOBS) as $codec) {
            $cases["{$codec} quality"] = [qualityTemplate($codec), LIGHT_SOURCE];
            $cases["{$codec} capped"] = [qualityTemplate($codec, ['maxrate' => '4000k', 'bufsize' => '8000k']), LIGHT_SOURCE];
            $cases["{$codec} abr"] = [
                qualityTemplate($codec, ['constant_bitrate' => '2500k', 'maxrate' => '3000k', 'bufsize' => '6000k']),
                LIGHT_SOURCE,
            ];
            $cases["{$codec} measured overshoot"] = [
                qualityTemplate($codec),
                [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497],
            ];
        }

        return $cases;
    });
});

describe('the probe that feeds the ceiling', function () {
    beforeEach(fn () => config(['ffmpeg.node_accel' => 'intel']));

    it('never encodes a sample on a node without the encoder hardware', function (?string $nodeAccel) {
        Process::fake();
        config(['ffmpeg.node_accel' => $nodeAccel]);

        $stream = matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        (new QualityBitrateProbe($stream))->measure('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
        expect($stream->meta)->not->toHaveKey('quality_bitrate');
    })->with(['CPU-only node' => null, 'the other GPU family' => 'nvidia']);

    it('runs the real chunk command, hardware path included', function () {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-hwaccel qsv -hwaccel_output_format qsv')
            && str_contains($process->command, '-c:v av1_qsv')
            && str_contains($process->command, '-global_quality 22')
            && str_contains($process->command, '-fps_mode passthrough'));
    });

    it('never runs where the encoder caps itself', function (string $codec) {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate($codec), meta: LIGHT_SOURCE, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    })->with(['libsvtav1', 'libx264', 'h264_qsv', 'av1_nvenc']);

    it('never runs twice for the same stream, nor on a clip too short to sample', function (array $meta, float $duration) {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate('av1_qsv'), meta: $meta, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', $duration);

        Process::assertNothingRan();
    })->with([
        'already measured' => [[...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497], 6800.0],
        'too short' => [LIGHT_SOURCE, 60.0],
    ]);

    it('leaves the stream unmeasured when every sample fails, which reads as uncapped ICQ', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $stream = matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        (new QualityBitrateProbe($stream))->measure('/tmp/src.mkv', 6800.0);

        expect($stream->meta)->not->toHaveKey('quality_bitrate')
            ->and((new ChunkTranscodeService($stream))->buildVideoArguments(windowed: true))
            ->toContain('-global_quality 22')->not->toContain('-maxrate');
    });
});

describe('needsQualityBitrateProbe', function () {
    it('asks for a measurement only where the encoder honours no VBV', function (string $codec, bool $expected) {
        $stream = matrixStream(qualityTemplate($codec), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->needsQualityBitrateProbe())->toBe($expected);
    })->with([
        ['av1_qsv', true],
        ['libsvtav1', false],
        ['libx264', false],
        ['h264_qsv', false],
        ['av1_nvenc', false],
    ]);

    it('does not ask for one when there is no ceiling to compare against, or no quality mode', function (array $params, array $meta) {
        $stream = matrixStream($params, meta: $meta, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->needsQualityBitrateProbe())->toBeFalse();
    })->with([
        'pinned ABR' => [['video_codec' => 'av1_qsv', 'constant_bitrate' => '2500k'], LIGHT_SOURCE],
        'unknown source bitrate' => [['video_codec' => 'av1_qsv', 'qsv_global_quality' => 22], ['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080]],
    ]);
});
