<?php

namespace App\Services\Concerns;

use App\Models\Video;

/**
 * Throttled liveness heartbeat for long-running processing services.
 *
 * ffmpeg progress fires many times per second; persisting every tick would hammer
 * the database and Redis. This emits at most one heartbeat per interval while
 * still proving the worker is alive far sooner than the queue's retry_after.
 */
trait EmitsHeartbeat
{
    private const HEARTBEAT_INTERVAL_SECONDS = 15;

    private ?float $lastHeartbeatAt = null;

    private function heartbeat(Video $video): void
    {
        $now = microtime(true);

        if ($this->lastHeartbeatAt !== null
            && ($now - $this->lastHeartbeatAt) < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $video->heartbeat();
    }
}
