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

    // Light orchestration queue every worker drains, so prep runs even on a GPU-only fleet.
    private const QUEUE = 'orchestration';

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

        $dispatched = 0;

        Video::where('status', VideoStatus::PENDING->value)
            ->with('template')
            ->oldest('created_at')
            ->cursor()
            ->each(function (Video $video) use (&$dispatched, $available) {
                if ($dispatched >= $available) {
                    return false;
                }

                // Never claim a video the fleet cannot encode: its chunks would land on a hardware
                // queue nobody drains and it would hang until the reaper. Skipping leaves it
                // PENDING at no cost, and the next tick offers it again the moment the node is
                // back — where failing it would hand it to `videos:prune`, which deletes the video
                // and the user's only copy 24h later, over what is usually a reboot. Skipped
                // rather than returned early so one waiting GPU video cannot block the CPU queue
                // behind it.
                if ($video->template?->missingCapacity()) {
                    return;
                }

                $originalPath = $video->streams()->where('type', 'original')->value('path');

                if (! $originalPath) {
                    $video->markAsFailed('The uploaded source is missing: this video has no original stream to encode from.');

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

                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error('Failed to dispatch segment job; reverting to pending', ['video' => $video->id, 'error' => $e->getMessage()]);

                    Video::whereKey($video->id)
                        ->where('status', VideoStatus::RUNNING->value)
                        ->update(['status' => VideoStatus::PENDING->value]);
                }
            });
    }
}
