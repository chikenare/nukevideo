<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\NodeService;
use Illuminate\Console\Command;

/**
 * Recovery backstop for video status: Horizon recovers the queue, but a worker killed
 * mid-flight leaves a video stuck in an active state with no job to complete or fail it.
 * Fails terminally any active video whose heartbeat has gone stale.
 */
class ReapStuckVideos extends Command
{
    protected $signature = 'videos:reap
        {--minutes= : Minutes without a heartbeat before an active video is considered stuck; defaults to one queue redelivery window}';

    protected $description = 'Fail videos whose worker died mid-processing (detected via stale heartbeat)';

    private const STALLABLE = [
        VideoStatus::RUNNING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::UPLOADING->value,
    ];

    // The queue is the first line of recovery; this floor only applies if it reports no window.
    private const MIN_STALE_MINUTES = 20;

    public function handle(): int
    {
        $minutes = $this->staleMinutes();
        $threshold = now()->subMinutes($minutes);

        $stuck = Video::whereIn('status', self::STALLABLE)
            ->where(function ($q) use ($threshold) {
                $q->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_heartbeat_at')->where('updated_at', '<', $threshold);
                    });
            })
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck videos found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($stuck as $video) {
            $video->markAsFailed();
            $failed++;
            $this->warn("Video {$video->id} failed (no heartbeat for >{$minutes} minutes).");
        }

        $this->info("Reap complete: {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * A worker that dies takes its job's heartbeat with it, but the queue re-delivers that job
     * after `retry_after` and the batch carries on by itself. Reaping any sooner kills videos
     * the queue would have finished, so wait out one redelivery plus the job it hands back.
     * Read off {@see NodeService}, the authority on what workers run with — this command runs on
     * the scheduler host, whose own queue env is unrelated to theirs.
     */
    private function staleMinutes(): int
    {
        if ($given = $this->option('minutes')) {
            return (int) $given;
        }

        $window = NodeService::QUEUE_RETRY_AFTER + NodeService::WORKER_TIMEOUT;

        return (int) max(self::MIN_STALE_MINUTES, ceil($window / 60));
    }
}
