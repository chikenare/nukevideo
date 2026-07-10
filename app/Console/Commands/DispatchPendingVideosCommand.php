<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchPendingVideosCommand extends Command
{
    protected $signature = 'videos:dispatch';

    protected $description = 'Dispatch pending videos into the chunk-based encoding pipeline, up to the number of available worker slots';

    private const QUEUE = 'video-processing';

    /** Statuses that mean a video is occupying a worker slot. */
    private const IN_FLIGHT = [
        VideoStatus::RUNNING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::UPLOADING->value,
    ];

    public function handle(): void
    {
        // Each worker node processes one video at a time; only dispatch as many as free slots.
        $workerCount = Node::worker()->active()->count();

        if ($workerCount === 0) {
            return;
        }

        $inFlight = Video::whereIn('status', self::IN_FLIGHT)->count();
        $available = $workerCount - $inFlight;

        if ($available <= 0) {
            return;
        }

        Video::where('status', VideoStatus::PENDING->value)
            ->oldest('created_at')
            ->limit($available)
            ->get()
            ->each(function (Video $video) {
                $originalPath = $video->streams()->where('type', 'original')->value('path');

                if (! $originalPath) {
                    $video->markAsFailed();

                    return;
                }

                // Atomically claim (PENDING → RUNNING) so a concurrent tick can't double-dispatch.
                $claimed = Video::whereKey($video->id)
                    ->where('status', VideoStatus::PENDING->value)
                    ->update([
                        'status' => VideoStatus::RUNNING->value,
                        'last_heartbeat_at' => now(),
                    ]);

                if (! $claimed) {
                    return;
                }

                // Revert on dispatch failure so the video isn't stranded in RUNNING with no job.
                try {
                    PrepareVideoJob::dispatch($video->id, $originalPath)
                        ->onQueue(self::QUEUE);
                } catch (\Throwable $e) {
                    Log::error('Failed to dispatch segment job; reverting to pending', ['video' => $video->id, 'error' => $e->getMessage()]);

                    Video::whereKey($video->id)
                        ->where('status', VideoStatus::RUNNING->value)
                        ->update(['status' => VideoStatus::PENDING->value]);
                }
            });
    }
}
