<?php

namespace App\Services;

/**
 * Parses ffmpeg's `time=` output and reports per-chunk progress, throttled to flush only when
 * the percent climbs and at most once every 5s (100% always flushes), since each report is a
 * Redis HSET per output. One instance per encode pass.
 */
class ChunkProgressReporter
{
    private int $lastPercent = 0;

    private ?float $lastProgressAt = null;

    /**
     * @param  iterable<\App\Models\Output>  $outputs
     */
    public function __construct(
        private iterable $outputs,
        private int $chunkIndex,
        private float $windowDuration,
    ) {}

    public function handle(string $output): void
    {
        if ($this->windowDuration <= 0) {
            return;
        }

        if (! preg_match('/time=(\d{2}):(\d{2}):(\d{2})\.\d+/', $output, $m)) {
            return;
        }

        $seconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        $percent = (int) min(100, round($seconds / $this->windowDuration * 100));
        $now = microtime(true);

        if ($percent <= $this->lastPercent) {
            return;
        }

        if ($percent !== 100 && $this->lastProgressAt !== null && ($now - $this->lastProgressAt) < 5) {
            return;
        }

        $this->lastProgressAt = $now;
        $this->lastPercent = $percent;

        foreach ($this->outputs as $out) {
            $out->reportChunkProgress($this->chunkIndex, $percent);
        }
    }
}
