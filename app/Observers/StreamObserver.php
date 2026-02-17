<?php

namespace App\Observers;

use App\Models\Stream;
use Exception;
use Illuminate\Support\Facades\Storage;

class StreamObserver
{
    public function deleting(Stream $stream): void
    {
        if ($stream->path && Storage::exists($stream->path)) {
            if (!Storage::delete($stream->path)) {
                throw new Exception("Delete stream $stream->id failed");
            }
        }

        $children = $stream->children()->get();

        foreach ($children as $child) {
            $child->delete();
        }
    }
}
