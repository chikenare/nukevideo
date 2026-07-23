<?php

use App\Models\Stream;
use App\Services\ChunkTranscodeService;
use App\Services\EncodeCommandBuilder;
use Illuminate\Support\Collection;

/** 1080p h264 8-bit 6 Mbps — hardware-decodable, so GPU renditions take the vpp_qsv path. */
const MATRIX_SOURCE = [
    'index' => 0,
    'source_codec' => 'h264',
    'source_pix_fmt' => 'yuv420p',
    'source_width' => 1920,
    'source_height' => 1080,
    'source_bit_rate' => 6_000_000,
    'source_fps' => 23.976,
];

function matrixStream(array $inputParams, string $type = 'video', array $meta = MATRIX_SOURCE): Stream
{
    $stream = (new Stream)->forceFill([
        'type' => $type,
        'width' => $inputParams['width'] ?? 1280,
        'height' => $inputParams['height'] ?? 720,
        'input_params' => $inputParams,
        'meta' => $meta,
    ]);
    $stream->id = 1;

    return $stream;
}

/** Every `-flag` in an argument string, in order. */
function matrixFlags(string $args): array
{
    preg_match_all('/(?:^|\s)(-[A-Za-z][\w:.-]*)/', $args, $matches);

    return $matches[1];
}

/**
 * Flags the codec may legitimately emit: the templates of the params `available_for` it, plus
 * the structural and forced-ABR ones the builder injects itself.
 */
function matrixAllowedFlags(string $codec, string $type): array
{
    $fromConfig = collect(config('ffmpeg.parameters'))
        ->filter(fn ($config) => ($config['type'] ?? null) === $type
            && in_array($codec, $config['available_for'] ?? [], true))
        ->pluck('template')
        ->filter()
        ->map(fn (string $template) => explode(' ', $template)[0])
        ->all();

    $forced = match (true) {
        $type === 'audio' => ['-c:a', '-map', '-vn'],
        $codec === 'libx264' => ['-sc_threshold', '-x264-params', '-threads'],
        $codec === 'libx265' => ['-x265-params'],
        $codec === 'libsvtav1' => ['-svtav1-params'],
        str_ends_with($codec, '_qsv') => ['-adaptive_i', '-mbbrc', '-extbrc', '-look_ahead_depth', '-b:v'],
        str_ends_with($codec, '_nvenc') => ['-no-scenecut', '-forced-idr', '-b:v'],
        default => [],
    };

    return array_merge($fromConfig, $forced, $type === 'audio' ? [] : ['-c:v', '-vf', '-map', '-an']);
}

describe('shipped presets', function () {
    $cases = [];

    // Read straight from the file: datasets are built while Pest collects, before the app boots.
    foreach (require __DIR__.'/../../../config/template-presets.php' as $key => $preset) {
        foreach ($preset['query']['outputs'] as $output) {
            foreach ($output['variants'] as $variant) {
                $label = "{$key} {$output['video_codec']} {$variant['width']}x{$variant['height']}";
                $cases[$label] = [array_merge(['video_codec' => $output['video_codec']], $variant)];
            }
        }
    }

    it('builds a video command with no duplicate or foreign flags', function (array $params) {
        $args = (new ChunkTranscodeService(matrixStream($params)))->buildVideoArguments(windowed: true);
        $flags = matrixFlags($args);

        expect($args)->toContain("-c:v {$params['video_codec']}")
            ->and($args)->toContain('-map ')
            ->and($args)->toContain('-an');

        // A repeated flag means the last one silently wins — the encode is not what the template says.
        expect(array_diff_assoc($flags, array_unique($flags)))->toBe([]);

        expect(array_diff($flags, matrixAllowedFlags($params['video_codec'], 'video')))->toBe([]);
    })->with($cases);

    it('scales on the GPU for accel codecs and in software for CPU codecs', function (array $params) {
        $args = (new ChunkTranscodeService(matrixStream($params)))->buildVideoArguments(windowed: true);

        $accel = ChunkTranscodeService::accelForCodec($params['video_codec']);
        $size = "{$params['width']}:{$params['height']}";

        $accel === 'intel'
            ? expect($args)->toContain("-vf vpp_qsv=w={$params['width']}:h={$params['height']}")->not->toContain('scale=')
            : expect($args)->toContain("-vf scale={$size}")->not->toContain('vpp_qsv');
    })->with($cases);

    it('forces the closed, scene-cut-free GOP every codec needs for ABR alignment', function (array $params) {
        $args = (new ChunkTranscodeService(matrixStream($params)))->buildVideoArguments(windowed: true);

        expect($args)->toContain(match ($params['video_codec']) {
            'libx264' => '-sc_threshold 0 -x264-params open-gop=0',
            'libx265' => '-x265-params scenecut=0:open-gop=0',
            'libsvtav1' => 'scd=0',
            'h264_qsv', 'hevc_qsv' => '-adaptive_i 0 -mbbrc 1',
            'av1_qsv' => '-adaptive_i 0',
            default => '-no-scenecut 1 -forced-idr 1',
        });
    })->with($cases);
});

