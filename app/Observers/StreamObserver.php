<?php

namespace App\Observers;

use App\Models\Stream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StreamObserver
{
    public function deleting(Stream $stream): void
    {
        if ($stream->path && Storage::exists($stream->path)) {
            if (! Storage::delete($stream->path)) {
                Log::warning("Failed to delete storage file for stream {$stream->id}: {$stream->path}");
            }
        }
    }
}
