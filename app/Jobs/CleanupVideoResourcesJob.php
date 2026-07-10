<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\Video;
use App\Services\UppyS3Service;
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

        // Settles the source (reclaims it, or archives it under the video's prefix) and drops the
        // scratch tree, so skip while still active. A missing video (user-deleted) still proceeds —
        // the mirror is orphaned either way.
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
        if ($video?->status !== VideoStatus::COMPLETED->value) {
            return;
        }

        $original = $video->streams()->where('type', 'original')->first();

        if ($video->template?->keep_original ?? false) {
            $this->archiveOriginal($video, $original);

            return;
        }

        // Deleting the stream row drops the S3 source via StreamObserver.
        $original?->delete();
    }

    /**
     * File a retained source under the video's own prefix, out of the shared upload folder.
     * Server-side copy (the SDK switches to MultipartCopy past 5 GB), so no bytes cross the worker.
     *
     * Order matters. Once `path` moves, PruneScratchJob no longer sees the upload key as referenced
     * and would re-dispatch ingestion for it while its upload meta lives — a duplicate video. So the
     * meta is forgotten before the column moves, and every crash point in between is safe: the row
     * still references the old key until the copy is durable, and a leftover key ages out on its own.
     */
    private function archiveOriginal(Video $video, ?Stream $original): void
    {
        if (! $original) {
            return;
        }

        $destination = $original->archivePath($video);

        if ($original->path === $destination) {
            return;
        }

        $source = $original->path;
        $disk = Storage::disk('s3');

        if (! $disk->exists($destination)) {
            $disk->copy($source, $destination);
        }

        app(UppyS3Service::class)->forgetUploadMeta($source);
        $original->update(['path' => $destination]);
        $disk->delete($source);

        Log::info('Original retained per template', ['ulid' => $this->videoUlid, 'path' => $destination]);
    }
}