describe('cross-codec param isolation', function () {
    // One param per codec family, each with a distinct flag, all crammed into one template.
    $foreign = [
        'crf' => 30,                 // libx264/libx265
        'preset' => 'veryslow',      // libx264/libx265
        'video_profile' => 'high',   // libx264
        'pixel_format' => 'yuv444p', // CPU only
        'hevc_tag' => true,          // libx265
        'svtav1_crf' => 40,          // libsvtav1
        'qsv_global_quality' => 24,  // qsv
        'qsv_preset' => 'slow',      // qsv
        'nvenc_cq' => 30,            // nvenc
        'nvenc_preset' => 'p5',      // nvenc
    ];

    it('renders only the flags the target codec declares', function (string $codec) use ($foreign) {
        $args = (new ChunkTranscodeService(matrixStream(
            array_merge(['video_codec' => $codec, 'gop_size' => 60], $foreign),
        )))->buildVideoArguments(windowed: true);

        $flags = matrixFlags($args);

        expect(array_diff($flags, matrixAllowedFlags($codec, 'video')))->toBe([]);
        expect(array_diff_assoc($flags, array_unique($flags)))->toBe([]);
        expect($args)->toContain('-g 60');
    })->with(['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc']);

    it('does not let a qsv preset override the CPU preset', function () {
        $args = (new ChunkTranscodeService(matrixStream([
            'video_codec' => 'libx264',
            'crf' => 23,
            'preset' => 'medium',
            'qsv_preset' => 'veryslow',
        ])))->buildVideoArguments(windowed: true);

        expect($args)->toContain('-preset medium')->not->toContain('veryslow');
    });

    it('does not leak a CRF into a hardware encoder', function (string $codec) {
        $args = (new ChunkTranscodeService(matrixStream([
            'video_codec' => $codec,
            'qsv_global_quality' => 24,
            'crf' => 30,
            'svtav1_crf' => 40,
        ])))->buildVideoArguments(windowed: true);

        expect($args)->toContain('-global_quality 24')->not->toContain('-crf');
    })->with(['h264_qsv', 'hevc_qsv', 'av1_qsv']);
});

