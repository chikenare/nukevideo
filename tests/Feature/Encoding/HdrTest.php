<?php

/**
 * An HDR master (PQ or HLG, 10-bit) either stays HDR or is tone-mapped to SDR, per output and
 * never per rung. Before this, nothing looked at the source's transfer function: an H.264 rung of
 * an HDR10 master came out 8-bit with the PQ tags still on it, and packaging advertised it as
 * `VIDEO-RANGE=PQ` — washed out on every SDR screen, banded on HDR ones.
 */

use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use App\Services\EncodeCommandBuilder;
use App\Services\PerTitleCrfService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

/** A 4K HDR10 master as ffprobe describes it. */
const HDR10_SOURCE = [
    'index' => 0,
    'source_codec' => 'hevc',
    'source_pix_fmt' => 'yuv420p10le',
    'source_width' => 3840,
    'source_height' => 2160,
    'source_bit_rate' => 40_000_000,
    'source_fps' => 23.976,
    'source_color_transfer' => 'smpte2084',
    'source_color_primaries' => 'bt2020',
    'source_color_space' => 'bt2020nc',
];

function hdrProbe(array $extra = []): FFStream
{
    return new FFStream([
        'codec_type' => 'video',
        'width' => 3840,
        'height' => 2160,
        'pix_fmt' => 'yuv420p10le',
        'color_transfer' => 'smpte2084',
        ...$extra,
    ]);
}

function outputTonesMap(FFStream $source, array $ladder): bool
{
    return (fn () => $this->tonesMapOutput($source, $ladder))->call(new CreateVideoStreamsService);
}

describe('which renditions can carry HDR', function () {
    it('takes 10-bit HEVC or AV1, on the CPU or the GPU', function (array $params) {
        expect(ChunkTranscodeService::carriesHdr($params, 'yuv420p10le'))->toBeTrue();
    })->with([
        'x265 following the source' => [['video_codec' => 'libx265']],
        'x265 main10' => [['video_codec' => 'libx265', 'x265_profile' => 'main10', 'pixel_format' => 'yuv420p10le']],
        'svt-av1' => [['video_codec' => 'libsvtav1']],
        'hevc qsv' => [['video_codec' => 'hevc_qsv']],
        'av1 nvenc' => [['video_codec' => 'av1_nvenc']],
    ]);

    it('refuses H.264 and anything pinned to 8 bits', function (array $params) {
        expect(ChunkTranscodeService::carriesHdr($params, 'yuv420p10le'))->toBeFalse();
    })->with([
        'x264' => [['video_codec' => 'libx264']],
        'h264 nvenc' => [['video_codec' => 'h264_nvenc']],
        'x265 at yuv420p' => [['video_codec' => 'libx265', 'pixel_format' => 'yuv420p']],
        'x265 main' => [['video_codec' => 'libx265', 'x265_profile' => 'main']],
        'svt-av1 at yuv420p' => [['video_codec' => 'libsvtav1', 'pixel_format' => 'yuv420p']],
    ]);

    it('counts a 4:2:2 master as 10-bit, and an 8-bit one as already SDR-deep', function () {
        expect(ChunkTranscodeService::carriesHdr(['video_codec' => 'libx265'], 'yuv422p10le'))->toBeTrue()
            ->and(ChunkTranscodeService::carriesHdr(['video_codec' => 'libx265'], 'yuv420p'))->toBeFalse();
    });
});

describe('the decision per output', function () {
    it('keeps an all-10-bit HEVC ladder HDR', function () {
        expect(outputTonesMap(hdrProbe(), [
            ['video_codec' => 'libx265', 'width' => 3840, 'height' => 2160],
            ['video_codec' => 'libx265', 'width' => 1920, 'height' => 1080],
        ]))->toBeFalse();
    });

    it('tone-maps the whole ladder when a single rung cannot carry HDR', function () {
        // The old 4K preset: main10 at the top, 8-bit main below — a ladder that switched dynamic
        // range on every quality change.
        expect(outputTonesMap(hdrProbe(), [
            ['video_codec' => 'libx265', 'width' => 3840, 'height' => 2160, 'pixel_format' => 'yuv420p10le'],
            ['video_codec' => 'libx265', 'width' => 1920, 'height' => 1080, 'pixel_format' => 'yuv420p'],
        ]))->toBeTrue();
    });

    it('tone-maps H.264 from HLG as from PQ', function () {
        expect(outputTonesMap(hdrProbe(['color_transfer' => 'arib-std-b67']), [['video_codec' => 'libx264']]))->toBeTrue();
    });

    it('leaves an SDR source alone, whatever the ladder', function () {
        expect(outputTonesMap(hdrProbe(['color_transfer' => 'bt709', 'pix_fmt' => 'yuv420p']), [['video_codec' => 'libx264']]))->toBeFalse()
            ->and(outputTonesMap(hdrProbe(['color_transfer' => null]), [['video_codec' => 'libx264']]))->toBeFalse();
    });

    it('keeps the stock 4K preset HDR end to end', function () {
        $variants = config('template-presets.hls-hevc-4k.query.outputs.0.variants');
        $ladder = array_map(fn (array $variant) => ['video_codec' => 'libx265', ...$variant], $variants);

        expect(outputTonesMap(hdrProbe(), $ladder))->toBeFalse();
    });
});

