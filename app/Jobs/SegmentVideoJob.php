<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * Plans keyframe-aligned chunk windows over the mirrored source and fans out one
 * {@see ProcessChunkJob} per (window × rendition). Audio + subtitles run separately via
 * {@see EncodeSidecarTracksJob}.
 */
class SegmentVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    // Must stay under the queue's retry_after (NodeService exports REDIS_QUEUE_RETRY_AFTER=1850
    // to workers) or the job is re-delivered mid-run and two attempts race on the mirror staging.
    public $timeout = 1800;

    private const CHUNK_SECONDS = 300; // 5 minutes

    private const QUEUE = 'video-processing';

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    /**
     * Presigned URL of the mirrored source on the internal store (LAN, no egress). Every
     * ffmpeg/ffprobe pass reads this instead of the costly main S3, which is read once by
     * {@see ensureSourceMirrored}. Generated fresh per job; TTL only outlasts a single pass.
     */
    public static function sourceUrl(string $mirrorPath): string
    {
        return Storage::disk('chunks')->temporaryUrl($mirrorPath, now()->addHours(6));
    }

    public function handle(): void
    {
        $video = Video::find($this->videoId);

        if (! $video) {
            return;
        }

        // Redelivery guard: if the encode batch already exists, fan-out ran. Best-effort —
        // a double fan-out is idempotent (re-encode/uploads/concats all skip), just wasteful.
        if (DB::table('job_batches')->where('name', "encode video {$video->id}")->exists()) {
            Log::info('Segments already planned; skipping redelivery', ['video' => $this->videoId]);

            return;
        }

        if (! Storage::disk('s3')->exists($this->originalPath)) {
            throw new RuntimeException("Original {$this->originalPath} missing in S3");
        }

        // Guarded transition: never revive a video the reaper (or a failure path) already
        // moved to a terminal state — that would re-run work after `video.error` was emitted.
        $claimed = Video::whereKey($video->id)
            ->whereIn('status', Video::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::RUNNING->value, 'last_heartbeat_at' => now()]);

        if (! $claimed) {
            Log::info('Segmentation skipped: video no longer active', ['video' => $this->videoId]);

            return;
        }

        $mirrorPath = $this->ensureSourceMirrored($video);

        $sourceUrl = self::sourceUrl($mirrorPath);

        // Thumbnail + storyboard run in parallel with the encode; they swallow their own
        // errors so they never block or fail the video.
        ExtractThumbnailJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
        GenerateVideoStoryboard::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);

        // Audio + subtitles encode in one pass, not chunked: per-chunk audio concat corrupts
        // gapless codecs like Opus (per-segment pre-skip).
        if ($video->streams()->whereIn('type', ['audio', 'subtitle'])->exists()) {
            EncodeSidecarTracksJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
        }

        $windows = $this->planWindows($video, $sourceUrl);

        if (empty($windows)) {
            throw new RuntimeException("Segment planner produced no windows for video {$this->videoId}");
        }

        $this->fanOut($video, $windows, $mirrorPath);

        Log::info('Segmentation planned', ['video' => $this->videoId, 'chunks' => count($windows)]);
    }

    /**
     * Copy the original from main S3 into the internal store once, returning the mirror key.
     * Idempotent. Streamed through a local scratch file in bounded blocks so we can heartbeat
     * throughout — a multi-GB source can outlast the reaper and writeStream has no progress hook.
     */
    private function ensureSourceMirrored(Video $video): string
    {
        $ext = pathinfo($this->originalPath, PATHINFO_EXTENSION) ?: 'mp4';
        $mirrorPath = $video->sourceMirrorPath($ext);

        if (Storage::disk('chunks')->exists($mirrorPath)) {
            return $mirrorPath;
        }

        $in = Storage::disk('s3')->readStream($this->originalPath);
        if ($in === null) {
            throw new RuntimeException("Failed to read original from S3: {$this->originalPath}");
        }

        $localTmp = Storage::disk('local')->path($mirrorPath);
        $dir = dirname($localTmp);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $out = fopen($localTmp, 'w');
        try {
            if ($out === false) {
                throw new RuntimeException("Failed to open local mirror staging file: {$localTmp}");
            }

            $lastBeat = microtime(true);
            while (! feof($in)) {
                $buffer = fread($in, 8 * 1024 * 1024);
                if ($buffer === false || fwrite($out, $buffer) === false) {
                    throw new RuntimeException("Failed to stage original to local mirror: {$this->originalPath}");
                }
                if ((microtime(true) - $lastBeat) >= 15) {
                    $video->heartbeat();
                    $lastBeat = microtime(true);
                }
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }

        $handle = fopen($localTmp, 'r');
        try {
            if ($handle === false || ! Storage::disk('chunks')->writeStream($mirrorPath, $handle)) {
                throw new RuntimeException("Failed to mirror source to internal store: {$mirrorPath}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($localTmp);
        }

        $video->heartbeat();

        return $mirrorPath;
    }

    /**
     * Read keyframe timestamps straight from the presigned source URL and group them
     * into keyframe-aligned blocks of at least CHUNK_SECONDS.
     *
     * @return list<array{0:float,1:float}> ordered [start, end] windows in seconds
     */
    private function planWindows(Video $video, string $sourceUrl): array
    {
        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries packet=pts_time,flags -of csv=p=0 "%s"',
            $sourceUrl,
        );

        // Probing a large source over HTTP is a full read; heartbeat off its streaming
        // output so the reaper never mistakes it for a dead worker.
        $lastBeat = microtime(true);
        $process = Process::timeout($this->timeout)->run($command, function () use ($video, &$lastBeat) {
            if ((microtime(true) - $lastBeat) >= 15) {
                $video->heartbeat();
                $lastBeat = microtime(true);
            }
        });

        if (! $process->successful()) {
            Log::error('Keyframe probe failed', ['video' => $this->videoId, 'error' => $process->errorOutput()]);
            throw new RuntimeException($process->errorOutput());
        }

        $keyTimes = [];
        foreach (explode("\n", trim($process->output())) as $line) {
            // Each line is "<pts_time>,<flags>", e.g. "12.345000,K__".
            [$time, $flags] = array_pad(explode(',', $line), 2, '');

            if ($time === '' || $time === 'N/A' || ! str_contains($flags, 'K')) {
                continue;
            }

            $keyTimes[] = (float) $time;
        }

        sort($keyTimes);

        return $this->groupKeyframes($keyTimes, (float) $video->duration);
    }

    /**
     * Close each block at the first keyframe >= CHUNK_SECONDS past its start, so every
     * boundary lands on a source keyframe and the worker's `-ss` seek decodes no partial GOP.
     *
     * @param  list<float>  $keyTimes
     * @return list<array{0:float,1:float}>
     */
    private function groupKeyframes(array $keyTimes, float $duration): array
    {
        if ($duration <= 0) {
            return [];
        }

        $windows = [];
        $start = 0.0;

        foreach ($keyTimes as $t) {
            if ($t - $start >= self::CHUNK_SECONDS && $t < $duration) {
                $windows[] = [$start, $t];
                $start = $t;
            }
        }

        $windows[] = [$start, $duration];

        return $windows;
    }

    /**
     * One flat batch with one {@see ProcessChunkJob} per (window × rendition); each job encodes
     * AND uploads its own chunk, so no job depends on another job's local disk and any worker
     * node can pick up any job. The batch's then() fires packaging once every chunk is staged.
     *
     * @param  list<array{0:float,1:float}>  $windows
     */
    private function fanOut(Video $video, array $windows, string $mirrorPath): void
    {
        $streams = $video->streams()->where('type', 'video')->get();

        if ($streams->isEmpty()) {
            throw new RuntimeException("No video rendition streams found for video {$this->videoId}");
        }

        $queue = self::QUEUE;
        $chunkCount = count($windows);

        // Progress is tracked per Output as one field per (chunk × rendition it contains);
        // seed each output's Redis hash so its percent is meaningful from the first tick.
        foreach ($video->outputs as $output) {
            $output->update(['status' => VideoStatus::RUNNING->value]);

            $videoStreamIds = $output->streams()->where('type', 'video')->pluck('streams.id')->all();
            $output->seedChunkProgress($chunkCount, $videoStreamIds);
        }

        $jobs = [];

        foreach ($windows as $index => [$start, $end]) {
            foreach ($streams as $stream) {
                $jobs[] = new ProcessChunkJob($stream->id, $mirrorPath, $index, $start, $end);
            }
        }

        // Only primitives in the closure — Eloquent models are not serializable there.
        $videoId = $video->id;

        Bus::batch($jobs)
            ->onQueue($queue)
            ->name("encode video {$video->id}")
            ->then(function () use ($videoId) {
                // All chunk windows encoded: mark the encode done and let the readiness check fire
                // packaging once the sidecar tracks are staged too.
                $video = Video::find($videoId);

                if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
                    return;
                }

                $video->update(['status' => VideoStatus::UPLOADING->value]);
                PackageVideoJob::dispatchIfReady($video);
            })
            ->dispatch();
    }

    public function failed(Throwable $e): void
    {
        Video::find($this->videoId)?->markAsFailed();
    }
}
