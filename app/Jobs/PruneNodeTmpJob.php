<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims local tmp left behind when a worker/node died mid-processing and its
 * chain never reached {@see CleanupVideoResourcesJob}.
 *
 * Dispatched to a specific node's queue so it runs ON that node and sees the
 * node-local tmp volume. It deletes only entries that are (a) not referenced by a
 * video still active on THIS node and (b) older than a grace window — so it can
 * never remove in-flight work, even for very long encodes (those are protected by
 * the DB active-check, not by mtime).
 */
class PruneNodeTmpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** Safety margin: never touch anything younger than this. */
    private const GRACE_SECONDS = 3600;

    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    private const UPLOADS_FOLDER = 'tmp-videos';

    public function __construct(
        public int $nodeId,
    ) {}

    public function handle(): void
    {
        $disk = Storage::disk('tmp');

        // [ulid => id] of every video still legitimately occupying this node.
        $active = Video::whereIn('status', Video::ACTIVE_STATUSES)
            ->where('node_id', $this->nodeId)
            ->pluck('id', 'ulid');

        $this->pruneRenditionDirs($disk, $active->keys()->all());
        $this->pruneOriginals($disk, $active->values()->all());
    }

    /** Per-video output directories, named with the video ULID. */
    private function pruneRenditionDirs(Filesystem $disk, array $activeUlids): void
    {
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);

        foreach (@scandir($root) ?: [] as $name) {
            if (! preg_match(self::ULID_PATTERN, $name) || in_array($name, $activeUlids, true)) {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.$name;

            if (! is_dir($absolute) || $this->tooYoung($absolute)) {
                continue;
            }

            if ($disk->deleteDirectory($name)) {
                Log::info('Pruned orphaned rendition dir', ['node' => $this->nodeId, 'ulid' => $name]);
            }
        }
    }

    /** Downloaded source files under the uploads folder (keyed by storage key). */
    private function pruneOriginals(Filesystem $disk, array $activeVideoIds): void
    {
        $activeOriginals = Stream::where('type', 'original')
            ->whereIn('video_id', $activeVideoIds)
            ->pluck('path')
            ->all();

        foreach ($disk->files(self::UPLOADS_FOLDER) as $relativePath) {
            if (in_array($relativePath, $activeOriginals, true) || $this->tooYoung($disk->path($relativePath))) {
                continue;
            }

            if ($disk->delete($relativePath)) {
                Log::info('Pruned orphaned original', ['node' => $this->nodeId, 'path' => $relativePath]);
            }
        }
    }

    private function tooYoung(string $absolutePath): bool
    {
        $mtime = @filemtime($absolutePath);

        return $mtime === false || (time() - $mtime) < self::GRACE_SECONDS;
    }
}
