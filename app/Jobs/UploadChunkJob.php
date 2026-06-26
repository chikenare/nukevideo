<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Models\Video;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Streams a single encoded chunk from the worker's local scratch to the shared chunk store.
 * Split from {@see ProcessChunkJob} so a transient store error retries the upload without
 * repeating the expensive encode. The .part file lives on the encoding worker's local disk,
 * so multi-node clusters need a shared scratch volume.
 */
class UploadChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [10];

    public function __construct(
        public int $streamId,
        public int $chunkIndex,
        public string $chunkKey,
    ) {}

    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function handle(): void
    {
        $stream = Stream::with('video')->find($this->streamId);

        if (! $stream || ! $stream->video) {
            Log::info('UploadChunk skipped: stream or video gone', ['stream' => $this->streamId]);

            return;
        }

        $stream->video->heartbeat();

        // Idempotent: a prior complete run already uploaded this chunk.
        if (Storage::disk('chunks')->exists($this->chunkKey)) {
            @unlink($this->localPath());

            return;
        }

        $localPath = $this->localPath();

        if (! file_exists($localPath)) {
            throw new RuntimeException("Encoded chunk file missing on local disk: {$this->chunkKey}");
        }

        $handle = fopen($localPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open encoded chunk for upload: {$this->chunkKey}");
        }

        try {
            $uploaded = Storage::disk('chunks')->writeStream($this->chunkKey, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $uploaded) {
            throw new RuntimeException("Failed to upload chunk to store: {$this->chunkKey}");
        }

        // Delete only after a confirmed upload, so a failed upload's retry still has the file.
        @unlink($localPath);

        Log::debug('Chunk uploaded to store', ['stream' => $this->streamId, 'chunk' => $this->chunkIndex]);
    }

    public function failed(Throwable $e): void
    {
        // Clean up the scratch file on permanent failure so it doesn't leak.
        @unlink($this->localPath());

        $stream = Stream::with('video.user')->find($this->streamId);

        if (! $stream) {
            return;
        }

        $video = $stream->video;

        if (! in_array($video?->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->batch()?->cancel();

        $stream->update(['error_log' => $e->getMessage()]);
        $video->markAsFailed();
    }

    private function localPath(): string
    {
        return Storage::disk('local')->path($this->chunkKey).'.part';
    }
}
