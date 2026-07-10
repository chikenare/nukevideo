<?php

namespace App\Support;

class Cpu
{
    public static function cores(): int
    {
        $n = (int) @shell_exec('nproc 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        $n = (int) @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');

        return $n > 0 ? $n : 1;
    }

    public static function videoWorkerProcesses(): int
    {
        return env('VIDEO_WORKER_PROCESSES', self::cores());
    }
}
