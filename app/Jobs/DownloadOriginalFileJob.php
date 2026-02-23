<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DownloadOriginalFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {
    }

    public function handle(): void
    {
        $inputPath = $this->originalPath;

        if (!Storage::exists($inputPath)) {
            throw new Exception("Original file $inputPath does not exist in storage");
        }

        // Path includes video ULID as root
        $inputLocalPath = Storage::disk('tmp')->path($inputPath);

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
