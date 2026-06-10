<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\FencedByRun;
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
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class UploadStreamsJob implements ShouldQueue
{
    use Batchable, Dispatchable, FencedByRun, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [30, 120];

    private ?float $lastBeat = null;

    public function __construct(
        public int $videoId,
        ?int $runAttempt = null,
    ) {
        $this->runAttempt = $runAttempt;
    }

    public function handle(): void
    {
        Log::info('UploadStreams started', ['video' => $this->videoId]);

        $video = Video::find($this->videoId);

        if ($this->supersededRun($video)) {
            return;
        }

        $video->heartbeat();

        // A single failed rendition means the output is incomplete (e.g. a video
        // with a missing audio track). Bail before uploading anything so the
        // chain's catch handler fails the video and cleans up, instead of
        // silently shipping a broken result as "completed".
        $this->assertNoFailedStreams($video);

        $video->update(['status' => VideoStatus::UPLOADING->value]);

        $localDir = Storage::disk('tmp')->path($video->ulid);

        if (! is_dir($localDir)) {
            Log::error('Video directory not found', ['ulid' => $video->ulid, 'video' => $this->videoId]);
            throw new Exception("Video directory not found: {$video->ulid}");
        }

        // Captured up front (including UPLOADING, so a retried attempt re-adopts
        // streams whose status a prior attempt already advanced) and reused for
        // both the progress-tracked upload and the completion transaction.
        $pending = $video->streams()
            ->whereIn('status', [VideoStatus::PENDING->value, VideoStatus::UPLOADING->value])
            ->get();

        $this->uploadDirectory($localDir, $video->ulid, $video, $pending->keyBy('path'));

        $this->markStreamsCompleted($video, $pending);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Stream>  $streamsByPath
     */
    private function uploadDirectory(string $localDir, string $prefix, Video $video, $streamsByPath): void
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

            $stream = $streamsByPath->get($relativePath);

            if ($stream) {
                $this->uploadStreamFile($file->getPathname(), $relativePath, $stream, $video);

                continue;
            }

            $this->uploadPlainFile($file->getPathname(), $relativePath);
        }
    }

    private function uploadPlainFile(string $localPath, string $relativePath): void
    {
        $fileResource = fopen($localPath, 'r');

        if (! $fileResource) {
            Log::error('Could not open file for upload', ['path' => $localPath]);
            throw new Exception("Could not open file: {$localPath}");
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

    /**
     * Upload a single output stream, reusing its `progress` column to surface the
     * upload percentage (the encode already left it at 100). Progress is pushed
     * via the AWS SDK's transfer callback, throttled to roughly one write per
     * percent/second so a multi-hundred-MB rendition doesn't hammer the database.
     */
    private function uploadStreamFile(string $localPath, string $key, Stream $stream, Video $video): void
    {
        $stream->updateQuietly(['status' => VideoStatus::UPLOADING->value, 'progress' => 0]);

        $lastPercent = -1;
        $lastWrite = 0.0;

        // Flysystem's putObject auto-detects ContentType; the raw client call
        // doesn't, so set it ourselves or renditions serve as octet-stream and
        // break in-browser playback (.mp4) and subtitles (.vtt).
        $params = [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'SourceFile' => $localPath,
            '@http' => [
                'progress' => function ($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use ($stream, $video, &$lastPercent, &$lastWrite) {
                    if ($uploadTotal <= 0) {
                        return;
                    }

                    $percent = (int) min(100, floor($uploadedBytes / $uploadTotal * 100));
                    $now = microtime(true);

                    if ($percent !== $lastPercent && ($now - $lastWrite) >= 1) {
                        $lastPercent = $percent;
                        $lastWrite = $now;
                        $stream->updateQuietly(['progress' => $percent]);
                        $this->throttledHeartbeat($video);
                    }
                },
            ],
        ];

        if ($mime = (new FinfoMimeTypeDetector)->detectMimeTypeFromFile($localPath)) {
            $params['ContentType'] = $mime;
        }

        Storage::getClient()->putObject($params);

        $stream->updateQuietly(['progress' => 100]);
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

    private function markStreamsCompleted(Video $video, $pending): void
    {
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

            // The status check alone can't tell runs apart: after a reaper
            // requeue the video is ACTIVE *because the new run owns it*, which is
            // exactly when a zombie chain used to slip through here, mark the
            // video COMPLETED mid-rerun, and hand its cleanup a terminal status
            // that let it delete the original the new run still needed.
            if ($this->runAttempt !== null && $locked->dispatch_attempts !== $this->runAttempt) {
                Log::warning('Video owned by a newer run at completion; skipping', [
                    'video' => $video->id,
                    'job_attempt' => $this->runAttempt,
                    'current_attempt' => $locked->dispatch_attempts,
                ]);

                return false;
            }

            foreach ($pending as $stream) {
                $stream->update([
                    'size' => filesize(Storage::disk('tmp')->path($stream->path)),
                    'status' => VideoStatus::COMPLETED->value,
                    'progress' => 100,
                    // Preserve the real per-stream finish time recorded when the
                    // encode/extraction completed; only backfill if it was never set.
                    'completed_at' => $stream->completed_at ?? now(),
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
     * Refuse to complete a video that has any failed rendition. Completion only
     * ever inspected PENDING streams, so a FAILED audio/video/subtitle stream was
     * silently ignored and the video still went COMPLETED. Throwing here routes
     * the video through the chain's catch handler (markAsFailed + cleanup).
     */
    private function assertNoFailedStreams(Video $video): void
    {
        $failed = $video->streams()
            ->where('type', '!=', 'original')
            ->where('status', VideoStatus::FAILED->value)
            ->pluck('id');

        if ($failed->isNotEmpty()) {
            Log::error('Refusing to complete video with failed streams', [
                'video' => $video->id,
                'failed_streams' => $failed->all(),
            ]);

            throw new Exception(
                "Cannot complete video {$video->id}: {$failed->count()} stream(s) failed processing"
            );
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
