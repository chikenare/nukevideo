<?php

namespace App\Jobs;

use App\Jobs\Concerns\CompletesVideo;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ConcatenateVideoChunksJob implements ShouldQueue
{
    use CompletesVideo, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 200;

    public function __construct(
        public int $streamId,
    ) {}

    public function handle(): void
    {
        $stream = Stream::with('video')->find($this->streamId);

        if (! $stream || ! $stream->video) {
            return;
        }

        $video = $stream->video;

        // Idempotent: final file already in S3. Still settle in case this was the last to land.
        if (Storage::disk('s3')->exists($stream->path)) {
            $this->markOutputCompletedIfReady($stream);
            $this->finalizeVideoIfReady($video);

            return;
        }

        $video->heartbeat();

        $this->concatVideo($stream, $video);

        $this->markOutputCompletedIfReady($stream);
        $this->finalizeVideoIfReady($video);
    }

    private function concatVideo(Stream $stream, Video $video): void
    {
        $chunkKeys = $this->collectChunkKeys($stream, 'mp4');

        $scratch = Storage::disk('local');
        $finalLocal = $scratch->path("{$video->ulid}/final/{$stream->ulid}.mp4");
        $listLocal = $scratch->path("{$video->ulid}/final/{$stream->ulid}.concat.txt");
        $stageDir = $scratch->path("{$video->ulid}/chunks/{$stream->ulid}");

        $dir = dirname($finalLocal);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $localPaths = $this->stageChunks($chunkKeys, $stageDir, $video);

            $list = '';
            foreach ($localPaths as $abs) {
                $list .= "file '".str_replace("'", "'\\''", $abs)."'\n";
            }
            if (file_put_contents($listLocal, $list) === false) {
                throw new RuntimeException("Failed to write concat manifest: {$listLocal}");
            }

            $this->runFfmpegConcat($listLocal, $finalLocal, $video);

            $size = filesize($finalLocal);
            if ($size === false) {
                throw new RuntimeException("Failed to stat final rendition: {$finalLocal}");
            }

            $handle = fopen($finalLocal, 'r');
            if ($handle === false) {
                throw new RuntimeException("Failed to open final rendition for upload: {$finalLocal}");
            }
            try {
                $uploaded = Storage::disk('s3')->writeStream($stream->path, $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if (! $uploaded) {
                throw new RuntimeException("Failed to upload final rendition to S3: {$stream->path}");
            }

            $stream->update(['size' => $size]);

            Log::info('Rendition concatenated', ['stream' => $this->streamId, 'size' => $size]);

            Storage::disk('chunks')->deleteDirectory("{$video->ulid}/chunks/{$stream->ulid}");
        } finally {
            @unlink($finalLocal);
            @unlink($listLocal);
            $scratch->deleteDirectory("{$video->ulid}/chunks/{$stream->ulid}");
        }
    }

    /**
     * Download each chunk to local scratch in order; the concat demuxer reads local files,
     * not store URLs.
     *
     * @param  list<string>  $chunkKeys  store-relative keys, already ordered
     * @return list<string> absolute local paths, same order
     */
    private function stageChunks(array $chunkKeys, string $stageDir, Video $video): array
    {
        if (! is_dir($stageDir)) {
            mkdir($stageDir, 0755, true);
        }

        $store = Storage::disk('chunks');
        $localPaths = [];
        $lastBeat = microtime(true);

        foreach ($chunkKeys as $key) {
            $local = $stageDir.'/'.basename($key);

            $in = $store->readStream($key);
            if ($in === null) {
                throw new RuntimeException("Failed to read chunk from store: {$key}");
            }
            $out = fopen($local, 'w');
            try {
                if ($out === false || stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException("Failed to stage chunk locally: {$key}");
                }
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
            }

            $localPaths[] = $local;

            if ((microtime(true) - $lastBeat) >= 15) {
                $video->heartbeat();
                $lastBeat = microtime(true);
            }
        }

        return $localPaths;
    }

    private function runFfmpegConcat(string $listLocal, string $finalLocal, Video $video): void
    {
        $command = sprintf(
            'ffmpeg -hide_banner -y -f concat -safe 0 -i "%s" -c copy -movflags +faststart "%s"',
            $listLocal,
            $finalLocal,
        );

        $lastBeat = microtime(true);
        $process = Process::timeout($this->timeout - 60)->run($command, function () use ($video, &$lastBeat) {
            if ((microtime(true) - $lastBeat) >= 15) {
                $video->heartbeat();
                $lastBeat = microtime(true);
            }
        });

        if (! $process->successful()) {
            Log::error('Rendition concat failed', ['stream' => $this->streamId, 'error' => $process->errorOutput()]);
            throw new RuntimeException($process->errorOutput());
        }
    }

    /**
     * Chunk keys for the given extension, ordered: the zero-padded `chunk_NNN` names sort
     * lexicographically into concat order.
     *
     * @return list<string>
     */
    private function collectChunkKeys(Stream $stream, string $ext): array
    {
        $dir = "{$stream->video->ulid}/chunks/{$stream->ulid}";

        $keys = collect(Storage::disk('chunks')->files($dir))
            ->filter(fn ($path) => preg_match('#/chunk_\d+\.'.$ext.'$#', $path))
            ->sort()
            ->values()
            ->all();

        if (empty($keys)) {
            throw new RuntimeException("No chunk segments in store for stream {$stream->id}");
        }

        return $keys;
    }

    public function failed(Throwable $e): void
    {
        $stream = Stream::with(['video', 'outputs'])->find($this->streamId);
        $video = $stream?->video;

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->markOutputsFailedForStream($stream);
        $this->finalizeVideoIfReady($video);
    }
}
