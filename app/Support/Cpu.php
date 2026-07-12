<?php

namespace App\Support;

/**
 * Sizes a worker node's encode concurrency against the hardware it actually sees — nodes range from
 * a 4-core VPS to a 64-core box, so nothing here may assume a shape. Two independent budgets, CPU
 * and memory, and the tighter one wins: a box with cores to spare but little RAM must not run more
 * ffmpeg than its RAM holds (staging learned this the hard way — the OOM killer took the S3 store
 * down with it), and a box with RAM to spare can't run more encodes than its cores can feed.
 *
 * Both readings respect cgroup limits, so `DOCKER_MEMORY`/`DOCKER_CPUSET_CPUS` on a node shrink the
 * budget instead of being ignored. Explicit env always wins over the whole calculation.
 */
class Cpu
{
    /** Threads per ffmpeg. SVT-AV1 scales poorly past this; more instances beat fatter ones. */
    private const ENCODE_THREADS = 4;

    private const MAX_ENCODE_THREADS = 8;

    /** Peak RSS budget for one encode. Measured on staging: 0.9-2.2 GB for a 1080p SVT-AV1 chunk. */
    private const ENCODE_MEMORY_GB = 3.0;

    /** Left to the OS, the chunk store, packaging and Horizon itself. */
    private const RESERVED_FRACTION = 0.2;

    private const MIN_RESERVED_GB = 2.0;

    public static function cores(): int
    {
        $n = (int) @shell_exec('nproc 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        $n = (int) @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');

        return $n > 0 ? $n : 1;
    }

    /** Memory the node may actually use: its cgroup limit when capped, else the host's total. */
    public static function memoryGb(): float
    {
        $limit = @file_get_contents('/sys/fs/cgroup/memory.max')
            ?: @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes');

        // cgroup v2 writes "max" when uncapped, v1 an absurd sentinel. Both mean "whatever the host has".
        $bytes = (float) trim((string) $limit);

        if ($bytes > 0 && $bytes < PHP_INT_MAX / 2) {
            return $bytes / 1024 ** 3;
        }

        if (preg_match('/MemTotal:\s+(\d+) kB/', (string) @file_get_contents('/proc/meminfo'), $match)) {
            return ((float) $match[1]) / 1024 ** 2;
        }

        return self::ENCODE_MEMORY_GB + self::MIN_RESERVED_GB; // unreadable: enough for exactly one encode
    }

    public static function videoWorkerProcesses(): int
    {
        return (int) env('VIDEO_WORKER_PROCESSES', self::workerProcesses(self::cores(), self::memoryGb()));
    }

    public static function videoEncoderThreads(): int
    {
        return (int) env('VIDEO_FFMPEG_THREADS', self::threadsPerEncode(self::cores(), self::videoWorkerProcesses()));
    }

    /**
     * Concurrent encodes this node can carry: whichever of its CPU and its RAM runs out first.
     * Never zero — a box too small for the budget still processes video, one chunk at a time.
     */
    public static function workerProcesses(int $cores, float $memoryGb): int
    {
        $usableGb = $memoryGb - max(self::MIN_RESERVED_GB, $memoryGb * self::RESERVED_FRACTION);

        $byCpu = intdiv(max(1, $cores), self::ENCODE_THREADS);
        $byMemory = (int) floor($usableGb / self::ENCODE_MEMORY_GB);

        return max(1, min($byCpu, $byMemory));
    }

    /**
     * Threads for one encode: the node's fair share, so processes × threads fills the CPU without
     * oversubscribing it. Capped — past MAX_ENCODE_THREADS an encoder buys little per extra thread.
     */
    public static function threadsPerEncode(int $cores, int $processes): int
    {
        $fairShare = intdiv(max(1, $cores), max(1, $processes));

        return max(1, min(self::MAX_ENCODE_THREADS, $fairShare));
    }
}
