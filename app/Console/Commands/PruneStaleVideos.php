<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneStaleVideos extends Command
{
    protected $signature = 'videos:prune {--hours=24 : Hours before a failed video is considered stale}';

    protected $description = 'Remove videos that have been in a failed state for too long';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $threshold = Carbon::now()->subHours($hours);

        // Terminal failures only: deleting a stale active video (queued or reaper-stuck) and
        // its source would turn a long backlog into silent data loss.
        $staleVideos = Video::where('status', VideoStatus::FAILED->value)
            ->where('updated_at', '<', $threshold)
            ->get();

        $staleActive = Video::whereIn('status', Video::ACTIVE_STATUSES)
            ->where('updated_at', '<', $threshold)
            ->count();

        if ($staleActive > 0) {
            $this->warn("{$staleActive} active video(s) older than {$hours}h left untouched (waiting for capacity or reaper recovery).");
        }

        if ($staleVideos->isEmpty()) {
            $this->info('No stale videos found.');

            return;
        }

        $this->table(
            ['ID', 'Name', 'Status', 'Last Updated'],
            $staleVideos->map(fn (Video $video) => [
                $video->id,
                $video->name,
                $video->status->value ?? $video->status,
                $video->updated_at->diffForHumans(),
            ])
        );

        $count = $staleVideos->count();

        $staleVideos->each->delete();

        $this->info("Pruned {$count} stale video(s).");
    }
}
