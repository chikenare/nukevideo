<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Duration of an encoded output, used to reject silent truncation: when the read of the source
 * dies mid-file ffmpeg treats it as EOF, exits 0 and writes a file that stops there. Nothing
 * downstream notices — the packager happily builds a manifest whose audio ends minutes in.
 */
class MediaDuration
{
    /** Encoders round and containers often overstate their tail, so allow a small shortfall. */
    private const TOLERANCE = 0.02;

    private const SLACK_SECONDS = 1.0;

    public static function seconds(string $path): ?float
    {
        $process = Process::timeout(60)->run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path,
        ]);

        $duration = trim($process->output());

        return $process->successful() && is_numeric($duration) ? (float) $duration : null;
    }

    /** The output's duration when it falls short of `$expected`; null when it covers it or can't be probed. */
    public static function truncated(string $path, float $expected): ?float
    {
        if ($expected <= 0) {
            return null;
        }

        $actual = self::seconds($path);

        if ($actual === null) {
            return null;
        }

        return $actual < $expected * (1 - self::TOLERANCE) - self::SLACK_SECONDS ? $actual : null;
    }
}
