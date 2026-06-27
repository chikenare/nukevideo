<?php

namespace App\Jobs;

use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\Concerns\SyncsViaAwsCli;
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
    use CompletesVideo, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaAwsCli;

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

        // Idempotent: rendition already staged on the mirror. Still re-check the barrier in case
        // this was the last stream to land.
        if (Storage::disk('chunks')->exists($stream->stagingPath())) {
            $this->dispatchSyncIfReady($video);

            return;
        }

        $video->heartbeat();

        $this->concatVideo($stream, $video);

        $this->dispatchSyncIfReady($video);
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
                $uploaded = Storage::disk('chunks')->writeStream($stream->stagingPath(), $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if (! $uploaded) {
                throw new RuntimeException("Failed to stage final rendition on mirror: {$stream->stagingPath()}");
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
     * Pull every chunk for this stream off the mirror into local scratch with one parallel,
     * self-retrying `aws s3 sync` of the stream's chunk prefix; the concat demuxer reads local
     * files, not store URLs. Download order is irrelevant — the concat manifest is built from the
     * already-ordered `$chunkKeys`.
     *
     * @param  list<string>  $chunkKeys  store-relative keys, already ordered
     * @return list<string> absolute local paths, same order
     */
    private function stageChunks(array $chunkKeys, string $stageDir, Video $video): array
    {
        if (! is_dir($stageDir)) {
            mkdir($stageDir, 0755, true);
        }

        // All keys share the stream's chunk prefix (`{ulid}/chunks/{streamUlid}/`).
        $prefix = dirname($chunkKeys[0]).'/';

        $this->awsS3Sync(
            'chunks',
            $this->awsS3Uri('chunks', $prefix),
            $stageDir,
            $video,
            $this->timeout - 60,
            ['--exclude', '*', '--include', 'chunk_*.mp4'],
        );

        $localPaths = [];
        foreach ($chunkKeys as $key) {
            $local = $stageDir.'/'.basename($key);
            if (! is_file($local)) {
                throw new RuntimeException("Chunk missing after sync: {$key}");
            }
            $localPaths[] = $local;
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
