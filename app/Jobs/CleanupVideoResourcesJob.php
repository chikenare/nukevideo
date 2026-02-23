<?php

namespace App\Jobs;

use App\Models\Video;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CleanupVideoResourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $videoUlid
    ) {}

    public function handle(): void
    {
        //Delete original file
        Video::where('ulid', $this->videoUlid)
            ->first()
            ?->streams()->where('type', 'original')->first()
            ?->delete();

        $disk = Storage::disk('tmp');

        if ($disk->exists($this->videoUlid)) {
            if (!$disk->deleteDirectory($this->videoUlid)) {
                throw new Exception("Failed to cleanup video resources {$this->videoUlid}");
            }
        }
    }
}
