<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function handle(): void
    {
        $this->pruneLocalScratch();
        $this->pruneChunkStore();
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
            $pruned = false;
            foreach (['chunks', 'source'] as $sub) {
                $pruned = $disk->deleteDirectory("{$name}/{$sub}") || $pruned;
            }

            if ($pruned) {
                Log::info('Pruned orphaned internal store', ['ulid' => $name, 'video' => $video?->id]);
            }
        }
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
