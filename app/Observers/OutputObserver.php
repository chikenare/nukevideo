<?php

namespace App\Observers;

use App\Models\Output;
use Illuminate\Support\Facades\Storage;

class OutputObserver
{
    /**
     * An output owns its manifests and nothing else: the segments belong to the streams, which
     * are shared across outputs. The manifests sit in the playback zone — the one directory every
     * playback token authorizes — so one left behind by a deleted output stays fetchable for as
     * long as the video lives, listing segment directories that may no longer exist.
     *
     * Matched by filename prefix rather than enumerated by format and cap: the capped variants
     * are minted from the packaged heights ({@see Output::manifestFile}), which this observer has
     * no reliable way to recompute after the streams are gone.
     */
    public function deleting(Output $output): void
    {
        $video = $output->video;

        if ($video) {
            $prefix = "{$output->packagePrefix()}/{$output->ulid}.";
            $manifests = array_filter(Storage::files($output->packagePrefix()), fn (string $key) => str_starts_with($key, $prefix));

            if ($manifests !== []) {
                Storage::delete(array_values($manifests));
            }
        }

        // Transient, TTL-bound, but a re-probe seeds a fresh hash under a new output id and this one
        // would otherwise sit in Redis for a day for nothing.
        $output->clearChunkProgress();
    }
}
