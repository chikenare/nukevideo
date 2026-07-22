<?php

namespace App\Support;

/**
 * Sizes a GPU node's encode concurrency. Unlike CPU encodes, throughput is bound by the media
 * engine's session capacity, not cores — but each job still needs a slice of CPU (demux/mux,
 * bitstream I/O, and full software decode when a source defeats the hardware decoder) and RAM
 * for ffmpeg itself, so both stay as guards. Explicit env always wins over the calculation.
 */
class Gpu
{
    /**
     * Concurrent sessions before the media engine flattens out. Measured on Arc B580:
     * aggregate 28.9x at 3, 31.4x at 6, flat at 8; oversubscribing to 12 on a real movie
     * left the batch wall time identical (440s) and doubled per-chunk latency. The engine's
     * busy% pegs at 100% from ~3 sessions — occupancy, not throughput, so don't tune by it.
     */
    private const MAX_SESSIONS = 6;

    /** Peak RSS for one hardware-pipeline encode — frames live in VRAM, not in process memory. */
    private const ENCODE_MEMORY_GB = 1.0;

    public static function videoWorkerProcesses(): int
    {
        $explicit = (int) env('GPU_WORKER_PROCESSES', 0);

        if ($explicit > 0) {
            return $explicit;
        }

        $byCpu = max(2, Cpu::cores() - 2);
        $byMemory = max(2, (int) floor((Cpu::memoryGb() * 0.8 - 2.0) / self::ENCODE_MEMORY_GB));

        return (int) min(self::MAX_SESSIONS, $byCpu, $byMemory);
    }
}
