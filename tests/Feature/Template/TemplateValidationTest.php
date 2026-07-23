<?php

use App\Data\Template\StoreTemplateData;
use Illuminate\Support\Facades\Validator;

function templateQuery(array $variant, string $codec): array
{
    return ['outputs' => [[
        'video_codec' => $codec,
        'variants' => [$variant],
        'audio' => ['audio_codec' => 'aac', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k']]],
    ]]];
}

function validateTemplate(array $variant, string $codec): bool
{
    return Validator::make(
        ['name' => 'x', 'query' => templateQuery($variant, $codec)],
        StoreTemplateData::rules(),
    )->passes();
}

// A GPU variant with neither a quality value nor a bitrate would fall into the encoder's
// default rate control (CQP default QP on QSV, 2M VBR on NVENC) — never what anyone wants.
it('requires a quality value on GPU variants unless a bitrate is pinned', function (string $codec) {
    expect(validateTemplate(['width' => 1920, 'height' => 1080], $codec))->toBeFalse();
})->with(['h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc']);

it('accepts quality mode and ABR mode GPU variants', function (string $codec, array $extra) {
    expect(validateTemplate(['width' => 1920, 'height' => 1080] + $extra, $codec))->toBeTrue();
})->with([
    ['av1_qsv', ['qsv_global_quality' => 22]],
    ['av1_qsv', ['constant_bitrate' => '3000k']],
    ['av1_nvenc', ['nvenc_cq' => 28]],
    ['av1_nvenc', ['constant_bitrate' => '3000k']],
]);

it('validates every shipped preset', function () {
    foreach (config('template-presets') as $slug => $preset) {
        expect(Validator::make(
            ['name' => 'x', 'query' => $preset['query']],
            StoreTemplateData::rules(),
        )->passes())->toBeTrue("preset {$slug} failed validation");
    }
});
