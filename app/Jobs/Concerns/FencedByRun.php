<?php

namespace App\Jobs\Concerns;

use App\Models\Video;
use Illuminate\Support\Facades\Log;

/**
 * Run fence for the processing chain.
 *
 * `videos.dispatch_attempts` is the run discriminator: the reaper increments it
 * every time it requeues a stuck video, so each dispatched chain carries the
 * value it was born with. A job whose snapshot no longer matches belongs to a
 * superseded ("zombie") run — typically a chain the reaper gave up on but whose
 * worker was merely slow. Such a job must not touch the video at all: without
 * this fence a zombie chain can mark a video COMPLETED mid-rerun and its
 * cleanup then deletes the original source the new run still needs.
 *
 * A `null` snapshot means the job was serialized before fencing existed
 * (in-flight payload from an older deploy) and is let through unchanged.
 */
trait FencedByRun
{
    public ?int $runAttempt = null;

    /**
     * True when this job's run no longer owns the video (or the video is gone).
     */
    private function supersededRun(?Video $video): bool
    {
        if (! $video) {
            return true;
        }

        if ($this->runAttempt !== null && $video->dispatch_attempts !== $this->runAttempt) {
            Log::info('Skipping job from superseded run', [
                'job' => static::class,
                'video' => $video->id,
                'job_attempt' => $this->runAttempt,
                'current_attempt' => $video->dispatch_attempts,
            ]);

            return true;
        }

        return false;
    }
}
