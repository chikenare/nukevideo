<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Process;

class ThumbnailService
{
    public function extractThumbnail(
        string $inputPath,
        string $outputPath,
        int $timeInSeconds = 1,
    ): string {
        if (! file_exists($inputPath)) {
            throw new Exception("Input video file not found: $inputPath");
        }

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $process = Process::timeout(60)->run([
            'ffmpeg',
            '-hide_banner',
            '-y',
            '-ss',
            (string) $timeInSeconds,
            '-i',
            $inputPath,
            '-vframes',
            '1',
            '-vf',
            'scale=640:-2',
            '-q:v',
            '2',
            $outputPath,
        ]);

        if (! $process->successful()) {
            throw new Exception('Failed to extract thumbnail: '.$process->errorOutput());
        }

        if (! file_exists($outputPath)) {
            throw new Exception("Thumbnail file was not created at: $outputPath");
        }

        return $outputPath;
    }
}
