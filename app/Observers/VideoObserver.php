<?php

namespace App\Observers;

use App\Jobs\DeleteResourceWithPath;
use App\Models\Node;
use App\Models\Video;
use Illuminate\Support\Str;

class VideoObserver
{
    public function creating($video)
    {
        $video->ulid = Str::ulid();
    }

    public function created(Video $video): void
    {
        //
    }

    public function updated(Video $video): void
    {
        //
    }

    public function deleting(Video $video): void
    {
        if ($video->node_id) {
            Node::find($video->node_id)?->releaseSlot($video->id);
        }

        // Streams are removed by the DB cascade, which never fires StreamObserver
        // — and the original's source object lives under tmp-videos/ on the
        // default disk, outside the ULID directory deleted below, so it leaked
        // for every video deleted before completion. Delete it through Eloquent
        // so the observer cleans up the storage object.
        $video->streams()->where('type', 'original')->get()->each->delete();

        DeleteResourceWithPath::dispatch($video->ulid);
    }
}
