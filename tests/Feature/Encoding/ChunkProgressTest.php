<?php

use App\Models\Output;
use App\Services\ChunkProgressReporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

function progressOutput(): Output
{
    return (new Output)->forceFill(['id' => 7]);
}

it('writes a chunk report to the output hash', function () {
    Redis::shouldReceive('hset')->once()->with('output-progress:7', '3:42', 55);
    Redis::shouldReceive('expire')->once()->with('output-progress:7', 86400);

    progressOutput()->reportChunkProgress(3, 42, 55);
});

it('never lets a failed progress write escape into the encode it describes', function () {
    // A dropped Redis connection inside ffmpeg's progress callback used to kill the chunk's
    // encode mid-way; the report is a display value and has to fail on its own.
    Log::spy();
    Redis::shouldReceive('hset')->andThrow(new RuntimeException('read error on connection to redis:6379'));

    $reporter = new ChunkProgressReporter([progressOutput()], 3, 42, 10.0);
    $reporter->handle('frame=  120 fps=24 time=00:00:05.00 bitrate=1000kbits/s');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'Chunk progress report failed')
        && $context['output'] === 7);
});

it('carries on when seeding or clearing the progress hash fails', function () {
    Log::spy();
    Redis::shouldReceive('hmset')->andThrow(new RuntimeException('Connection closed'));
    Redis::shouldReceive('del')->andThrow(new RuntimeException('Connection timed out'));

    progressOutput()->seedChunkProgress(4, [42, 43]);
    progressOutput()->clearChunkProgress();

    Log::shouldHaveReceived('warning')->twice();
});
