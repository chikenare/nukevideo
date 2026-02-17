<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ThumbnailService
{
    /**
     * Extract a thumbnail from a video file
     *
     * @param string $inputPath Local path to the input video file
     * @param string $outputPath Local path where thumbnail will be saved
     * @param int $timeInSeconds Time position to extract frame (default: 1 second)
     * @return string The output path of the generated thumbnail
     * @throws Exception
     */
    public function extractThumbnail(
        string $inputPath,
        string $outputPath,
        int $timeInSeconds = 1,
    ): string {
        // Ensure input file exists
        if (!file_exists($inputPath)) {
            throw new Exception("Input video file not found: $inputPath");
        }

        // Create output directory if it doesn't exist
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = $this->buildThumbnailCommand($inputPath, $outputPath, $timeInSeconds);

        $process = Process::timeout(60)->run($command);

        if (!$process->successful()) {
            throw new Exception("Failed to extract thumbnail: " . $process->errorOutput());
        }

        // Verify thumbnail was created
        if (!file_exists($outputPath)) {
            throw new Exception("Thumbnail file was not created at: $outputPath");
        }

        return $outputPath;
    }

    /**
     * Build the FFmpeg command for thumbnail extraction
     *
     * @param string $inputPath
     * @param string $outputPath
     * @param int $timeInSeconds
     * @return string
     */
    private function buildThumbnailCommand(
        string $inputPath,
        string $outputPath,
        int $timeInSeconds,
    ): string {
        $args = [
            'ffmpeg',
            '-hide_banner',
            '-y',
            '-ss',
            $timeInSeconds,
            '-i',
            "\"$inputPath\"",
            '-vframes',
            '1',
            '-vf',
            "scale=1280:-1",
            '-q:v',
            '2',
            "\"$outputPath\"",
        ];

        return implode(' ', $args);
    }

    /**
     * Upload thumbnail to storage and return the storage path
     *
     * @param string $localPath Local path to the thumbnail file
     * @param string $storagePath Storage path where thumbnail should be saved
     * @return string The storage path
     * @throws Exception
     */
    public function uploadThumbnail(string $localPath, string $storagePath): string
    {
        if (!file_exists($localPath)) {
            throw new Exception("Thumbnail file not found: $localPath");
        }

        $fileResource = fopen($localPath, 'r');

        if (!$fileResource) {
            throw new Exception("Failed to open thumbnail file: $localPath");
        }

        try {
            $uploaded = Storage::put($storagePath, $fileResource);

            if (!$uploaded) {
                throw new Exception("Failed to upload thumbnail to storage: $storagePath");
            }

            return $storagePath;
        } finally {
            if (is_resource($fileResource)) {
                fclose($fileResource);
            }
        }
    }
}
