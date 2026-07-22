<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Video;
use App\Services\CreateVideoStreamsService;
use App\Services\PerTitleCrfService;
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

    // Chunk-window sizing. A single ProcessChunkJob pass must finish well inside the per-chunk
    // timeout, and its wall-time scales ~linearly with pixels/frame × fps — for BOTH ends: it
    // decodes a window of the source and encodes one rendition out of it. So we hold that workload
    // near the reference (a 1080p source encoded to a 1080p rendition, 30fps, REF_WINDOW seconds)
    // and shrink the window as either end's resolution/fps climb — a 4K/8K master gets proportionally
    // shorter windows instead of blowing the timeout even when its top rendition is only 1080p.
    // Floored/capped so fan-out and parallelism stay sane.
    private const REF_PIXELS = 1920 * 1080;

    private const REF_FPS = 30.0;

    private const REF_WINDOW = 120.0;

    private const MIN_WINDOW = 8;

    private const MAX_WINDOW = 300;

    // Decoding a pixel is far cheaper than encoding one; weight the source side accordingly.
    private const DECODE_WEIGHT = 0.25;

    // Above this, `avg_frame_rate` is a VFR container lying (1000/1 and friends), not a real rate.
    private const MAX_FPS = 120.0;

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

        // Redelivery guard: if an encode batch already exists, fan-out ran. Best-effort —
        // a double fan-out is idempotent (re-encode/uploads/concats all skip), just wasteful.
        // Names are "encode video {id} {queue}"; the trailing space keeps id 12 from matching 123.
        if (DB::table('job_batches')->where('name', 'like', "encode video {$video->id} %")->exists()) {
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
            $this->assertAccelCapacity($video);

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

            // Per-title CRF: measure what each rendition actually needs from THIS source
            // before chunks fan out. No-op unless the template sets `target_vmaf`.
            $this->resolvePerTitleCrf($video, $localPath);

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

    /** Probe-and-set per-title CRF on each video rendition; a probe failure keeps the template CRF. */
    private function resolvePerTitleCrf(Video $video, string $localPath): void
    {
        foreach ($video->streams()->where('type', 'video')->get() as $stream) {
            (new PerTitleCrfService($stream))->apply(
                $localPath,
                (float) $video->duration,
                fn () => $this->heartbeat($video),
            );
        }
    }

    /**
     * Read keyframe timestamps from the local source file and group them into keyframe-aligned
     * blocks of at least the video's adaptive chunk-window length.
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

        return $this->groupKeyframes($keyTimes, (float) $video->duration, $this->chunkSeconds($video));
    }

    /**
     * Window length (seconds) for THIS video. Every rendition must share the chunk boundaries, so
     * size a window for each one — off its own pixels and the `source_*` probe meta its chunks
     * decode ({@see CreateVideoStreamsService}) — and keep the tightest: the heaviest rendition's
     * jobs are the ones that have to stay inside the per-chunk timeout.
     */
    private function chunkSeconds(Video $video): int
    {
        return $video->streams()->where('type', 'video')->get()
            ->map(fn ($stream) => self::chunkWindowSeconds(
                (int) $stream->width * (int) $stream->height,
                (int) ($stream->meta['source_width'] ?? 0) * (int) ($stream->meta['source_height'] ?? 0),
                (float) ($stream->meta['source_fps'] ?? 0.0),
            ))
            ->min() ?? (int) self::REF_WINDOW;
    }

    /**
     * Pure window-size calc (seconds of source per chunk) for ONE rendition: scale the reference
     * window inversely with the per-frame workload of its chunk jobs — its own pixels plus the
     * source pixels each of them decodes — times fps, then clamp. Static so it's covered without
     * a DB round-trip.
     *
     * A source probed before `source_fps` existed reports no rate; fall back to the reference one
     * rather than stretching the window on a video whose framerate we can't see.
     */
    public static function chunkWindowSeconds(int $renditionPixels, int $sourcePixels, float $fps): int
    {
        if ($renditionPixels <= 0 && $sourcePixels <= 0) {
            return (int) self::REF_WINDOW;
        }

        $sourcePixels = $sourcePixels > 0 ? $sourcePixels : $renditionPixels;
        $fps = $fps > 0 && $fps <= self::MAX_FPS ? $fps : self::REF_FPS;

        $pixels = $renditionPixels + $sourcePixels * self::DECODE_WEIGHT;
        $refPixels = self::REF_PIXELS * (1 + self::DECODE_WEIGHT);

        $window = self::REF_WINDOW
            * ($refPixels / $pixels)
            * (self::REF_FPS / $fps);

        return (int) max(self::MIN_WINDOW, min(self::MAX_WINDOW, round($window)));
    }

    /**
     * Close each block at the first keyframe >= $chunkSeconds past its start, so every
     * boundary lands on a source keyframe and the worker's `-ss` seek decodes no partial GOP.
     *
     * @param  list<float>  $keyTimes
     * @return list<array{0:float,1:float}>
     */
    private function groupKeyframes(array $keyTimes, float $duration, int $chunkSeconds): array
    {
        if ($duration <= 0) {
            return [];
        }

        $windows = [];
        $start = 0.0;

        foreach ($keyTimes as $t) {
            if ($t - $start >= $chunkSeconds && $t < $duration) {
                $windows[] = [$start, $t];
                $start = $t;
            }
        }

        $windows[] = [$start, $duration];

        return $windows;
    }

    /**
     * Second line of defense (ingest already refused templates without GPU capacity): a node
     * deactivated since then would leave the chunks on a queue nobody consumes and the video
     * hanging in RUNNING until the reaper — fail before fan-out with a message naming the gap.
     */
    private function assertAccelCapacity(Video $video): void
    {
        if ($accel = $video->template->missingAccel()) {
            throw new RuntimeException("Template needs a {$accel} GPU worker node, but none is active.");
        }
    }

    /**
     * One flat batch per hardware queue with one {@see ProcessChunkJob} per (window × rendition);
     * each job encodes AND uploads its own chunk, so no job depends on another job's local disk
     * and any node on that queue can pick up any of its jobs. The last batch to finish flips the
     * video to UPLOADING and fires packaging once every chunk is staged.
     *
     * @param  list<array{0:float,1:float}>  $windows
     */
    private function fanOut(Video $video, array $windows, string $mirrorPath): void
    {
        $streams = $video->streams()->where('type', 'video')->get();

        if ($streams->isEmpty()) {
            throw new RuntimeException("No video rendition streams found for video {$this->videoId}");
        }

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

        // One batch per hardware queue: the framework bulk-pushes batched jobs onto the BATCH's
        // queue (a job's own queue is ignored), so a mixed CPU+GPU template needs parallel batches.
        $jobsByQueue = [];

        foreach ($windows as $index => [$start, $end]) {
            foreach ($streams as $stream) {
                $jobsByQueue[$stream->encodeQueue()][] = new ProcessChunkJob($stream->id, $mirrorPath, $index, $start, $end);
            }
        }

        // Only primitives in the closure — Eloquent models are not serializable there.
        $videoId = $video->id;

        foreach ($jobsByQueue as $queue => $jobs) {
            Bus::batch($jobs)
                ->onQueue($queue)
                ->name("encode video {$video->id} {$queue}")
                ->then(function () use ($videoId) {
                    // Every batch fires this, but only the last finisher passes the gate: the
                    // framework marks a batch finished BEFORE running then(), so whoever sees
                    // no unfinished sibling knows every chunk window is encoded.
                    $stillEncoding = DB::table('job_batches')
                        ->where('name', 'like', "encode video {$videoId} %")
                        ->whereNull('finished_at')
                        ->exists();

                    if ($stillEncoding) {
                        return;
                    }

                    $video = Video::find($videoId);

                    if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
                        return;
                    }

                    $video->update(['status' => VideoStatus::UPLOADING->value]);
                    PackageVideoJob::dispatchIfReady($video);
                })
                ->dispatch();
        }
    }

    public function failed(Throwable $e): void
    {
        Video::find($this->videoId)?->markAsFailed();
    }
}
