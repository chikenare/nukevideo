<?php

namespace App\Observers;

use App\Data\Video\UpdateVideoData;
use App\Jobs\DeleteResourceWithPath;
use App\Models\Video;
use App\Services\WebhookDispatcher;

class VideoObserver
{
    /**
     * The columns the API lets an integrator change ({@see UpdateVideoData}). The pipeline never
     * writes them, so guarding on them is what separates a real edit from the status, duration and
     * aspect-ratio writes a run performs — those already have their own events.
     */
    private const ANNOUNCED_COLUMNS = ['name', 'external_user_id', 'external_resource_id'];

    public function updated(Video $video): void
    {
        if ($video->wasChanged(self::ANNOUNCED_COLUMNS)) {
            WebhookDispatcher::forVideo('video.updated', $video);
        }
    }

    public function deleting(Video $video): void
    {
        // Relations are gone by the time `deleted` fires, so snapshot them for the webhook payload.
        if ($video->external_resource_id) {
            $video->loadMissing(['outputs.streams', 'streams']);
        }

        // The DB cascade skips StreamObserver, and an original that was never archived still points
        // at the shared upload folder, outside the ULID dir — so delete it through Eloquent to let
        // the observer clean up that object. An archived one is covered by the sweep below too.
        // Announcing nothing: the video is disappearing, and `video.deleted` below says so.
        StreamObserver::withoutAnnouncing(
            fn () => $video->streams()->where('type', 'original')->get()->each->delete()
        );

        // A video deleted mid-encode (a FAILED one pruned while chunk jobs are still queued, an
        // operator deleting a stuck one) leaves batches whose remaining jobs would each load a
        // video that no longer exists. Cancelling them is what makes the batch drop those jobs;
        // otherwise they linger until `queue:prune-batches` a week later. The progress hashes are
        // TTL-bound but would sit in Redis for a day for nothing.
        $video->cancelEncodeBatches();
        $video->outputs()->get()->each->clearChunkProgress();

        // One prefix covers all four zones (`play/`, `download/`, `assets/`, `original/`), so a zone
        // added later cannot be forgotten here and leave its objects orphaned.
        DeleteResourceWithPath::dispatch($video->ulid);
    }

    public function deleted(Video $video): void
    {
        if (! $video->external_resource_id) {
            return;
        }

        WebhookDispatcher::forVideo('video.deleted', $video);
    }
}
