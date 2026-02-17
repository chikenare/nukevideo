<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\ThumbnailService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExtractThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $videoId
    ) {
    }

    public function handle(): void
    {
        $video = Video::find($this->videoId);

        if (!$video) {
            throw new Exception("Video {$this->videoId} not found");
        }

        $originalStream = $video->streams()->where('type', 'original')->first();

        if (!$originalStream) {
            throw new Exception("Original stream not found for video {$video->id}");
        }

        $inputPath = $originalStream->path;
        $inputLocalPath = Storage::disk('local')->path($inputPath);

        if (!file_exists($inputLocalPath)) {
            throw new Exception("Local file $inputLocalPath not found. File should have been downloaded before processing.");
        }

        $thumbnailService = new ThumbnailService();

        $thumbnailFilename = $video->ulid . '/thumbnail.jpg';
        $thumbnailLocalPath = Storage::disk('local')->path($thumbnailFilename);

        $percentage = 30;
        $timeInSeconds = ($percentage / 100) * $video->duration;

        $thumbnailService->extractThumbnail($inputLocalPath, $thumbnailLocalPath, $timeInSeconds);

        $storagePath = $thumbnailService->uploadThumbnail($thumbnailLocalPath, $thumbnailFilename);

        $video->update(['thumbnail_path' => $storagePath]);
    }
}
