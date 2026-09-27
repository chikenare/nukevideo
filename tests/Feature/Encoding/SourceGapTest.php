<?php

/**
 * A damaged source can hold stretches with audio and no picture. A chunk window that ends inside
 * one came out short on every attempt and failed the video (seven Dragon Ball Z episodes); it is
 * now re-encoded repeating the last frame. That chunk is concatenated with `-c copy` next to its
 * neighbours, so it must keep their frame rate and timescale — a fill at the rounded source rate
 * (23.976 → 1/11988 against 1/24000) played its window at double speed.
 */

use App\Services\EncodeCommandBuilder;
use App\Support\MediaDuration;
use App\Support\MediaSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

const GAP_DIR = '/tmp/nukevideo-gap';

/** 40s at 24000/1001, keyframes every 2s, no picture between 15s and 20.02s (a keyframe). */
function gappedSource(): string
{
    $path = GAP_DIR.'/source.mkv';

    if (! file_exists($path)) {
        @mkdir(GAP_DIR, 0o777, true);
        Process::timeout(120)->run(sprintf(
            'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc2=s=320x180:r=24000/1001 -t 40 '
            .'-vf "select=\'not(between(t,15,20))\'" -fps_mode passthrough '
            .'-c:v libx264 -preset ultrafast -g 48 -keyint_min 48 -sc_threshold 0 %s',
            escapeshellarg($path),
        ))->throw();
    }

    return $path;
}

function gapStream()
{
    return matrixStream(['video_codec' => 'libx264', 'crf' => 30, 'preset' => 'ultrafast', 'gop_size' => 48],
        meta: [...MATRIX_SOURCE, 'source_fps' => 23.976], width: 320, height: 180);
}

function encodeWindow(int $i, float $start, float $end, ?array $gapFill = null): string
{
    $stream = gapStream();
    $out = GAP_DIR."/chunk_{$i}.mp4";
    Process::timeout(120)->run(EncodeCommandBuilder::build(new Collection([$stream]), gappedSource(), [$stream->id => $out], $start, $end, $gapFill))->throw();

    return $out;
}

afterAll(function () {
    array_map('unlink', glob(GAP_DIR.'/*') ?: []);
    @rmdir(GAP_DIR);
});

it('tells a hole in the source from a stretch that holds picture', function () {
    expect(MediaSource::packetsBetween(gappedSource(), 0, 15.05, 20.0))->toBe(0)
        ->and(MediaSource::packetsBetween(gappedSource(), 0, 2.0, 8.0))->toBeGreaterThan(100)
        ->and(MediaSource::packetsBetween('/nonexistent.mkv', 0, 1.0, 2.0))->toBeNull();
});

it('fills the hole so the chunk keeps its length, and concatenates in step with its neighbours', function () {
    // Keyframe-aligned windows, as the planner cuts them: the middle one ends where the picture
    // comes back, so the hole cuts its end.
    $windows = [[0.0, 10.01], [10.01, 20.02], [20.02, 40.0]];

    $chunks = [encodeWindow(0, ...$windows[0])];
    $short = encodeWindow(1, ...$windows[1]);
    expect(MediaDuration::truncated($short, 10.01))->not->toBeNull();

    // Filled with the short encode's own rate and timescale, as ProcessChunkJob does.
    $timing = MediaSource::videoTiming($short);
    expect($timing)->toBe(['rate' => '24000/1001', 'timescale' => 24000]);

    $chunks[] = encodeWindow(1, ...[...$windows[1], $timing]);
    expect(MediaDuration::truncated($chunks[1], 10.01))->toBeNull();
    $chunks[] = encodeWindow(2, ...$windows[2]);

    // PackageVideoJob's concat.
    file_put_contents(GAP_DIR.'/list.txt', implode("\n", array_map(fn ($c) => "file '{$c}'", $chunks))."\n");
    Process::timeout(120)->run(sprintf('ffmpeg -hide_banner -v error -y -f concat -safe 0 -i %s -c copy -movflags +faststart %s',
        GAP_DIR.'/list.txt', GAP_DIR.'/rendition.mp4'))->throw();

    $pts = array_map('floatval', explode("\n", trim(Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v:0',
        '-show_entries', 'packet=pts_time', '-of', 'csv=p=0', GAP_DIR.'/rendition.mp4'])->output())));
    sort($pts);
    $deltas = array_map(fn ($a, $b) => $b - $a, array_slice($pts, 0, -1), array_slice($pts, 1));

    // One frame apart all the way through: no double-speed run, no jump, the full 40s.
    expect(min($deltas))->toBeGreaterThan(0.04)
        ->and(max($deltas))->toBeLessThan(0.043)
        ->and(end($pts))->toBeGreaterThan(39.9);
});
