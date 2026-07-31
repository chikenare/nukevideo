<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

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

/** A video/audio stream with no database behind it, for the argument builders. */
function matrixStream(array $inputParams, string $type = 'video', array $meta = MATRIX_SOURCE, ?int $width = null, ?int $height = null): App\Models\Stream
{
    $stream = (new App\Models\Stream)->forceFill([
        'type' => $type,
        'width' => $width ?? $inputParams['width'] ?? 1280,
        'height' => $height ?? $inputParams['height'] ?? 720,
        'input_params' => $inputParams,
        'meta' => $meta,
    ]);
    $stream->id = 1;

    return $stream;
}

/** A cheap 1.26 Mbps 1080p h264 source: the staging case where a re-encode outgrew its source. */
const LIGHT_SOURCE = [
    'index' => 0,
    'source_codec' => 'h264',
    'source_pix_fmt' => 'yuv420p',
    'source_width' => 1920,
    'source_height' => 1080,
    'source_bit_rate' => 1_263_599,
];

/** The quality knob each codec steers with, as its template renders it. */
const QUALITY_KNOBS = [
    'libx264' => ['crf' => 23, 'flag' => '-crf 23'],
    'libx265' => ['crf' => 26, 'flag' => '-crf 26'],
    'libsvtav1' => ['svtav1_crf' => 30, 'flag' => '-crf 30'],
    'h264_qsv' => ['qsv_global_quality' => 23, 'flag' => '-global_quality 23'],
    'hevc_qsv' => ['qsv_global_quality' => 24, 'flag' => '-global_quality 24'],
    'av1_qsv' => ['qsv_global_quality' => 22, 'flag' => '-global_quality 22'],
    'h264_nvenc' => ['nvenc_cq' => 25, 'flag' => '-cq 25'],
    'av1_nvenc' => ['nvenc_cq' => 30, 'flag' => '-cq 30'],
];

/** A template in quality mode for `$codec`, plus whatever `$extra` the case is about. */
function qualityTemplate(string $codec, array $extra = []): array
{
    return ['video_codec' => $codec, 'gop_size' => 60, ...collect(QUALITY_KNOBS[$codec])->except('flag')->all(), ...$extra];
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
