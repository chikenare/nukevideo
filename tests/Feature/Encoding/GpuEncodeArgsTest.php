<?php

use App\Models\Stream;
use App\Services\ChunkTranscodeService;

function gpuStream(array $inputParams, array $meta = []): Stream
{
    return (new Stream)->forceFill([
        'type' => 'video',
        'width' => 1920,
        'height' => 1080,
        'input_params' => $inputParams,
        'meta' => $meta,
    ]);
}

it('builds qsv arguments with the ABR grid pinned and no CPU thread flags', function (string $codec) {
    $args = (new ChunkTranscodeService(gpuStream([
        'video_codec' => $codec,
        'qsv_global_quality' => 24,
        'qsv_preset' => 'medium',
        'gop_size' => 48,
    ])))->buildVideoArguments(windowed: true);

    expect($args)
        ->toContain("-c:v {$codec}")
        ->toContain('-global_quality 24')
        ->toContain('-preset medium')
        ->toContain('-g 48')
        ->toContain('-adaptive_i 0 -adaptive_b 0')
        ->not->toContain('-threads')
        ->not->toContain('-sc_threshold')
        ->not->toContain('x264-params');
})->with(['h264_qsv', 'hevc_qsv', 'av1_qsv']);

it('builds nvenc arguments and frees CQ from the default bitrate cap', function (string $codec) {
    $args = (new ChunkTranscodeService(gpuStream([
        'video_codec' => $codec,
        'nvenc_cq' => 23,
        'nvenc_preset' => 'p5',
    ])))->buildVideoArguments(windowed: true);

    expect($args)
        ->toContain("-c:v {$codec}")
        ->toContain('-cq 23')
        ->toContain('-preset p5')
        ->toContain('-no-scenecut 1 -forced-idr 1 -b:v 0')
        ->not->toContain('-threads')
        ->not->toContain('-sc_threshold');
})->with(['h264_nvenc', 'hevc_nvenc', 'av1_nvenc']);

it('keeps the template bitrate when an nvenc output is ABR', function () {
    $args = (new ChunkTranscodeService(gpuStream([
        'video_codec' => 'h264_nvenc',
        'nvenc_cq' => 23,
        'constant_bitrate' => '3000k',
    ])))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-b:v 3000k')->not->toContain('-b:v 0');
});

it('remuxes instead of re-encoding when the source already matches a GPU target', function () {
    $args = (new ChunkTranscodeService(gpuStream(
        ['video_codec' => 'h264_nvenc', 'maxrate' => '5000k', 'width' => 1920, 'height' => 1080],
        ['source_codec' => 'h264', 'source_bit_rate' => 2_000_000, 'source_width' => 1920, 'source_height' => 1080],
    )))->buildVideoArguments(windowed: false);

    expect($args)->toContain('-c:v copy');
});

const HW_SOURCE = ['source_codec' => 'h264', 'source_pix_fmt' => 'yuv420p', 'source_width' => 1920, 'source_height' => 1080];

it('hardware-decodes and scales on the GPU when the source qualifies', function () {
    $svc = new ChunkTranscodeService(gpuStream(['video_codec' => 'av1_qsv', 'qsv_global_quality' => 28], HW_SOURCE));

    expect($svc->inputArguments(windowed: true))->toContain('-hwaccel qsv -hwaccel_output_format qsv')
        ->and($svc->buildVideoArguments(windowed: true))
        ->toContain('-vf vpp_qsv=w=1920:h=1080:format=nv12')
        ->not->toContain('-vf scale=');
});

it('uses the cuda pipeline for nvenc targets', function () {
    $svc = new ChunkTranscodeService(gpuStream(['video_codec' => 'hevc_nvenc', 'nvenc_cq' => 24], HW_SOURCE));

    expect($svc->inputArguments(windowed: true))->toContain('-hwaccel cuda -hwaccel_output_format cuda')
        ->and($svc->buildVideoArguments(windowed: true))->toContain('-vf scale_cuda=1920:1080:format=nv12');
});

it('falls back to software decode with capped threads when the source defeats the hardware', function () {
    $svc = new ChunkTranscodeService(gpuStream(
        ['video_codec' => 'av1_qsv', 'qsv_global_quality' => 28],
        ['source_codec' => 'mpeg4', 'source_pix_fmt' => 'yuv420p'],
    ));

    expect($svc->inputArguments(windowed: true))->toMatch('/^-threads \d+ $/')
        ->and($svc->buildVideoArguments(windowed: true))->toContain('-vf scale=1920:1080');
});

it('keeps 10-bit through the GPU pipeline except for H.264', function () {
    $tenBit = ['source_codec' => 'hevc', 'source_pix_fmt' => 'yuv420p10le'];

    $av1 = (new ChunkTranscodeService(gpuStream(['video_codec' => 'av1_qsv'], $tenBit)))->buildVideoArguments(windowed: true);
    $h264 = (new ChunkTranscodeService(gpuStream(['video_codec' => 'h264_qsv'], $tenBit)))->buildVideoArguments(windowed: true);

    expect($av1)->toContain('format=p010le')
        ->and($h264)->toContain('format=nv12');
});

it('builds the full hwaccel command for a windowed chunk and leaves sidecars alone', function () {
    $video = gpuStream(['video_codec' => 'av1_qsv'], HW_SOURCE);
    $video->id = 1;

    $cmd = \App\Services\EncodeCommandBuilder::build(collect([$video]), 'http://src', [1 => '/out.part'], 0.0, 10.0);

    expect($cmd)->toStartWith('ffmpeg -hide_banner -y -hwaccel qsv -hwaccel_output_format qsv -ss');

    $audio = (new Stream)->forceFill(['type' => 'audio', 'input_params' => ['audio_codec' => 'aac'], 'meta' => []]);
    $audio->id = 2;

    expect(\App\Services\EncodeCommandBuilder::build(collect([$audio]), 'http://src', [2 => '/a.part']))
        ->not->toContain('-hwaccel');
});

it('sizes GPU workers from the hardware and honors the env override', function () {
    putenv('GPU_WORKER_PROCESSES=5');
    expect(\App\Support\Gpu::videoWorkerProcesses())->toBe(5);

    putenv('GPU_WORKER_PROCESSES');
    expect(\App\Support\Gpu::videoWorkerProcesses())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(6);
});

it('maps codecs to their hardware family', function () {
    expect(ChunkTranscodeService::accelForCodec('av1_qsv'))->toBe('intel')
        ->and(ChunkTranscodeService::accelForCodec('h264_nvenc'))->toBe('nvidia')
        ->and(ChunkTranscodeService::accelForCodec('libsvtav1'))->toBeNull()
        ->and(ChunkTranscodeService::accelForCodec(null))->toBeNull();
});
