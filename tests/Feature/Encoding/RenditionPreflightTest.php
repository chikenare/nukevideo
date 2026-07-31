<?php

use App\Services\RenditionPreflight;
use Illuminate\Support\Facades\Process;

/**
 * The preflight is the only rendition check that can fail a video, so both answers matter: it must
 * catch a parameter set the encoder refuses, and it must not fail one that encodes fine.
 */
function preflightStream(array $params, ?string $name = '1080p'): App\Models\Stream
{
    $stream = matrixStream($params, meta: LIGHT_SOURCE, width: 1920, height: 1080);
    $stream->name = $name;

    return $stream;
}

/** Writes bytes to whatever output path the command actually asked for. */
function fakeWritingEncoder(int $bytes = 4096): void
{
    Process::fake(function ($process) use ($bytes) {
        if (preg_match('/"([^"]+)"\s*$/', $process->command, $matches)) {
            file_put_contents($matches[1], str_repeat('x', $bytes));
        }

        return Process::result('');
    });
}

it('passes a rendition whose parameters the encoder accepts', function () {
    fakeWritingEncoder();

    (new RenditionPreflight(preflightStream(qualityTemplate('libx264'))))->assert(sys_get_temp_dir().'/src.mkv');
})->throwsNoExceptions();

it('runs the real chunk command over one second of the source', function () {
    fakeWritingEncoder();

    (new RenditionPreflight(preflightStream(qualityTemplate('libx264'))))->assert(sys_get_temp_dir().'/src.mkv');

    Process::assertRan(fn ($process) => str_contains($process->command, '-ss 0.0000 -to 1.0000')
        && str_contains($process->command, '-c:v libx264')
        && str_contains($process->command, '-crf 23'));
});

it('fails the video with the encoder own words when it refuses the parameters', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Target Bitrate only supported when --rc is 1/2', exitCode: 1)]);

    (new RenditionPreflight(preflightStream(qualityTemplate('libsvtav1'))))->assert(sys_get_temp_dir().'/src.mkv');
})->throws(RuntimeException::class, 'Target Bitrate only supported when --rc is 1/2');

it('fails on an encoder that aborts having written nothing, exit code aside', function () {
    // The av1_qsv case: a rejected extension flag leaves a 0-byte file behind a clean exit.
    Process::fake(['*' => Process::result('')]);

    (new RenditionPreflight(preflightStream(qualityTemplate('libx264'))))->assert(sys_get_temp_dir().'/src.mkv');
})->throws(RuntimeException::class, 'cannot be encoded with these parameters');

it('leaves no sample file behind, whichever way it goes', function () {
    fakeWritingEncoder();

    (new RenditionPreflight(preflightStream(qualityTemplate('libx264'))))->assert(sys_get_temp_dir().'/src.mkv');

    expect(glob(sys_get_temp_dir().'/sample_*'))->toBe([]);
});

it('skips a rendition this node has no hardware for, leaving it to its own chunk jobs', function (?string $nodeAccel) {
    Process::fake();
    config(['ffmpeg.node_accel' => $nodeAccel]);

    (new RenditionPreflight(preflightStream(qualityTemplate('av1_qsv'))))->assert(sys_get_temp_dir().'/src.mkv');

    Process::assertNothingRan();
})->with(['CPU-only node' => null, 'the other GPU family' => 'nvidia']);
