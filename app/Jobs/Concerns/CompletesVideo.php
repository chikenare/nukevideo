<?php

namespace App\Jobs\Concerns;

use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Stream;
use App\Models\Video;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CompletesVideo
{
    private function markOutputsFailedForStream(Stream $stream): void
    {
        foreach ($stream->outputs as $output) {
            if (in_array($output->status->value, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
                continue;
            }

            $output->update(['status' => VideoStatus::FAILED->value]);
        }
    }

    /** Returns true only for the caller that won the lock and flipped the video to terminal. */
    private function completeVideoIfReady(Video $video): bool
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
                ->log($anyCompleted ? "Video processing completed: {$locked->name}" : "Video processing failed: {$locked->name}");

            return true;
        });
    }

    private function finalizeVideoIfReady(Video $video): void
    {
        if ($this->completeVideoIfReady($video)) {
            Storage::disk('local')->deleteDirectory($video->ulid);
            // Only our own subtrees (the store reuses the default bucket), never the whole prefix.
            Storage::disk('chunks')->deleteDirectory($video->chunksDir());
            Storage::disk('chunks')->deleteDirectory("{$video->ulid}/".Video::SOURCE_DIR);
            Storage::disk('chunks')->deleteDirectory($video->finalDir());
            $video->outputs->each->clearChunkProgress();

            $fresh = $video->fresh();
            $event = $fresh->status === VideoStatus::COMPLETED->value ? 'video.completed' : 'video.error';
            WebhookDispatcher::forVideo($event, $fresh);
            CleanupVideoResourcesJob::dispatch($video->ulid)->onQueue('video-processing');
        }
    }
}
