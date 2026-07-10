<?php

use App\Models\Stream;
use App\Services\ChunkTranscodeService;

function clampStream(array $inputParams, array $meta, int $width, int $height): Stream
{
    return (new Stream)->forceFill([
        'type' => 'video',
        'width' => $width,
        'height' => $height,
        'input_params' => $inputParams,
        'meta' => $meta,
    ]);
}

const CLAMP_PARAMS = [
    'video_codec' => 'libsvtav1',
    'svtav1_crf' => 30,
    'maxrate' => '4000k',
    'bufsize' => '8000k',
];

it('caps maxrate and bufsize at the source bitrate for a same-resolution rendition', function () {
    $args = (new ChunkTranscodeService(clampStream(CLAMP_PARAMS, [
        'source_codec' => 'h264', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960,
    ], 1920, 960)))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 2100k')->toContain('-bufsize 4200k');
});

it('scales the cap sublinearly for a downscaled rendition', function () {
    $args = (new ChunkTranscodeService(clampStream(CLAMP_PARAMS, [
        'source_codec' => 'h264', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960,
    ], 1280, 640)))->buildVideoArguments(windowed: true);

    // (1280*640 / 1920*960)^0.75 ≈ 0.544 → 2.1M * 0.544 ≈ 1143k
    expect($args)->toContain('-maxrate 1143k')->toContain('-bufsize 2286k');
});

it('leaves the template maxrate alone when the source has more bits than the cap', function () {
    $args = (new ChunkTranscodeService(clampStream(CLAMP_PARAMS, [
        'source_codec' => 'h264', 'source_bit_rate' => 12_000_000, 'source_width' => 1920, 'source_height' => 960,
    ], 1920, 960)))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 4000k')->toContain('-bufsize 8000k');
});

it('leaves the template alone when the source bitrate is unknown', function () {
    $args = (new ChunkTranscodeService(clampStream(CLAMP_PARAMS, [
        'source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 960,
    ], 1920, 960)))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 4000k');
});

it('skips the clamp for ABR templates', function () {
    $args = (new ChunkTranscodeService(clampStream(
        [...CLAMP_PARAMS, 'constant_bitrate' => '3000k'],
        ['source_codec' => 'h264', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960],
        1920,
        960,
    )))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 4000k');
});

it('skips the clamp when the target codec is less efficient than the source', function () {
    $args = (new ChunkTranscodeService(clampStream(
        [...CLAMP_PARAMS, 'video_codec' => 'libx264', 'crf' => 23],
        ['source_codec' => 'av1', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960],
        1920,
        960,
    )))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 4000k');
});

it('ignores a bogus sub-100k source bitrate instead of emitting 0k', function () {
    $args = (new ChunkTranscodeService(clampStream(CLAMP_PARAMS, [
        'source_codec' => 'h264', 'source_bit_rate' => 800, 'source_width' => 1920, 'source_height' => 960,
    ], 1920, 960)))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 4000k')->not->toContain('-maxrate 0k');
});

it('preserves the template bufsize:maxrate ratio when clamping', function () {
    $args = (new ChunkTranscodeService(clampStream(
        [...CLAMP_PARAMS, 'bufsize' => '4000k'], // strict 1x VBV
        ['source_codec' => 'h264', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960],
        1920,
        960,
    )))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 2100k')->toContain('-bufsize 2100k');
});

it('parses M-suffixed maxrates when clamping', function () {
    $args = (new ChunkTranscodeService(clampStream(
        [...CLAMP_PARAMS, 'maxrate' => '4M', 'bufsize' => '8M'],
        ['source_codec' => 'h264', 'source_bit_rate' => 2_100_000, 'source_width' => 1920, 'source_height' => 960],
        1920,
        960,
    )))->buildVideoArguments(windowed: true);

    expect($args)->toContain('-maxrate 2100k');
});
