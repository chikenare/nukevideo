<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Console\Command;

/**
 * Recovery backstop for video status: Horizon recovers the queue, but a worker killed
 * mid-flight leaves a video stuck in an active state with no job to complete or fail it.
 * Fails terminally any active video whose heartbeat has gone stale.
 */
class ReapStuckVideos extends Command
{
    protected $signature = 'videos:reap
        {--minutes=20 : Minutes without a heartbeat before an active video is considered stuck}';

    protected $description = 'Fail videos whose worker died mid-processing (detected via stale heartbeat)';

    private const STALLABLE = [
        VideoStatus::RUNNING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::UPLOADING->value,
    ];

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

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
            $this->warn("Video {$video->id} failed (no heartbeat for >{$this->option('minutes')} minutes).");
        }

        $this->info("Reap complete: {$failed} failed.");

        return self::SUCCESS;
    }
}
