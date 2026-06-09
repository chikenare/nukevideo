<?php

namespace App\Jobs;

use App\Models\Node;
use App\Models\Video;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupVideoResourcesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $videoUlid
    ) {}

    public function handle(): void
    {
        Log::info('CleanupVideoResources started', ['ulid' => $this->videoUlid]);

        $video = Video::where('ulid', $this->videoUlid)->first();

        // Terminal-only cleanup. If the video is back to an active status, the
        // reaper has re-queued it and a newer run now owns its resources; a
        // resurrected old chain's cleanup must not release the slot, drop the
        // original stream, or delete tmp the new run is using. (This single guard
        // is what makes the recovery safe without per-job fencing.)
        if ($video && in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            Log::info('Cleanup skipped: video re-queued and active', ['ulid' => $this->videoUlid, 'status' => $video->status]);

            return;
        }

        $disk = Storage::disk('tmp');

        if ($video?->node_id) {
            Node::find($video->node_id)?->releaseSlot($video->id);
            $video->update(['node_id' => null]);
        }

        $original = $video?->streams()->where('type', 'original')->first();
        $originalPath = $original?->path;

        // Removing the stream record deletes the source copy on the default disk
        // (S3) via the StreamObserver.
        $original?->delete();

        // The downloaded source lives under its storage key (tmp-videos/...), NOT
        // under the video's ULID directory, so the directory delete below misses
        // it — leaking one original per video on local disk. Remove it here.
        if ($originalPath && $disk->exists($originalPath)) {
            $disk->delete($originalPath);
        }

        if ($disk->exists($this->videoUlid)) {
            if (! $disk->deleteDirectory($this->videoUlid)) {
                Log::error('Failed to cleanup video resources', ['ulid' => $this->videoUlid]);
                throw new Exception("Failed to cleanup video resources {$this->videoUlid}");
            }
        }
    }
}
