<?php

namespace App\Observers;

use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Stream;
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
