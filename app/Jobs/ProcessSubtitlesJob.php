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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ProcessSubtitlesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 700;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {
    }

    public function handle(): void
    {
        $video = Video::with([
            'streams' => function ($query) {
                $query->where('type', 'subtitle');
            }
        ])->find($this->videoId);

        if (!$video) {
            throw new Exception("Video {$this->videoId} not found");
        }

        $subtitleStreams = $video->streams;

        if ($subtitleStreams->isEmpty()) {
            return;
        }

        try {
            $inputLocalPath = Storage::disk('tmp')->path($this->originalPath);
            if (!file_exists($inputLocalPath)) {
                throw new Exception("Original video file not found at: $inputLocalPath");
            }

            // Mark all streams as running
            foreach ($subtitleStreams as $stream) {
                $stream->update([
                    'status' => VideoStatus::RUNNING->value,
                    'started_at' => now()
                ]);
            }

            $this->processAllSubtitles($subtitleStreams, $inputLocalPath);

            foreach ($subtitleStreams as $stream) {
                // Stream path already includes video ULID as root
                $localPath = Storage::disk('tmp')->path($stream->path);

                if (file_exists($localPath)) {
                    $this->uploadSubtitle($stream, $localPath);

                    $stream->update([
                        'size' => filesize($localPath),
                        'status' => VideoStatus::COMPLETED->value,
                        'progress' => 100,
                        'completed_at' => now()
                    ]);
                } else {
                    throw new Exception("Subtitle file not found after extraction: $localPath");
                }
            }

        } catch (Exception $e) {
            Log::error('Subtitle job processing failed', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark all subtitle streams as failed
            $video->streams()->where('type', 'subtitle')->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => $e->getMessage(),
            ]);

            throw $e;

        } finally {
            // No cleanup needed - files remain in video ULID folder
        }
    }

    /**
     * Process all subtitle streams in a single FFmpeg command
     */
    private function processAllSubtitles($subtitleStreams, string $inputLocalPath): void
    {
        $commandParts = ['ffmpeg', '-hide_banner', '-y', '-i', "\"{$inputLocalPath}\""];

        // Build command with all subtitle outputs
        foreach ($subtitleStreams as $stream) {
            $streamIndex = $stream->meta['index'] ?? 0;

            // Stream path already includes video ULID as root
            $streamLocalPath = Storage::disk('tmp')->path($stream->path);

            // Create output directory if not exists
            $outputDir = dirname($streamLocalPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Add map and output for this subtitle
            $commandParts[] = "-map 0:{$streamIndex}";
            $commandParts[] = "-c:s webvtt";
            $commandParts[] = "\"{$streamLocalPath}\"";
        }

        $command = implode(' ', $commandParts);

        // Execute FFmpeg command
        $process = Process::timeout(600)->run($command);

        if (!$process->successful()) {
            throw new Exception($process->errorOutput());
        }
    }

    /**
     * Upload subtitle to storage
     */
    private function uploadSubtitle($stream, string $localPath): void
    {
        $stream->update(['status' => VideoStatus::UPLOADING->value]);

        $fileResource = fopen($localPath, 'r');

        if (!$fileResource) {
            throw new Exception("Failed to open subtitle file at: $localPath");
        }

        $uploaded = Storage::put($stream->path, $fileResource);

        if (!$uploaded) {
            throw new Exception("Failed to upload subtitle to S3: {$stream->path}");
        }

        if (is_resource($fileResource)) {
            fclose($fileResource);
        }
    }



    /**
     * Handle a job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Subtitle job permanently failed after all retries', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        $video = Video::find($this->videoId);
        if ($video) {
            $video->streams()->where('type', 'subtitle')->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => 'Job failed: ' . $exception->getMessage(),
            ]);
        }

        $this->delete();
    }
}
