<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
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
use Throwable;

class DownloadOriginalFileJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    public function handle(): void
    {
        Log::info('DownloadOriginalFile started', ['video' => $this->videoId, 'path' => $this->originalPath]);

        $inputPath = $this->originalPath;

        if (! Storage::exists($inputPath)) {
            Log::error('Original file does not exist in storage', ['path' => $inputPath, 'video' => $this->videoId]);
            throw new Exception("Original file $inputPath does not exist in storage");
        }

        Video::where('id', $this->videoId)
            ->update(['status' => VideoStatus::DOWNLOADING->value]);

        // Path includes video ULID as root
        $inputLocalPath = Storage::disk('tmp')->path($inputPath);

        // Check if already downloaded
        if (file_exists($inputLocalPath)) {
            return;
        }

        // Create directory if not exists
        $outputDir = dirname($inputLocalPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $sourceStream = null;
        $destStream = null;

        try {
            $sourceStream = Storage::readStream($inputPath);
            if (! $sourceStream) {
                Log::error('Failed to open source stream', ['path' => $inputPath, 'video' => $this->videoId]);
                throw new Exception("Failed to open source stream for $inputPath");
            }

            $destStream = fopen($inputLocalPath, 'w');
            if (! $destStream) {
                Log::error('Failed to open destination file', ['path' => $inputLocalPath, 'video' => $this->videoId]);
                throw new Exception("Failed to open destination file $inputLocalPath");
            }

            stream_copy_to_stream($sourceStream, $destStream);

            Video::where('id', $this->videoId)
                ->update(['status' => VideoStatus::RUNNING->value]);
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($destStream)) {
                fclose($destStream);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with('user')->find($this->videoId);

        if ($video) {
            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->withProperties(['error' => $e->getMessage()])
                ->event('download_failed')
                ->log("Original file download failed: {$e->getMessage()}");
        }
    }
}
