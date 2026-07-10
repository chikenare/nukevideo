<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Video;
use App\Services\CreateVideoStreamsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Downloads the uploaded source once to local scratch, mirrors it to the internal store, creates
 * the video's rendition/audio/subtitle streams from a local probe, then plans keyframe-aligned
 * chunk windows and fans out one {@see ProcessChunkJob} per (window × rendition). Audio + subtitles
 * run separately via {@see EncodeSidecarTracksJob}.
 */
class PrepareVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaS5cmd;

    // tries absorbs redeliveries after a dead worker (OOM/restart/network); real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    // Must stay under the queue's retry_after (NodeService exports REDIS_QUEUE_RETRY_AFTER=1850
    // to workers) or the job is re-delivered mid-run and two attempts race on the local scratch.
    public $timeout = 1800;

    private const CHUNK_SECONDS = 300; // 5 minutes

    private const QUEUE = 'video-processing';

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    /**
     * Presigned URL of the mirrored source on the internal store (LAN, no egress). The downstream
     * thumbnail/storyboard/sidecar/chunk jobs read this instead of the costly main S3, which this
     * job reads exactly once. Generated fresh per job; TTL only outlasts a single pass.
     */
    public static function sourceUrl(string $mirrorPath): string
    {
        return Storage::disk('chunks')->temporaryUrl($mirrorPath, now()->addHours(6));
    }

    public function handle(CreateVideoStreamsService $streamsService): void
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

        // Guarded transition: never revive a video the reaper (or a failure path) already moved to
        // a terminal state — that would re-run work after `video.error` was emitted. affected-rows
        // can't be the guard here: MariaDB reports CHANGED rows and the dispatcher already set
        // RUNNING, so a same-second heartbeat refresh (last_heartbeat_at is second-precision) changes
        // nothing and returns 0 without the video being terminal. Disambiguate a 0 with a status read.
        $claimed = Video::whereKey($video->id)
            ->whereIn('status', Video::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::RUNNING->value, 'last_heartbeat_at' => now()]);

        if (! $claimed && ! Video::whereKey($video->id)->whereIn('status', Video::ACTIVE_STATUSES)->exists()) {
            Log::info('Preparation skipped: video no longer active', ['video' => $this->videoId]);

            return;
        }

        $ext = pathinfo($this->originalPath, PATHINFO_EXTENSION) ?: 'mp4';
        $mirrorPath = $video->sourceMirrorPath($ext);
        // Resolved before the try so the finally always cleans the scratch file, even if the
        // download itself throws partway through.
        $localPath = Storage::disk('local')->path($mirrorPath);

        try {
            $this->ensureLocalSource($video, $mirrorPath, $localPath);

            $sourceUrl = self::sourceUrl($mirrorPath);

            // Probe + rendition/audio/subtitle streams run on the LOCAL file: mkvmerge needs it for
            // BCP-47 language tags, and ffprobe is faster off local disk than the HTTP mirror.
            // Non-original streams present means a redelivery already ran it. Duration/aspect ratio
            // are empty until this fills them.
            if (! $video->streams()->where('type', '!=', 'original')->exists()) {
                $this->heartbeat($video);
                $streamsService->handle($video, $localPath);
                $video->refresh();
            }

            // Thumbnail + storyboard run in parallel with the encode; they swallow their own
            // errors so they never block or fail the video.
            ExtractThumbnailJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
            GenerateVideoStoryboard::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);

            // Audio + subtitles encode in one pass, not chunked: per-chunk audio concat corrupts
            // gapless codecs like Opus (per-segment pre-skip).
            if ($video->streams()->whereIn('type', ['audio', 'subtitle'])->exists()) {
                EncodeSidecarTracksJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
            }

            $windows = $this->planWindows($video, $localPath);

            if (empty($windows)) {
                throw new RuntimeException("Segment planner produced no windows for video {$this->videoId}");
            }

            $this->fanOut($video, $windows, $mirrorPath);

            Log::info('Preparation planned', ['video' => $this->videoId, 'chunks' => count($windows)]);
        } finally {
            // Downstream jobs read the mirror, not this scratch file; drop it either way.
            @unlink($localPath);
        }
    }

    /**
     * Download the source to the caller-resolved local scratch path (needed by mkvmerge/ffprobe) and
     * guarantee the internal mirror exists. First run: pull the original from main S3 with s5cmd,
     * then push it to the `chunks` mirror straight away so retries never hit main S3 again. On a
     * redelivery where the mirror already exists, pull from the LAN mirror instead. The caller
     * resolves `$localPath` and removes it in a finally.
     */
    private function ensureLocalSource(Video $video, string $mirrorPath, string $localPath): void
    {
        File::ensureDirectoryExists(dirname($localPath));

        $video->update(['status' => VideoStatus::DOWNLOADING->value]);

        if (Storage::disk('chunks')->exists($mirrorPath)) {
            $this->s5cmdCopy('chunks', $this->s3Uri('chunks', $mirrorPath), $localPath, $video, $this->timeout);
        } else {
            $this->s5cmdCopy('s3', $this->s3Uri('s3', $this->originalPath), $localPath, $video, $this->timeout);
            $this->s5cmdCopy('chunks', $localPath, $this->s3Uri('chunks', $mirrorPath), $video, $this->timeout);
        }

        $video->update(['status' => VideoStatus::RUNNING->value]);
    }

    /**
     * Read keyframe timestamps from the local source file and group them into keyframe-aligned
     * blocks of at least CHUNK_SECONDS.
     *
     * @return list<array{0:float,1:float}> ordered [start, end] windows in seconds
     */
    private function planWindows(Video $video, string $localPath): array
    {
        $command = ['ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'packet=pts_time,flags', '-of', 'csv=p=0', $localPath];

        $process = Process::timeout($this->timeout)->run($command, function () use ($video) {
            $this->heartbeat($video);
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

        // Authoritative window count: the packager asserts each rendition concatenated exactly this
        // many chunks, so a prematurely-completed batch can't publish a short rendition.
        $video->update(['chunk_count' => $chunkCount]);

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
