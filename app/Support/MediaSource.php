<?php

namespace App\Support;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/** Helpers for feeding ffmpeg/ffprobe a source that may be a local file or a presigned URL. */
class MediaSource
{
    public static function isUrl(string $input): bool
    {
        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }

    /** URLs can't be cheaply verified up front, so let ffmpeg surface a bad link. */
    public static function isReadable(string $input): bool
    {
        return self::isUrl($input) || file_exists($input);
    }

    /**
     * Input flags that make ffmpeg re-issue a ranged request when a long read over HTTP drops.
     * Without them a dropped connection reads as EOF: ffmpeg exits 0 and leaves an output that
     * covers only the bytes it managed to read.
     *
     * @return list<string>
     */
    public static function reconnectArguments(string $input): array
    {
        if (! self::isUrl($input)) {
            return [];
        }

        return [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_on_network_error', '1',
            '-reconnect_on_http_error', '5xx',
            '-reconnect_delay_max', '30',
        ];
    }

    /**
     * How many packets of `$track` the source holds with a presentation time in [$from, $to),
     * both counted from the file's start as an input `-ss` counts them. Null when it can't be
     * read — never a count the caller could mistake for an answer.
     */
    public static function packetsBetween(string $source, int|string $track, float $from, float $to): ?int
    {
        try {
            $probe = Process::timeout(60)->run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=start_time', '-of', 'csv=p=0', $source,
            ]);
            $offset = is_numeric(trim($probe->output())) ? (float) trim($probe->output()) : 0.0;

            // A second early, for containers that seek by decode time, and an absolute end: a
            // `+duration` end counts from the keyframe the read seeks back to.
            $result = Process::timeout(60)->run([
                'ffprobe', '-v', 'error',
                '-select_streams', (string) $track,
                '-read_intervals', sprintf('%.3f%%%.3f', max(0.0, $offset + $from - 1), $offset + $to + 1),
                '-show_entries', 'packet=pts_time',
                '-of', 'csv=p=0',
                $source,
            ]);
        } catch (ProcessTimedOutException) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $count = 0;

        foreach (explode("\n", trim($result->output())) as $pts) {
            if (is_numeric($pts) && $pts >= $offset + $from && $pts < $offset + $to) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * An encoded file's video frame rate (as a rational) and its timescale — what a re-encode of
     * the same chunk must match to concatenate with its neighbours. Null when it can't be read.
     *
     * @return ?array{rate: string, timescale: int}
     */
    public static function videoTiming(string $path): ?array
    {
        try {
            $result = Process::timeout(60)->run([
                'ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'stream=r_frame_rate,time_base', '-of', 'csv=p=0', $path,
            ]);
        } catch (ProcessTimedOutException) {
            return null;
        }

        // ffprobe prints the entries in its own order: r_frame_rate, then time_base.
        [$rate, $timeBase] = array_pad(explode(',', trim($result->output())), 2, '');

        if (! $result->successful() || ! preg_match('#^\d+/\d+$#', $rate) || ! preg_match('#^1/(\d+)$#', $timeBase, $m)) {
            return null;
        }

        return ['rate' => $rate, 'timescale' => (int) $m[1]];
    }
}