describe('encode arguments', function () {
    it('tone-maps after the scale, ends 8-bit 4:2:0 and tags BT.709', function () {
        $args = (new ChunkTranscodeService(matrixStream(
            ['video_codec' => 'libx264', 'crf' => 23, 'width' => 1920, 'height' => 1080],
            meta: [...HDR10_SOURCE, 'tone_map' => true],
        )))->buildVideoArguments(windowed: true);

        expect($args)
            ->toContain('-vf scale=1920:1080,zscale=t=linear:npl=100,')
            ->toContain('tonemap=tonemap=hable')
            ->toContain('sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA')
            ->toContain(',format=yuv420p ')
            ->toContain('-pix_fmt yuv420p')
            ->toContain('-color_primaries bt709 -color_trc bt709 -colorspace bt709')
            ->not->toContain('smpte2084');
    });

    it('decodes in software when a GPU rendition tone-maps, ending at its encoder format', function () {
        $svc = new ChunkTranscodeService(matrixStream(
            ['video_codec' => 'h264_nvenc', 'nvenc_cq' => 24, 'width' => 1920, 'height' => 1080],
            meta: [...HDR10_SOURCE, 'tone_map' => true],
        ));

        expect($svc->inputArguments(windowed: true))->not->toContain('-hwaccel')
            ->and($svc->buildVideoArguments(windowed: true))
            ->toContain('tonemap=tonemap=hable')
            ->toContain(',format=nv12')
            ->not->toContain('scale_cuda');
    });

    it('keeps an HDR rendition 10-bit and writes the source colour description back', function () {
        $args = (new ChunkTranscodeService(matrixStream(
            ['video_codec' => 'libx265', 'crf' => 22, 'width' => 1920, 'height' => 1080],
            meta: [...HDR10_SOURCE, 'tone_map' => false],
        )))->buildVideoArguments(windowed: true);

        expect($args)
            ->toContain('-pix_fmt yuv420p10le')
            ->toContain('-color_primaries bt2020 -color_trc smpte2084 -colorspace bt2020nc')
            ->not->toContain('zscale');
    });

    it('stays on the GPU for a kept HDR rendition, tagged all the same', function () {
        $svc = new ChunkTranscodeService(matrixStream(
            ['video_codec' => 'hevc_nvenc', 'nvenc_cq' => 24, 'width' => 1920, 'height' => 1080],
            meta: [...HDR10_SOURCE, 'tone_map' => false],
        ));

        expect($svc->inputArguments(windowed: true))->toContain('-hwaccel cuda')
            ->and($svc->buildVideoArguments(windowed: true))
            ->toContain('scale_cuda=1920:1080:format=p010le')
            ->toContain('-color_trc smpte2084');
    });

    it('writes no colour flags for an SDR source', function () {
        $args = (new ChunkTranscodeService(matrixStream(['video_codec' => 'libx264', 'crf' => 23])))
            ->buildVideoArguments(windowed: true);

        expect($args)->not->toContain('-color_')->not->toContain('-colorspace');
    });
});

