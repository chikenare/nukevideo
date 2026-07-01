<?php

namespace App\Jobs;

use App\Exceptions\EncodeInterruptedException;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ChunkProgressReporter;
use App\Services\Concerns\EmitsHeartbeat;
use App\Services\EncodeCommandBuilder;
use App\Services\UsageService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encodes ONE rendition of one chunk window and uploads it straight to the shared chunk store.
 * One job per (window × rendition) keeps every job's runtime bounded by a single ffmpeg pass —
 * it can never outgrow the worker timeout as rendition counts climb — and leaves nothing on the
 * worker's local disk for a later job to depend on, so jobs are free to land on any worker node.
 */
class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [30];

    public function __construct(
        public int $streamId,
        public string $mirrorPath,
        public int $chunkIndex,
        public float $start,
        public float $end,
    ) {}

    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    private function ffmpegTimeout(): int
    {
        return (int) config('nuke.video.worker_timeout') - 120;
    }

    public function handle(): void
    {
        $stream = Stream::with(['video', 'outputs'])->find($this->streamId);

        if (! $stream || ! $stream->video) {
            Log::info('ProcessChunk skipped: stream or video gone', ['stream' => $this->streamId]);

            return;
        }

        $video = $stream->video;
        $outputs = $stream->outputs;
        $chunkKey = $video->chunkKey($stream, $this->chunkIndex);
        $localPath = $this->localPath($chunkKey);

        // Idempotent: a prior complete run already uploaded this chunk. Re-settle progress and bail.
        if (Storage::disk('chunks')->exists($chunkKey)) {
            $this->reportDone($outputs);
            @unlink($localPath);

            return;
        }

        $video->heartbeat();

        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $startedAt = microtime(true);
        $windowDuration = max(0.0, $this->end - $this->start);

        // Generated here (not when queued) so its TTL only spans this run's encode.
        $sourceUrl = SegmentVideoJob::sourceUrl($this->mirrorPath);

        try {
            // A failed encode may leave a partial .part behind; harmless — the retry overwrites
            // it (`-y`) and only a whole output is uploaded below.
            $this->encode($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration);
        } finally {
            UsageService::record(
                $video->user_id,
                'encoding_cpu',
                round(microtime(true) - $startedAt, 2),
                $video->external_user_id ?? '',
            );
        }

        $this->upload($chunkKey, $localPath);
        $this->reportDone($outputs);
    }

    /**
     * Encode this rendition's chunk, beating the heartbeat and reporting per-chunk progress off
     * ffmpeg's `time=` output to every output the stream belongs to.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Output>  $outputs
     */
    private function encode(Stream $stream, string $sourceUrl, string $localPath, Video $video, $outputs, float $windowDuration): void
    {
        $command = EncodeCommandBuilder::build(
            new Collection([$stream]),
            $sourceUrl,
            [$stream->id => $localPath],
            $this->start,
            $this->end,
        );

        Log::debug($command);

        $progress = new ChunkProgressReporter($outputs, $this->chunkIndex, $this->streamId, $windowDuration);

        $process = Process::timeout($this->ffmpegTimeout())->run(
            $command,
            function (string $_type, string $output) use ($video, $progress) {
                $this->heartbeat($video);
                $progress->handle($output);
            }
        );

        if ($process->successful()) {
            return;
        }

        if (EncodeInterruptedException::causedTermination($process->errorOutput())) {
            Log::warning('Chunk ffmpeg killed by signal; job will be retried', ['stream' => $this->streamId]);
            throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
        }

        Log::error('Chunk ffmpeg failed', [
            'stream' => $this->streamId,
            'chunk' => $this->chunkIndex,
            'error' => $process->errorOutput(),
        ]);
        throw new RuntimeException($process->errorOutput());
    }

    /**
     * Stream the encoded chunk to the shared store, retrying transient store errors in-process
     * so they never cost a re-encode. The local .part is deleted only after a confirmed upload.
     */
    private function upload(string $chunkKey, string $localPath): void
    {
        if (! file_exists($localPath)) {
            throw new RuntimeException("Encoded chunk file missing on local disk: {$chunkKey}");
        }

        retry([2000, 10000], function () use ($chunkKey, $localPath) {
            $handle = fopen($localPath, 'r');
            if ($handle === false) {
                throw new RuntimeException("Failed to open encoded chunk for upload: {$chunkKey}");
            }

            try {
                if (! Storage::disk('chunks')->writeStream($chunkKey, $handle)) {
                    throw new RuntimeException("Failed to upload chunk to store: {$chunkKey}");
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        });

        @unlink($localPath);
    }

    /** @param  \Illuminate\Support\Collection<int,\App\Models\Output>  $outputs */
    private function reportDone($outputs): void
    {
        foreach ($outputs as $output) {
            $output->reportChunkProgress($this->chunkIndex, $this->streamId, 100);
        }
    }

    private function localPath(string $chunkKey): string
    {
        return Storage::disk('local')->path($chunkKey).'.part';
    }

    public function failed(Throwable $e): void
    {
        $stream = Stream::with('video.user')->find($this->streamId);

        if (! $stream || ! $stream->video) {
            return;
        }

        $video = $stream->video;

        // Clean up the scratch file on permanent failure so it doesn't leak.
        @unlink($this->localPath($video->chunkKey($stream, $this->chunkIndex)));

        if (! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->batch()?->cancel();

        $stream->update(['error_log' => $e->getMessage()]);
        $video->markAsFailed();
    }
}
