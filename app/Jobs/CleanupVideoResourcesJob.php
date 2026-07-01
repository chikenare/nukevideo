<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
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
        // A missing video (user-deleted) still proceeds — the mirror is orphaned either way.
        if ($video && in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            Log::info('Cleanup skipped: video still active', ['ulid' => $this->videoUlid, 'status' => $video->status]);

            return;
        }

        // Safety net for runs that never reached finalizeVideoIfReady. Only our own subtrees
        // (the store reuses the default bucket), never the whole `{ulid}/` prefix.
        Storage::disk('chunks')->deleteDirectory(Video::chunksDirFor($this->videoUlid));
        Storage::disk('chunks')->deleteDirectory("{$this->videoUlid}/".Video::SOURCE_DIR);
        Storage::disk('chunks')->deleteDirectory("{$this->videoUlid}/".Video::FINAL_DIR);

        // The uploaded source is only reclaimed after a SUCCESSFUL run. A FAILED video keeps it
        // for the PruneStaleVideos window (24h) — same policy as reaper/chunk failures — so the
        // user's only copy isn't destroyed the moment packaging hiccups.
        if ($video?->status === VideoStatus::COMPLETED->value) {
            // Deleting the stream row drops the S3 source via StreamObserver.
            $video->streams()->where('type', 'original')->first()?->delete();
        }
    }
}
