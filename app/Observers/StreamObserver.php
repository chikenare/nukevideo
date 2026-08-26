<?php

namespace App\Observers;

use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Stream;
use App\Services\UppyS3Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StreamObserver
{
    public function deleting(Stream $stream): void
    {
        foreach ($this->keysFor($stream) as $key) {
            if (Storage::exists($key) && ! Storage::delete($key)) {
                Log::warning("Failed to delete storage file for stream {$stream->id}: {$key}");
            }
        }

        if ($stream->type === 'original') {
            // The upload metadata outlives the object on purpose (it is what lets a lost bucket
            // webhook be replayed, {@see PruneScratchJob}) — but an original whose row is gone
            // must not be replayable: if the delete above failed and left the object behind, the
            // sweep would re-ingest it and hand the user back the video they just deleted.
            app(UppyS3Service::class)->forgetUploadMeta($stream->path);

            return;
        }

        // The packaged segments are this stream's own, not the video's: a re-probe replaces the
        // stream under a new ULID and the video keeps living, so nothing else ever reclaims the
        // old directory. A video delete wipes the whole prefix anyway; this is idempotent with it.
        if ($video = $stream->video) {
            Storage::deleteDirectory($stream->segmentsPath($video));
        }
    }

    /**
     * `path` is the authoritative key only for an `original`, which
     * {@see CleanupVideoResourcesJob::archiveOriginal} rewrites when it files the upload
     * away. For every other type `path` just carries the filename and the real object lives in the
     * video's download zone, so both keys are tried — otherwise deleting a stream silently deletes
     * nothing and leaks the heaviest objects we store.
     *
     * @return list<string>
     */
    private function keysFor(Stream $stream): array
    {
        $keys = array_filter([$stream->path]);

        if ($stream->type !== 'original' && $video = $stream->video) {
            $keys[] = $stream->storedPath($video);
        }

        return array_values(array_unique($keys));
    }
}
