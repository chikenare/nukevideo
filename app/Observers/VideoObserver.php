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
        DeleteResourceWithPath::dispatch($video->ulid);
    }
}