describe('audio', function () {
    it('renders each audio codec with only its own params', function (string $codec, string $expected) {
        $args = (new ChunkTranscodeService(matrixStream([
            'audio_codec' => $codec,
            'channels' => '2',
            'audio_bitrate' => '160k',
            'sample_rate' => '48000',
            'audio_vbr' => '4',        // aac
            'audio_profile' => 'aac_low', // aac + libfdk_aac
            'audio_vbr_fdk' => '3',    // libfdk_aac -> -vbr
            'cutoff' => 18000,         // libfdk_aac
            'opus_vbr' => 'on',        // libopus -> -vbr, collides with audio_vbr_fdk
            'opus_application' => 'audio',
        ], type: 'audio', meta: ['index' => 1])))->buildAudioArguments();

        $flags = matrixFlags($args);

        expect($args)->toContain("-c:a {$codec}")
            ->and($args)->toContain($expected)
            ->and($args)->toContain('-ac 2')
            ->and($args)->toContain('-b:a 160k')
            ->and($args)->toContain('-vn');

        // -vbr is claimed by both libfdk_aac and libopus; only one codec may render it.
        expect(array_diff_assoc($flags, array_unique($flags)))->toBe([]);
        expect(array_diff($flags, matrixAllowedFlags($codec, 'audio')))->toBe([]);
    })->with([
        ['aac', '-q:a 4'],
        ['libfdk_aac', '-cutoff 18000'],
        ['libopus', '-application audio'],
    ]);

    it('falls back to a light AAC encode when the template pins no codec', function () {
        $args = (new ChunkTranscodeService(matrixStream([], type: 'audio', meta: ['index' => 1])))->buildAudioArguments();

        expect($args)->toBe('-c:a aac -b:a 128k -map 0:1 -vn');
    });

    it('maps each track by its absolute index so multi-track sources do not collapse', function () {
        $stream = matrixStream(['audio_codec' => 'aac', 'channels' => '2'], type: 'audio', meta: ['index' => 3]);

        expect((new ChunkTranscodeService($stream))->buildAudioArguments())->toContain('-map 0:3');
    });
});

describe('command assembly', function () {
    it('sets hwaccel only on a single-rendition GPU video command', function () {
        $stream = matrixStream(['video_codec' => 'av1_qsv', 'qsv_global_quality' => 24]);

        $command = EncodeCommandBuilder::build(new Collection([$stream]), 'src.mkv', [1 => 'out.part'], 0.0, 10.0);

        expect($command)
            ->toContain('-hwaccel qsv -hwaccel_output_format qsv -ss 0.0000 -to 10.0000 -i "src.mkv"')
            ->toContain('vpp_qsv');
    });

    it('caps decoder threads instead of hwaccel when the source is not hardware-decodable', function () {
        $stream = matrixStream(
            ['video_codec' => 'av1_qsv', 'qsv_global_quality' => 24],
            meta: ['index' => 0, 'source_codec' => 'prores', 'source_pix_fmt' => 'yuv422p10le'],
        );

        $command = EncodeCommandBuilder::build(new Collection([$stream]), 'src.mkv', [1 => 'out.part'], 0.0, 10.0);

        // Software decode: swscale, and the 10-bit AV1 target reached with an explicit format filter.
        expect($command)
            ->not->toContain('-hwaccel')
            ->toContain('-threads')
            ->toContain('-vf scale=1280:720,format=p010le');
    });

    it('builds one sidecar pass for the audio and subtitle tracks', function () {
        $audio = matrixStream(['audio_codec' => 'libopus', 'channels' => '2', 'audio_bitrate' => '128k'], type: 'audio', meta: ['index' => 1]);
        $subtitle = matrixStream([], type: 'subtitle', meta: ['index' => 2]);
        $subtitle->id = 2;

        $command = EncodeCommandBuilder::build(
            new Collection([$audio, $subtitle]),
            'src.mkv',
            [1 => 'a.mp4', 2 => 's.vtt'],
        );

        expect($command)
            ->toStartWith('ffmpeg -hide_banner -y -i "src.mkv"')
            ->toContain('-c:a libopus -ac 2 -b:a 128k -map 0:1 -vn -movflags +faststart -f mp4 "a.mp4"')
            ->toContain('-map 0:2 -c:s webvtt -f webvtt "s.vtt"')
            // Sidecars run whole-source, never windowed, and never take decode-side flags.
            ->not->toContain('-ss ')
            ->not->toContain('-hwaccel');
    });

    it('packages every codec into the container its config declares', function (string $codec, string $format) {
        expect(ChunkTranscodeService::formatForCodec($codec))->toBe($format);
    })->with([
        ['libx264', 'mp4'], ['libx265', 'mp4'], ['libsvtav1', 'mp4'],
        ['av1_qsv', 'mp4'], ['libopus', 'mp4'], ['aac', 'mp4'],
    ]);
});
