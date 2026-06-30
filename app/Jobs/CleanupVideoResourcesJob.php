<?php

namespace App\Jobs;

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
        public string $videoUlid,
    ) {}

    public function handle(): void
    {
        Log::info('CleanupVideoResources started', ['ulid' => $this->videoUlid]);

        $video = Video::where('ulid', $this->videoUlid)->first();

        // Destructive (deletes the original stream + S3 source), so skip while still active.
        // A missing video (user-deleted) still proceeds — tmp is orphaned either way.
        if ($video && in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            Log::info('Cleanup skipped: video still active', ['ulid' => $this->videoUlid, 'status' => $video->status]);

            return;
        }

        $disk = Storage::disk('tmp');

        // Safety net for runs that never reached finalizeVideoIfReady. Only our own subtrees
        // (the store reuses the default bucket), never the whole `{ulid}/` prefix.
        Storage::disk('chunks')->deleteDirectory(Video::chunksDirFor($this->videoUlid));
        Storage::disk('chunks')->deleteDirectory("{$this->videoUlid}/".Video::SOURCE_DIR);

        $original = $video?->streams()->where('type', 'original')->first();
        $originalPath = $original?->path;

        // Deleting the stream row drops the S3 source via StreamObserver.
        $original?->delete();

        // The source lives under its storage key (tmp-videos/...), not the ULID dir, so the
        // directory delete below misses it — remove it here.
        if ($originalPath && $disk->exists($originalPath)) {
            $disk->delete($originalPath);
        }

        if ($disk->exists($this->videoUlid)) {
            if (! $disk->deleteDirectory($this->videoUlid)) {
                throw new Exception("Failed to cleanup video resources {$this->videoUlid}");
            }
        }
    }
}