describe('pixel format of a CPU rendition', function () {
    it('brings a 4:2:2 master down to 4:2:0 at a depth browsers decode', function (string $codec, string $format) {
        $args = (new ChunkTranscodeService(matrixStream(
            ['video_codec' => $codec],
            meta: [...MATRIX_SOURCE, 'source_codec' => 'prores', 'source_pix_fmt' => 'yuv422p10le'],
        )))->buildVideoArguments(windowed: true);

        expect($args)->toContain("-pix_fmt {$format}")->not->toContain('yuv422');
    })->with([
        'x264 is 8-bit' => ['libx264', 'yuv420p'],
        'x265 keeps 10 bits' => ['libx265', 'yuv420p10le'],
        'svt-av1 keeps 10 bits' => ['libsvtav1', 'yuv420p10le'],
    ]);

    it('lets the template pin its own, once', function () {
        $args = (new ChunkTranscodeService(matrixStream(['video_codec' => 'libx265', 'pixel_format' => 'yuv420p'])))
            ->buildVideoArguments(windowed: true);

        expect(substr_count($args, '-pix_fmt'))->toBe(1)->and($args)->toContain('-pix_fmt yuv420p');
    });
});

it('scores a tone-mapped rendition against a tone-mapped reference', function () {
    $stream = matrixStream(
        ['video_codec' => 'libx264', 'crf' => 23, 'target_vmaf' => 94, 'width' => 1920, 'height' => 1080],
        meta: [...HDR10_SOURCE, 'tone_map' => true],
    );

    $command = (fn () => $this->vmafCommand(10.0, '/tmp/src.mkv', '/tmp/sample.mp4'))->call(new PerTitleCrfService($stream));

    expect($command)->toContain('[0:0]scale=1920:1080,zscale=t=linear')
        ->toContain('format=yuv420p,settb=AVTB');
});

it('builds probe samples exactly as the chunk encode, minus the hardware decode', function () {
    Process::fake();

    $stream = matrixStream(
        ['video_codec' => 'libx265', 'crf' => 22, 'target_vmaf' => 94, 'width' => 1920, 'height' => 1080],
        meta: HDR10_SOURCE,
    );

    $command = (fn () => $this->encodeSampleCommand(22, 'crf', 10.0, '/tmp/src.mkv', '/tmp/s.mp4'))->call(new PerTitleCrfService($stream));

    // The depth comes from the source's pix_fmt, which the probe once blinded to dodge hwaccel.
    expect($command)->toContain('-pix_fmt yuv420p10le')->toContain('-color_trc smpte2084');
});

const HDR_DIR = '/tmp/nukevideo-hdr';

afterAll(function () {
    array_map('unlink', glob(HDR_DIR.'/*') ?: []);
    @rmdir(HDR_DIR);
});

describe('through real ffmpeg', function () {
    $dir = HDR_DIR;

    beforeEach(function () use ($dir) {
        @mkdir($dir, 0o777, true);

        if (! file_exists("{$dir}/source.mkv")) {
            Process::timeout(120)->run(sprintf(
                'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc2=s=320x180:r=24 -t 2 -vf format=yuv420p10le '
                .'-c:v libx265 -preset ultrafast -x265-params "log-level=error:colorprim=bt2020:transfer=smpte2084:colormatrix=bt2020nc:'
                .'hdr10=1:master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,1):max-cll=1000,400" %s',
                escapeshellarg("{$dir}/source.mkv"),
            ))->throw();
        }
    });

    $encode = function (array $params, bool $toneMap) use ($dir): array {
        $stream = matrixStream($params, meta: [...HDR10_SOURCE, 'tone_map' => $toneMap], width: 320, height: 180);
        $out = "{$dir}/out-{$params['video_codec']}.mp4";

        Process::timeout(120)->run(EncodeCommandBuilder::build(new Collection([$stream]), "{$dir}/source.mkv", [$stream->id => $out], 0.0, 1.0))->throw();

        return json_decode(Process::run(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=pix_fmt,color_transfer,color_primaries -of json %s',
            escapeshellarg($out),
        ))->throw()->output(), true)['streams'][0];
    };

    it('lands an H.264 rung on 8-bit BT.709', function () use ($encode) {
        expect($encode(['video_codec' => 'libx264', 'preset' => 'ultrafast'], true))
            ->toMatchArray(['pix_fmt' => 'yuv420p', 'color_transfer' => 'bt709', 'color_primaries' => 'bt709']);
    });

    it('keeps an x265 rung 10-bit PQ', function () use ($encode) {
        expect($encode(['video_codec' => 'libx265', 'preset' => 'ultrafast'], false))
            ->toMatchArray(['pix_fmt' => 'yuv420p10le', 'color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020']);
    });
});
