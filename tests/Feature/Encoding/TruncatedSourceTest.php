<?php

use App\Services\EncodeCommandBuilder;
use App\Support\MediaDuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

describe('reconnect flags', function () {
    it('makes ffmpeg reconnect when the source is a presigned URL', function () {
        $command = EncodeCommandBuilder::build(
            new Collection([matrixStream(['video_codec' => 'libx264'])]),
            'https://mirror.test/source.mkv?sig=x',
            [1 => '/tmp/out.mp4'],
            0.0,
            6.0,
        );

        expect($command)->toContain('-reconnect 1')
            ->and($command)->toContain('-reconnect_on_network_error 1')
            // Before -i, or ffmpeg applies them to nothing.
            ->and(strpos($command, '-reconnect 1'))->toBeLessThan(strpos($command, '-i '));
    });

    it('leaves a local source alone', function () {
        $command = EncodeCommandBuilder::build(
            new Collection([matrixStream(['video_codec' => 'libx264'])]),
            '/scratch/source.mkv',
            [1 => '/tmp/out.mp4'],
        );

        expect($command)->not->toContain('-reconnect');
    });
});

describe('truncation guard', function () {
    it('flags an output that stops short of the expected duration', function () {
        Process::fake(['*ffprobe*' => Process::result("106.853000\n")]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBe(106.853);
    });

    it('accepts the rounding a real encode drifts by', function () {
        Process::fake(['*ffprobe*' => Process::result("6830.1\n")]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBeNull();
    });

    it('stays out of the way when the duration is unknown', function () {
        Process::fake(['*ffprobe*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBeNull()
            ->and(MediaDuration::truncated('/tmp/audio.mp4', 0.0))->toBeNull();
    });
});
