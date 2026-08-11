<?php

namespace App\Observers;

use App\Jobs\DeleteResourceWithPath;
use App\Models\Video;
use App\Services\WebhookDispatcher;

class VideoObserver
{
    public function deleting(Video $video): void
    {
        // Relations are gone by the time `deleted` fires, so snapshot them for the webhook payload.
        if ($video->external_resource_id) {
            $video->loadMissing(['outputs.streams', 'streams']);
        }

        // The DB cascade skips StreamObserver, and the original's source lives outside the ULID
        // dir, so delete it through Eloquent to let the observer clean up the storage object.
        $video->streams()->where('type', 'original')->get()->each->delete();

        DeleteResourceWithPath::dispatch($video->ulid);
        // Retained renditions are filed outside the video's own prefix, so they need their own
        // sweep — otherwise deleting a video would leave its heaviest files behind.
        DeleteResourceWithPath::dispatch(Video::processedPrefixFor($video->ulid));
    }

    public function deleted(Video $video): void
    {
        if (! $video->external_resource_id) {
            return;
        }

        WebhookDispatcher::forVideo('video.deleted', $video);
    }
}
