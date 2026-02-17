<?php

namespace App\Jobs;

use App\Models\Video;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadOriginalFileJob implements ShouldQueue
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

        if (!Storage::exists($inputPath)) {
            throw new Exception("Original file $inputPath does not exist in storage");
        }

        // Path includes video ULID as root
        $inputLocalPath = Storage::disk('local')->path($inputPath);

        // Check if already downloaded
        if (file_exists($inputLocalPath)) {
            return;
        }

        // Create directory if not exists
        $outputDir = dirname($inputLocalPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $sourceStream = null;
        $destStream = null;

        try {
            $sourceStream = Storage::readStream($inputPath);
            if (!$sourceStream) {
                throw new Exception("Failed to open source stream for $inputPath");
            }

            $destStream = fopen($inputLocalPath, 'w');
            if (!$destStream) {
                throw new Exception("Failed to open destination file $inputLocalPath");
            }

            stream_copy_to_stream($sourceStream, $destStream);

        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($destStream)) {
                fclose($destStream);
            }
        }
    }
}
