<?php

/**
 * What the source spent over a per-title sample window, read on real files: the per-title ceiling
 * is scaled from it, so a window read short pushes the CRF up for nothing.
 */

use App\Services\SampleEncode;
use Illuminate\Support\Facades\Process;

/** Bytes of `$track` whose pts fall in [from, from + SECONDS), from a full packet scan. */
function scannedWindowBytes(string $path, float $from): int
{
    $output = Process::timeout(60)->run(['ffprobe', '-v', 'error', '-select_streams', '0',
        '-show_entries', 'packet=pts_time,size', '-of', 'csv=p=0', $path])->throw()->output();

    $bytes = 0;
    foreach (explode("\n", trim($output)) as $line) {
        [$pts, $size] = explode(',', $line);
        $bytes += $pts >= $from && $pts < $from + SampleEncode::SECONDS ? (int) $size : 0;
    }

    return $bytes;
}

it('reads a whole window however far back its keyframe sits', function () {
    // A 30s GOP with a window 25s past its keyframe: a `+duration` read end counts from that
    // keyframe, and used to stop 5s into the window (372 of its 500 packets).
    $path = sys_get_temp_dir().'/nukevideo-longgop.mkv';
    Process::timeout(120)->run(sprintf(
        'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc2=s=320x180:r=25 -t 120 '
        .'-c:v libx264 -preset ultrafast -g 750 -keyint_min 750 -sc_threshold 0 -bf 3 -b:v 400k %s',
        escapeshellarg($path),
    ))->throw();

    try {
        expect(SampleEncode::sourceBytes($path, 0, [85.0, 15.0]))
            ->toBe([scannedWindowBytes($path, 85.0), scannedWindowBytes($path, 15.0)]);
    } finally {
        @unlink($path);
    }
});

it('reads a whole window from a source that seeks by decode time', function () {
    // MPEG-TS seeks to `from` by dts: a frame decoded just before the window but shown inside it
    // was never read, one packet short on every window.
    $path = sys_get_temp_dir().'/nukevideo-bframes.ts';
    Process::timeout(120)->run(sprintf(
        'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc2=s=320x180:r=25 -t 60 '
        .'-c:v libx264 -preset ultrafast -g 250 -bf 3 -b:v 400k %s',
        escapeshellarg($path),
    ))->throw();

    try {
        $start = (float) trim(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=start_time', '-of', 'csv=p=0', $path])->output());

        expect(SampleEncode::sourceBytes($path, 0, [25.057, 13.0]))
            ->toBe([scannedWindowBytes($path, $start + 25.057), scannedWindowBytes($path, $start + 13.0)]);
    } finally {
        @unlink($path);
    }
});

it('reads a window it cannot read as null, never as a short count', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(SampleEncode::sourceBytes('/tmp/src.mkv', 0, [10.0, 50.0]))->toBe([0 => null, 1 => null]);
});
