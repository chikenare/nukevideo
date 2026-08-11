<?php

namespace App\Jobs\Concerns;

use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Video;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CompletesVideo
{
    /** Returns true only for the caller that won the lock and flipped the video to terminal. */
    private function completeVideoIfReady(Video $video, ?string $reason = null): bool
    {
        // Cheap pre-check to avoid lock churn; the authoritative check runs under the lock.
        $allSettled = ! $video->outputs()
            ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
            ->exists();

        if (! $allSettled) {
            return false;
        }

        return DB::transaction(function () use ($video) {
            $locked = Video::whereKey($video->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
                return false;
            }

            // Re-check under the lock: an output's status may have changed concurrently
            // between the pre-check above and acquiring the lock.
            $allSettled = ! $locked->outputs()
                ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
                ->exists();

            if (! $allSettled) {
                return false;
            }

            // At least one output completed → video COMPLETED; all failed → video FAILED.
            $anyCompleted = $locked->outputs()->where('status', VideoStatus::COMPLETED->value)->exists();
            $finalStatus = $anyCompleted ? VideoStatus::COMPLETED->value : VideoStatus::FAILED->value;

            $locked->update(['status' => $finalStatus]);

            activity('video')
                ->performedOn($locked)
                ->causedBy($locked->user)
                ->event($anyCompleted ? 'video_completed' : 'video_failed')
                ->withProperties($anyCompleted ? [] : array_filter(['reason' => Video::trimReason($reason)]))
                ->log($anyCompleted ? "Video processing completed: {$locked->name}" : "Video processing failed: {$locked->name}");

            return true;
        });
    }

    private function finalizeVideoIfReady(Video $video, ?string $reason = null): void
    {
        if (! $this->completeVideoIfReady($video, $reason)) {
            return;
        }

        $fresh = $video->fresh();
        $completed = $fresh->status === VideoStatus::COMPLETED->value;

        // Local scratch belongs to whichever worker ran the job and helps no retry; drop it either way.
        Storage::disk('local')->deleteDirectory($video->ulid);

        // The mirrored source and everything staged off it are what make a retry cheap — minutes
        // instead of a full re-download and re-encode — so a FAILED video keeps them and lets
        // PruneScratchJob reclaim them on its own grace window. Only a successful run drops them
        // here. Only our own subtrees (the store reuses the default bucket), never the whole prefix.
        if ($completed) {
            Storage::disk('chunks')->deleteDirectory($video->chunksDir());
            Storage::disk('chunks')->deleteDirectory("{$video->ulid}/".Video::SOURCE_DIR);
            Storage::disk('chunks')->deleteDirectory($video->finalDir());
        }

        $video->outputs->each->clearChunkProgress();

        WebhookDispatcher::forVideo($completed ? 'video.completed' : 'video.error', $fresh);
        CleanupVideoResourcesJob::dispatch($video->ulid)->onQueue('orchestration');
    }

    /**
     * Stop the encode batches of a video that can no longer complete. Callers that need the
     * batches stopped *before* they settle the video (so in-flight chunks can't race the
     * finalize) reach for this; every path that goes through {@see Video::markAsFailed()} is
     * already covered by it.
     */
    private function cancelEncodeBatches(Video $video): void
    {
        $video->cancelEncodeBatches();
    }
}
