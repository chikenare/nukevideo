<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneStaleVideos extends Command
{
    protected $signature = 'videos:prune {--hours=24 : Hours before a non-completed video is considered stale}';

    protected $description = 'Remove videos that have failed or been stuck in a non-completed state for too long';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $threshold = Carbon::now()->subHours($hours);

        $staleVideos = Video::where('status', '!=', VideoStatus::COMPLETED)
            ->where('updated_at', '<', $threshold)
            ->get();

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
