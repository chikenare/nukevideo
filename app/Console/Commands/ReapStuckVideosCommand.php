<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Node;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers videos whose worker/node died mid-processing.
 *
 * A crash doesn't throw, so the queue would otherwise only notice after
 * retry_after (~6h). Liveness is proven by {@see Video::heartbeat()}, refreshed
 * by every stage; a stale heartbeat means nothing is working on the video. We
 * release its slot, reset its derived streams, and hand it back to the
 * dispatcher (incrementing dispatch_attempts), a few times before giving up.
 *
 * Re-dispatch goes through the normal `videos:dispatch` path (PENDING +
 * node_id null), so there is no second code path to keep in sync.
 */
class ReapStuckVideosCommand extends Command
{
    protected $signature = 'videos:reap
        {--stuck-minutes=10 : Minutes without a heartbeat before a video is considered dead}
        {--max-attempts=3 : Re-dispatch attempts before failing the video for good}';

    protected $description = 'Recover videos stuck because a worker or node died mid-processing';

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('stuck-minutes'));
        $maxAttempts = (int) $this->option('max-attempts');

        $stuck = Video::query()
            ->whereIn('status', Video::ACTIVE_STATUSES)
            // A PENDING video without a node is just waiting for the dispatcher
            // and must not be reaped. Any OTHER active status without a node is
            // an inconsistent leftover (lost race, deleted node) that no worker
            // will ever finish — previously invisible to this query, it sat in
            // running/uploading forever and couldn't even be deleted.
            ->where(function ($query) {
                $query->whereNotNull('node_id')
                    ->orWhere('status', '!=', VideoStatus::PENDING->value);
            })
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_heartbeat_at')->where('updated_at', '<', $threshold);
                    });
            })
            ->get();

        foreach ($stuck as $video) {
            try {
                $this->recover($video, $maxAttempts);
            } catch (\Throwable $e) {
                Log::error('Failed to reap stuck video', ['video' => $video->id, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }

    private function recover(Video $video, int $maxAttempts): void
    {
        // Free the slot on the (presumed dead) node now, so capacity recovers
        // immediately instead of waiting for the slot TTL.
        Node::find($video->node_id)?->releaseSlot($video->id);

        if ($video->dispatch_attempts >= $maxAttempts) {
            $this->failPermanently($video);

            return;
        }

        $this->requeue($video);
    }

    /**
     * Reset the video and its derived streams so the dispatcher picks it up again
     * on a healthy node. The original source stream is preserved.
     */
    private function requeue(Video $video): void
    {
        $video->streams()
            ->where('type', '!=', 'original')
            ->update([
                'status' => VideoStatus::PENDING->value,
                'progress' => 0,
                'started_at' => null,
                'completed_at' => null,
                'error_log' => null,
            ]);

        $video->forceFill([
            'status' => VideoStatus::PENDING->value,
            'node_id' => null,
            'last_heartbeat_at' => null,
            'dispatch_attempts' => $video->dispatch_attempts + 1,
        ])->save();

        Log::warning('Re-queued stuck video', ['video' => $video->id, 'attempt' => $video->dispatch_attempts]);

        activity('video')
            ->performedOn($video)
            ->causedBy($video->user)
            ->withProperties(['dispatch_attempts' => $video->dispatch_attempts])
            ->event('video_requeued')
            ->log("Video re-queued after a worker/node became unresponsive (attempt {$video->dispatch_attempts})");
    }

    private function failPermanently(Video $video): void
    {
        $queue = $video->node?->resolveQueue();

        $video->markAsFailed();

        if ($queue) {
            CleanupVideoResourcesJob::dispatch($video->ulid, $video->dispatch_attempts)->onQueue($queue);
        }

        Log::error('Gave up on stuck video after exhausting attempts', [
            'video' => $video->id,
            'dispatch_attempts' => $video->dispatch_attempts,
        ]);
    }
}
