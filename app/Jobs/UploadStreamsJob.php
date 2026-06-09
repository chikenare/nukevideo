<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
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

    public $backoff = [30, 120];

    private ?float $lastBeat = null;

    public function __construct(
        public int $videoId,
    ) {}

    public function handle(): void
    {
        Log::info('UploadStreams started', ['video' => $this->videoId]);

        $video = Video::findOrFail($this->videoId);
        $video->heartbeat();

        $video->update(['status' => VideoStatus::UPLOADING->value]);

        $localDir = Storage::disk('tmp')->path($video->ulid);

        if (! is_dir($localDir)) {
            Log::error('Video directory not found', ['ulid' => $video->ulid, 'video' => $this->videoId]);
            throw new Exception("Video directory not found: {$video->ulid}");
        }

        $this->uploadDirectory($localDir, $video->ulid, $video);

        $this->markStreamsCompleted($video);
    }

    private function uploadDirectory(string $localDir, string $prefix, Video $video): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $this->throttledHeartbeat($video);

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

    private function throttledHeartbeat(Video $video): void
    {
        $now = microtime(true);

        if ($this->lastBeat !== null && ($now - $this->lastBeat) < 15) {
            return;
        }

        $this->lastBeat = $now;
        $video->heartbeat();
    }

    private function markStreamsCompleted(Video $video): void
    {
        $pending = $video->streams()->where('status', VideoStatus::PENDING->value)->get();

        // Verify every expected output is really present BEFORE touching state,
        // and outside the transaction so the row lock isn't held during S3 HEADs.
        foreach ($pending as $stream) {
            $this->assertStreamUploaded($stream);
        }

        $completed = DB::transaction(function () use ($video, $pending) {
            // Lock the video row so completion is mutually exclusive with the
            // reaper/prune's guarded FAILED transition — exactly one terminal
            // state can win.
            $locked = Video::query()->whereKey($video->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, Video::ACTIVE_STATUSES, true)) {
                Log::warning('Video not active at completion; skipping', ['video' => $video->id, 'status' => $locked?->status]);

                return false;
            }

            foreach ($pending as $stream) {
                $stream->update([
                    'size' => filesize(Storage::disk('tmp')->path($stream->path)),
                    'status' => VideoStatus::COMPLETED->value,
                    'progress' => 100,
                    'completed_at' => now(),
                ]);
            }

            $locked->update(['status' => VideoStatus::COMPLETED->value]);

            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->event('video_completed')
                ->log("Video processing completed: {$video->name}");

            return true;
        });

        if ($completed) {
            WebhookDispatcher::forVideo('video.completed', $video->fresh());
        }
    }

    /**
     * Fail loudly if an expected output is missing locally or wasn't durably
     * persisted to storage. Marking a stream COMPLETED without this check ships
     * broken/0-byte renditions to viewers as a "successful" video.
     */
    private function assertStreamUploaded(Stream $stream): void
    {
        $localPath = Storage::disk('tmp')->path($stream->path);

        if (! file_exists($localPath) || filesize($localPath) === 0) {
            throw new Exception("Expected output missing or empty before completion: {$stream->path}");
        }

        if (! Storage::exists($stream->path)) {
            throw new Exception("Output not confirmed in storage after upload: {$stream->path}");
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
                ->event('upload_failed')
                ->log("Stream upload failed: {$e->getMessage()}");
        }
    }
}
