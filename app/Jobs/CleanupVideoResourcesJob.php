<?php

namespace App\Jobs;

use App\Models\Node;
use App\Models\Video;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupVideoResourcesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $videoUlid
    ) {}

    public function handle(): void
    {
        Log::info('CleanupVideoResources started', ['ulid' => $this->videoUlid]);

        $video = Video::where('ulid', $this->videoUlid)->first();

        if ($video?->node_id) {
            Node::find($video->node_id)?->releaseSlot($video->id);
            $video->update(['node_id' => null]);
        }

        $video?->streams()->where('type', 'original')->first()?->delete();

        $disk = Storage::disk('tmp');

        if ($disk->exists($this->videoUlid)) {
            if (! $disk->deleteDirectory($this->videoUlid)) {
                Log::error('Failed to cleanup video resources', ['ulid' => $this->videoUlid]);
                throw new Exception("Failed to cleanup video resources {$this->videoUlid}");
            }
        }
    }
}
