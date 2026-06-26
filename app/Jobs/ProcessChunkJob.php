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

class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [30];

    public function __construct(
        public int $videoId,
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
        return (int) env('VIDEO_WORKER_TIMEOUT', 600) - 120;
    }

    public function handle(): void
    {
        $video = Video::with(['streams' => fn ($q) => $q->where('type', 'video'), 'outputs'])
            ->find($this->videoId);

        if (! $video) {
            Log::info('ProcessChunk skipped: video gone', ['video' => $this->videoId]);

            return;
        }

        $streams = $video->streams;
        $outputs = $video->outputs;

        // Idempotent: every rendition chunk is already in the store. Re-settle progress and bail.
        if ($this->allChunksExist($video, $streams)) {
            foreach ($outputs as $output) {
                $output->reportChunkProgress($this->chunkIndex, 100);
            }

            return;
        }

        $video->heartbeat();

        $outputPaths = $this->buildOutputPaths($video, $streams);
        foreach ($outputPaths as $path) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $startedAt = microtime(true);
        $windowDuration = max(0.0, $this->end - $this->start);

        // Generated here (not when queued) so its TTL only spans this run's encodes.
        $sourceUrl = SegmentVideoJob::sourceUrl($this->mirrorPath);

        try {
            // One rendition at a time so a single encoder runs at the full thread budget.
            foreach ($this->pendingStreams($video, $streams) as $videoStream) {
                $this->encode(
                    new Collection([$videoStream]),
                    $sourceUrl,
                    $outputPaths,
                    $video,
                    $outputs,
                    $windowDuration,
                );
            }

            foreach ($outputs as $output) {
                $output->reportChunkProgress($this->chunkIndex, 100);
            }
        } finally {
            // A failed encode may leave a partial .part behind; harmless — the retry overwrites
            // it (`-y`) and only whole outputs get uploaded by UploadChunkJob.
            UsageService::record(
                $video->user_id,
                'encoding_cpu',
                round(microtime(true) - $startedAt, 2),
                $video->external_user_id ?? '',
            );
        }
    }

    /**
     * Encode the given streams' chunk outputs, beating the heartbeat and reporting per-chunk
     * progress off ffmpeg's `time=` output.
     *
     * @param  Collection<int,\App\Models\Stream>  $streams
     * @param  array<int,string>  $outputPaths
     * @param  \Illuminate\Support\Collection<int,\App\Models\Output>  $outputs
     */
    private function encode(Collection $streams, string $sourceUrl, array $outputPaths, Video $video, $outputs, float $windowDuration): void
    {
        $command = EncodeCommandBuilder::build($streams, $sourceUrl, $outputPaths, $this->start, $this->end);

        Log::debug($command);

        $progress = new ChunkProgressReporter($outputs, $this->chunkIndex, $windowDuration);

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
            Log::warning('Chunk ffmpeg killed by signal; job will be retried', ['video' => $this->videoId]);
            throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
        }

        Log::error('Chunk ffmpeg failed', [
            'video' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'error' => $process->errorOutput(),
        ]);
        throw new RuntimeException($process->errorOutput());
    }

    /**
     * The subset of $streams whose chunk is not yet in the store, so a retried job resumes
     * instead of repeating finished renditions.
     *
     * @param  Collection<int,\App\Models\Stream>  $streams
     * @return Collection<int,\App\Models\Stream>
     */
    private function pendingStreams(Video $video, Collection $streams): Collection
    {
        return $streams->reject(fn ($stream) => Storage::disk('chunks')->exists($this->chunkKey($video, $stream)))->values();
    }

    /**
     * @return array<int,string> stream id → local .part path
     */
    private function buildOutputPaths(Video $video, Collection $streams): array
    {
        $paths = [];

        foreach ($streams as $stream) {
            $paths[$stream->id] = Storage::disk('local')->path($this->chunkKey($video, $stream)).'.part';
        }

        return $paths;
    }

    private function allChunksExist(Video $video, Collection $streams): bool
    {
        foreach ($streams as $stream) {
            if (! Storage::disk('chunks')->exists($this->chunkKey($video, $stream))) {
                return false;
            }
        }

        return true;
    }

    private function chunkKey(Video $video, Stream $stream): string
    {
        return sprintf('%s/chunks/%s/chunk_%03d.mp4', $video->ulid, $stream->ulid, $this->chunkIndex);
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with('user')->find($this->videoId);

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->batch()?->cancel();
        $video->markAsFailed();
    }
}
