<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * Backstop that reclaims local staging and chunk-store leftovers from failed or crashed runs
 * (the success path drops a video's `{ulid}` on completion). A `{ulid}` is removed only when
 * its video is terminal (or gone) and past the grace window, so an in-flight run is never wiped.
 */
class PruneScratchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** Leave anything that became terminal (or was last written) within this window. */
    private const GRACE_SECONDS = 1800; // 30 min

    /** Uploads that never became a video are the user's only copy, so reclaim them far more
     *  conservatively than internal scratch — a full day for any manual recovery/retry. */
    private const UPLOAD_GRACE_SECONDS = 86400; // 24 h

    public function handle(): void
    {
        $this->pruneLocalScratch();
        $this->pruneChunkStore();
        $this->pruneUploadTmp();
    }

    private function pruneLocalScratch(): void
    {
        $disk = Storage::disk('local');
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);

        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.$name;

            // Our staging dirs are named with the video ULID; never touch anything else.
            if (! is_dir($absolute) || ! Str::isUlid($name)) {
                continue;
            }

            $video = Video::where('ulid', $name)->first();

            if ($video) {
                if ($this->withinGrace($video)) {
                    continue;
                }
            } elseif ($this->tooYoung($absolute)) {
                continue; // orphaned but recently written — be safe
            }

            if ($disk->deleteDirectory($name)) {
                Log::info('Pruned orphaned staging', ['ulid' => $name, 'video' => $video?->id]);
            }
        }
    }

    private function pruneChunkStore(): void
    {
        $disk = Storage::disk('chunks');

        foreach ($disk->directories() as $path) {
            $name = basename($path);

            if (! Str::isUlid($name)) {
                continue;
            }

            $video = Video::where('ulid', $name)->first();

            // No mtime on object storage, so an orphan is dropped outright; a known video is
            // kept until terminal + past the grace window.
            if ($video && $this->withinGrace($video)) {
                continue;
            }

            // Only our own subtrees (the store reuses the default bucket), never the whole prefix.
            // FINAL_DIR (single-pass tracks) is included so a video that failed before
            // finalizeVideoIfReady — the only other place that clears it — doesn't leak it.
            $pruned = false;
            foreach ([Video::CHUNKS_DIR, Video::SOURCE_DIR, Video::FINAL_DIR] as $sub) {
                $pruned = $disk->deleteDirectory("{$name}/{$sub}") || $pruned;
            }

            if ($pruned) {
                Log::info('Pruned orphaned internal store', ['ulid' => $name, 'video' => $video?->id]);
            }
        }
    }

    /**
     * Reclaim raw uploads orphaned when ingest failed before a Video row existed (OnVideoUploaded
     * retains them "for age-based GC" — this is that GC). An upload that ingested successfully is
     * its `original` stream's `path` until cleanup, so those are skipped; the rest are keyed by a
     * ULID filename whose embedded timestamp gives the age with no object mtime.
     */
    private function pruneUploadTmp(): void
    {
        $folder = (string) config('uppy-s3-multipart-upload.s3.bucket.folder');

        if ($folder === '') {
            return;
        }

        $disk = Storage::disk('s3');
        $keys = $disk->files($folder);

        if ($keys === []) {
            return;
        }

        $referenced = array_flip(Stream::whereIn('path', $keys)->pluck('path')->all());

        foreach ($keys as $key) {
            if (isset($referenced[$key])) {
                continue; // belongs to a live video's original stream
            }

            $ulid = pathinfo($key, PATHINFO_FILENAME);

            if (! Str::isUlid($ulid) || $this->uploadWithinGrace($ulid)) {
                continue;
            }

            if ($disk->delete($key)) {
                Log::info('Pruned orphaned upload', ['key' => $key]);
            }
        }
    }

    /** True while an upload's ULID timestamp is younger than the upload grace window. */
    private function uploadWithinGrace(string $ulid): bool
    {
        try {
            $createdAt = Ulid::fromString($ulid)->getDateTime()->getTimestamp();
        } catch (\Throwable) {
            return true; // unparseable timestamp — never delete on a guess
        }

        return $createdAt > now()->subSeconds(self::UPLOAD_GRACE_SECONDS)->getTimestamp();
    }

    /** True while the video is still active or only just became terminal. */
    private function withinGrace(Video $video): bool
    {
        if (in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return true;
        }

        return (bool) $video->updated_at?->gt(now()->subSeconds(self::GRACE_SECONDS));
    }

    private function tooYoung(string $absolutePath): bool
    {
        $mtime = @filemtime($absolutePath);

        return $mtime === false || (time() - $mtime) < self::GRACE_SECONDS;
    }
}
