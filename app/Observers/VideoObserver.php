<?php

namespace App\Observers;

use App\Jobs\DeleteResourceWithPath;
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
        // The DB cascade skips StreamObserver, and the original's source lives outside the ULID
        // dir, so delete it through Eloquent to let the observer clean up the storage object.
        $video->streams()->where('type', 'original')->get()->each->delete();

        DeleteResourceWithPath::dispatch($video->ulid);
    }
}
