<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\WebhookDispatcher;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class UploadStreamsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public function __construct(
        public int $videoId,
    ) {}

    public function handle(): void
    {
        Log::info('UploadStreams started', ['video' => $this->videoId]);

        $video = Video::findOrFail($this->videoId);

        $video->update(['status' => VideoStatus::UPLOADING->value]);

        $localDir = Storage::disk('tmp')->path($video->ulid);

        if (! is_dir($localDir)) {
            Log::error('Video directory not found', ['ulid' => $video->ulid, 'video' => $this->videoId]);
            throw new Exception("Video directory not found: {$video->ulid}");
        }

        $this->uploadDirectory($localDir, $video->ulid);

        $this->markStreamsCompleted($video);
    }

    private function uploadDirectory(string $localDir, string $prefix): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = $prefix.'/'.ltrim(
                str_replace($localDir, '', $file->getPathname()),
                DIRECTORY_SEPARATOR
            );

            $fileResource = fopen($file->getPathname(), 'r');

            if (! $fileResource) {
                Log::error('Could not open file for upload', ['path' => $file->getPathname()]);
                throw new Exception("Could not open file: {$file->getPathname()}");
            }

            try {
                $uploaded = Storage::put($relativePath, $fileResource);

                if (! $uploaded) {
                    Log::error('Failed to upload file to storage', ['path' => $relativePath]);
                    throw new Exception("Failed to upload: {$relativePath}");
                }
            } finally {
                if (is_resource($fileResource)) {
                    fclose($fileResource);
                }
            }
        }
    }

    private function markStreamsCompleted(Video $video): void
    {
        DB::transaction(function () use ($video) {
            $video->streams()
                ->where('status', VideoStatus::PENDING->value)
                ->each(function ($stream) {
                    $localPath = Storage::disk('tmp')->path($stream->path);

                    $stream->update([
                        'size' => file_exists($localPath) ? filesize($localPath) : 0,
                        'status' => VideoStatus::COMPLETED->value,
                        'progress' => 100,
                        'completed_at' => now(),
                    ]);
                });

            $video->update(['status' => VideoStatus::COMPLETED->value]);

            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->event('video_completed')
                ->log("Video processing completed: {$video->name}");
        });

        WebhookDispatcher::forVideo('video.completed', $video->fresh());
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with('user')->find($this->videoId);

        if ($video) {
            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->withProperties(['error' => $e->getMessage()])
                ->event('upload_failed')
                ->log("Stream upload failed: {$e->getMessage()}");
        }
    }
}
