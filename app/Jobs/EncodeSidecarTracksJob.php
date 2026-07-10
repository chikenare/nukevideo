<?php

namespace App\Jobs;

use App\Exceptions\EncodeInterruptedException;
use App\Jobs\Concerns\CompletesVideo;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Concerns\EmitsHeartbeat;
use App\Services\EncodeCommandBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encodes every audio and subtitle track in one pass over the mirrored source and uploads
 * each straight to S3 — no chunking, no concat. Chunking exists only to bound the heavy video
 * encode; per-segment concat would corrupt gapless codecs like Opus (each segment keeps its
 * own pre-skip priming, so a `-c copy` merge drifts shorter than the manifest).
 *
 * Runs in parallel with the video batch and settles its own outputs via {@see CompletesVideo};
 * the lock there resolves the race over which job finalizes the video.
 */
class EncodeSidecarTracksJob implements ShouldQueue
{
    use CompletesVideo, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

    // tries absorbs redeliveries after a dead worker (OOM/restart/network); real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    public $backoff = [30, 120];

    public $timeout = 1800;

    public function __construct(
        public int $videoId,
        public string $mirrorPath,
    ) {}

    public function handle(): void
    {
        $video = Video::with(['streams' => fn ($q) => $q->whereIn('type', ['audio', 'subtitle']), 'outputs'])
            ->find($this->videoId);

        if (! $video) {
            Log::info('EncodeSidecarTracks skipped: video gone', ['video' => $this->videoId]);

            return;
        }

        if (! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $streams = $video->streams;

        if ($streams->isEmpty()) {
            return;
        }

        // Idempotent: a prior run already staged some/all tracks on the mirror.
        $pending = $streams->reject(fn ($stream) => Storage::disk('chunks')->exists($stream->stagingPath()))->values();

        if ($pending->isNotEmpty()) {
            $video->heartbeat();
            $this->encodeAndUpload($video, $pending);
        }

        PackageVideoJob::dispatchIfReady($video);
    }

    /**
     * @param  Collection<int,\App\Models\Stream>  $streams
     */
    private function encodeAndUpload(Video $video, Collection $streams): void
    {
        $outputPaths = [];
        foreach ($streams as $stream) {
            $ext = $stream->type === 'subtitle' ? 'vtt' : 'mp4';
            $local = Storage::disk('local')->path($video->sidecarPath($stream, $ext));
            $dir = dirname($local);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $outputPaths[$stream->id] = $local;
        }

        try {
            $sourceUrl = PrepareVideoJob::sourceUrl($this->mirrorPath);
            $command = EncodeCommandBuilder::build($streams, $sourceUrl, $outputPaths);

            Log::debug($command);

            $process = Process::timeout($this->timeout - 120)->run($command, function () use ($video) {
                $this->heartbeat($video);
            });

            if (! $process->successful()) {
                if (EncodeInterruptedException::causedTermination($process->errorOutput())) {
                    Log::warning('Sidecar ffmpeg killed by signal; job will be retried', ['video' => $this->videoId]);
                    throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
                }

                Log::error('Sidecar ffmpeg failed', ['video' => $this->videoId, 'error' => $process->errorOutput()]);
                throw new RuntimeException($process->errorOutput());
            }

            foreach ($streams as $stream) {
                $this->uploadTrack($stream, $outputPaths[$stream->id]);
            }
        } finally {
            foreach ($outputPaths as $path) {
                @unlink($path);
            }
        }
    }

    private function uploadTrack(Stream $stream, string $localPath): void
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException("Sidecar track output missing after encode: {$localPath}");
        }

        $handle = fopen($localPath, 'r');
        try {
            if ($handle === false || ! Storage::disk('chunks')->writeStream($stream->stagingPath(), $handle)) {
                throw new RuntimeException("Failed to stage sidecar track on mirror: {$stream->stagingPath()}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        // Size is recorded authoritatively from the packaged CMAF later ({@see \App\Jobs\PackageVideoJob}).
        Log::info('Sidecar track encoded', ['stream' => $stream->id, 'type' => $stream->type, 'size' => $size]);
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with(['streams' => fn ($q) => $q->whereIn('type', ['audio', 'subtitle']), 'outputs'])
            ->find($this->videoId);

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        // Stop the parallel video batch — this video can no longer complete.
        $batchId = DB::table('job_batches')->where('name', "encode video {$video->id}")->value('id');
        if ($batchId) {
            Bus::findBatch($batchId)?->cancel();
        }

        foreach ($video->streams as $stream) {
            $this->markOutputsFailedForStream($stream);
        }

        $this->finalizeVideoIfReady($video);
    }
}
