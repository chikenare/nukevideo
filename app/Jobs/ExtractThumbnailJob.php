<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\ThumbnailService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractThumbnailJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    public function handle(): void
    {
        Log::info('ExtractThumbnail started', ['video' => $this->videoId]);

        try {
            $video = Video::find($this->videoId);

            if (! $video) {
                throw new Exception("Video {$this->videoId} not found");
            }

            $inputLocalPath = Storage::disk('tmp')->path($this->originalPath);

            if (! file_exists($inputLocalPath)) {
                throw new Exception("Local file $inputLocalPath not found. File should have been downloaded before processing.");
            }

            $thumbnailService = new ThumbnailService;

            $thumbnailFilename = $video->ulid.'/thumbnail.jpg';
            $thumbnailLocalPath = Storage::disk('tmp')->path($thumbnailFilename);

            $percentage = 30;
            $timeInSeconds = ($percentage / 100) * $video->duration;

            $thumbnailService->extractThumbnail($inputLocalPath, $thumbnailLocalPath, $timeInSeconds);
        } catch (Throwable $e) {
            Log::warning("Thumbnail extraction failed for video {$this->videoId}: {$e->getMessage()}");

            $video = Video::with('user')->find($this->videoId);

            if ($video) {
                activity('video')
                    ->performedOn($video)
                    ->causedBy($video->user)
                    ->withProperties(['error' => $e->getMessage()])
                    ->event('thumbnail_failed')
                    ->log("Thumbnail extraction failed: {$e->getMessage()}");
            }
        }
    }
}
