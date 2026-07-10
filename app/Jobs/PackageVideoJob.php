<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ManifestEditor;
use App\Services\PackagerCommandBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Final stage, all on one worker: concatenate each video stream's chunks, package every output into
 * static CMAF (shared segments + per-resolution HLS/DASH manifests), then push processed files and
 * packages to primary S3 via `s5cmd sync`. Folding concat + packaging here avoids staging
 * the renditions to the mirror and reading them back. Packaging precedes completion, so a COMPLETED
 * video is already synced and servable.
 */
class PackageVideoJob implements ShouldBeUnique, ShouldQueue
{
    use CompletesVideo, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaS5cmd;

    private const QUEUE = 'packaging';

    // A crash/OOM/deploy mid-package would otherwise fail a fully-encoded video permanently. The job
    // is idempotent (rebuilds the gather tree from the still-present chunks each run) and ShouldBeUnique
    // + retry_after(1850) > timeout(1800) guarantee no two attempts run at once, so a retry is safe.
    // tries absorbs redeliveries after a dead worker; real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    public $backoff = [60];

    public $timeout = 1800;

    public function __construct(
        public int $videoId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Dispatch once the video is ready to package: the encode batch has finished (status UPLOADING)
     * and every sidecar track (audio/subtitle) is staged on the mirror. Both the batch callback and
     * the sidecar jobs call this; ShouldBeUnique collapses the duplicates.
     */
    public static function dispatchIfReady(Video $video): void
    {
        if (Video::whereKey($video->id)->value('status') !== VideoStatus::UPLOADING->value) {
            return;
        }

        $sidecars = $video->streams()->whereIn('type', ['audio', 'subtitle'])->get();

        $allStaged = $sidecars->every(fn (Stream $s) => Storage::disk('chunks')->exists($s->stagingPath()));

        if ($allStaged) {
            self::dispatch($video->id)->onQueue(self::QUEUE);
        }
    }

    public function handle(ManifestEditor $manifests): void
    {
        $video = Video::with(['streams', 'outputs.streams', 'template'])->find($this->videoId);

        if (! $video) {
            return;
        }

        // Idempotent: a prior run already packaged and finalized the video.
        if (! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $video->heartbeat();

        $gatherDir = Storage::disk('local')->path($video->gatherDir());

        try {
            $this->gatherSidecars($video, $gatherDir);
            $this->concatVideoStreams($video, $gatherDir);

            foreach ($video->outputs as $output) {
                $output->setRelation('video', $video);
                $this->packageOutput($video, $output, $gatherDir);
            }

            $this->packageSubtitles($video, $gatherDir, $manifests);

            // After prune so each stream's size counts exactly what survives to S3 (CMAF always,
            // the raw rendition only when the template keeps processed files).
            $this->pruneProcessedRenditions($video);
            $this->recordStoredSizes($video, $gatherDir);
            $this->syncToPrimary($video, $gatherDir);

            // Conditional: never overwrite an output a concurrent failure path already FAILED.
            $video->outputs()
                ->whereNotIn('status', [VideoStatus::FAILED->value])
                ->update(['status' => VideoStatus::COMPLETED->value]);
            $this->finalizeVideoIfReady($video);
        } finally {
            Storage::disk('local')->deleteDirectory($video->gatherDir());
            Storage::disk('local')->deleteDirectory($video->chunkstageDir());
        }
    }

    /**
     * Pull the single-pass tracks staged on the mirror (`final/`: audio, subtitle) into the gather
     * tree. Sync drops the prefix, so they land beside the concatenated video under the destination
     * `{ulid}/` layout. Thumbnail/storyboard don't pass through here — they upload straight to
     * primary S3 from their own jobs, which run in parallel and must not race this sync.
     */
    private function gatherSidecars(Video $video, string $gatherDir): void
    {
        $this->s5cmdSync(
            'chunks',
            $this->s3Uri('chunks', "{$video->finalDir()}/*"),
            "{$gatherDir}/",
            $video,
            $this->timeout - 120,
        );
    }

    /** Sync every video stream's chunks once, then concat each into its gathered rendition. */
    private function concatVideoStreams(Video $video, string $gatherDir): void
    {
        $videoStreams = $video->streams->where('type', 'video')->values();

        if ($videoStreams->isEmpty()) {
            return;
        }

        $stageDir = Storage::disk('local')->path($video->chunkstageDir());

        $this->s5cmdSync(
            'chunks',
            $this->s3Uri('chunks', "{$video->chunksDir()}/*"),
            "{$stageDir}/",
            $video,
            $this->timeout - 120,
        );

        foreach ($videoStreams as $stream) {
            $this->concatStream($video, $stream, $stageDir, $gatherDir);
        }
    }

    private function concatStream(Video $video, Stream $stream, string $stageDir, string $gatherDir): void
    {
        $chunks = glob("{$stageDir}/{$stream->ulid}/".Video::CHUNK_FILENAME_GLOB) ?: [];
        sort($chunks); // zero-padded names sort into concat order

        if (empty($chunks)) {
            throw new RuntimeException("No chunk segments staged for stream {$stream->id}");
        }

        // Guard against publishing a short rendition: if the encode batch completed early (a
        // redelivered chunk can double-decrement Laravel's batch counter and fire then() before
        // every window is encoded), a chunk is missing here. Throwing fails this attempt; the
        // job's retry re-runs once the late chunk has landed. Null skips pre-migration videos.
        if ($video->chunk_count !== null && count($chunks) !== $video->chunk_count) {
            throw new RuntimeException(
                "Stream {$stream->id} staged ".count($chunks)." chunks, expected {$video->chunk_count}"
            );
        }

        $finalLocal = $this->localRenditionPath($stream, $gatherDir);
        $listLocal = "{$finalLocal}.concat.txt";

        if (! is_dir(dirname($finalLocal))) {
            mkdir(dirname($finalLocal), 0755, true);
        }

        $list = '';
        foreach ($chunks as $abs) {
            $list .= "file '".str_replace("'", "'\\''", $abs)."'\n";
        }

        if (file_put_contents($listLocal, $list) === false) {
            throw new RuntimeException("Failed to write concat manifest: {$listLocal}");
        }

        try {
            $this->runFfmpegConcat($video, $listLocal, $finalLocal);
        } finally {
            @unlink($listLocal);
        }

        Log::info('Rendition concatenated', ['stream' => $stream->id]);
    }

    private function runFfmpegConcat(Video $video, string $listLocal, string $finalLocal): void
    {
        $command = sprintf(
            'ffmpeg -hide_banner -y -f concat -safe 0 -i "%s" -c copy -movflags +faststart "%s"',
            $listLocal,
            $finalLocal,
        );

        $result = Process::timeout($this->timeout - 120)->run(
            $command,
            fn () => $this->heartbeat($video),
        );

        if (! $result->successful()) {
            throw new RuntimeException("concat failed for {$finalLocal}: {$result->errorOutput()}");
        }
    }

    private function packageOutput(Video $video, Output $output, string $gatherDir): void
    {
        $formats = $output->computedFormats();

        if (empty($formats)) {
            $output->recordFormats([]);

            return;
        }

        $inputs = $this->resolveInputs($output, $gatherDir);

        if (empty($inputs)) {
            $output->recordFormats([]);

            return;
        }

        if (! is_dir($gatherDir)) {
            mkdir($gatherDir, 0755, true);
        }

        $this->packageManifests($video, $output, $inputs, $gatherDir, $formats);
        $output->recordFormats($formats);

        Log::info('Output packaged', ['video' => $video->id, 'output' => $output->id, 'formats' => $formats]);
    }

    /**
     * One packager run per supported resolution (the distinct video heights), all sharing the same
     * segment tree. The tallest is the full master; each lower height yields a capped manifest
     * listing only the renditions at or below it, plus every audio track. Filenames are keyed by
     * `$output`'s own ulid ({@see \App\Models\Output::manifestFile}), so a second output of the same
     * video landing on the same height never overwrites this one.
     *
     * @param  list<array{path:string,type:string,ulid:string,height:?int}>  $inputs
     * @param  list<string>  $formats
     */
    private function packageManifests(Video $video, Output $output, array $inputs, string $outputDir, array $formats): void
    {
        $heights = collect($inputs)
            ->where('type', 'video')
            ->pluck('height')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $max = $heights->last();

        foreach ($heights as $height) {
            $isMax = $height === $max;

            $subset = $isMax ? $inputs : array_values(array_filter(
                $inputs,
                fn (array $input) => $input['type'] !== 'video' || $input['height'] <= $height,
            ));

            $this->runPackager($video, $subset, $outputDir, $formats, $output, $isMax ? null : $height);
        }
    }

    /**
     * Map the output's video/audio streams to their gathered local files. Subtitles aren't attached
     * to outputs; they're added per video by {@see subtitleInputs}.
     *
     * @return list<array{path:string,type:string,ulid:string,height:?int}>
     */
    private function resolveInputs(Output $output, string $gatherDir): array
    {
        $inputs = [];

        foreach ($output->streams as $stream) {
            if (! in_array($stream->type, ['video', 'audio'], true)) {
                continue;
            }

            $local = $this->localRenditionPath($stream, $gatherDir);

            if (! is_file($local)) {
                throw new RuntimeException("Missing rendition input for packaging: {$local}");
            }

            $inputs[] = [
                'path' => $local,
                'type' => $stream->type,
                'ulid' => $stream->ulid,
                'language' => $stream->language,
                'name' => $stream->name,
                'height' => $stream->type === 'video' ? (int) $stream->height : null,
                'hearing_impaired' => (bool) data_get($stream->meta, 'hearing_impaired', false),
                'visual_impaired' => (bool) data_get($stream->meta, 'visual_impaired', false),
            ];
        }

        return $inputs;
    }

    /**
     * Subtitle packager inputs for the video (shared across outputs). Invalid tracks (bitmap, empty
     * source) are already dropped at stream creation ({@see \App\Services\CreateVideoStreamsService}); here we
     * additionally skip any VTT that didn't reach the gather tree or carries no cue (`-->`), since a
     * cue-less VTT makes shaka fail the whole run with PARSER_FAILURE.
     *
     * @return list<array{path:string,type:string,ulid:string,height:?int,language:?string,forced:bool,hearing_impaired:bool,name:?string}>
     */
    private function subtitleInputs(Video $video, string $gatherDir): array
    {
        $inputs = [];

        foreach ($video->streams->where('type', 'subtitle') as $sub) {
            $local = $this->localRenditionPath($sub, $gatherDir);

            if (! is_file($local) || ! str_contains((string) file_get_contents($local), '-->')) {
                Log::warning('Skipping subtitle with missing/cue-less VTT', ['video' => $video->id, 'stream' => $sub->id]);

                continue;
            }

            $inputs[] = [
                'path' => $local,
                'type' => 'subtitle',
                'ulid' => $sub->ulid,
                'height' => null,
                'language' => $sub->language,
                'forced' => (bool) data_get($sub->meta, 'forced', false),
                'hearing_impaired' => (bool) data_get($sub->meta, 'hearing_impaired', false),
                'name' => $sub->name,
            ];
        }

        return $inputs;
    }

    /** Local path of a rendition in the gather tree, mirroring its `{ulid}/{type}/x.ext` S3 key. */
    private function localRenditionPath(Stream $stream, string $gatherDir): string
    {
        return $gatherDir.'/'.$stream->relativePath();
    }

    /**
     * @param  list<array{path:string,type:string,ulid:string,height:?int}>  $inputs
     * @param  list<string>  $formats
     */
    private function makeBuilder(): PackagerCommandBuilder
    {
        return new PackagerCommandBuilder(
            (string) config('packager.bin'),
            (int) config('packager.segment_duration'),
        );
    }

    private function runPackager(Video $video, array $inputs, string $outputDir, array $formats, Output $output, ?int $cap): void
    {
        $builder = $this->makeBuilder();

        $result = Process::timeout($this->timeout - 120)->run(
            $builder->build($inputs, $outputDir, $formats, $output, $cap),
            fn () => $this->heartbeat($video),
        );

        if (! $result->successful()) {
            throw new RuntimeException("packager failed: {$result->errorOutput()}");
        }
    }

    /**
     * Package subtitles in SEPARATE single-segment runs (a few KB each — no point segmenting them like
     * video), then graft their text entries into the already-written DASH/HLS manifests in the gather
     * tree before the sync. One run PER format: DASH needs fMP4 `wvtt` (with an init segment, or dashjs
     * 404s fetching the BaseURL dir) while HLS needs raw `text/vtt` (hls.js can't parse fMP4) — see
     * {@see PackagerCommandBuilder::buildText}. Separate from the main run because `--segment_duration`
     * is global. Subtitles are non-critical, so a failed run leaves the video servable without them.
     */
    private function packageSubtitles(Video $video, string $gatherDir, ManifestEditor $manifests): void
    {
        $subs = $this->subtitleInputs($video, $gatherDir);

        if (empty($subs)) {
            return;
        }

        // A segment longer than the video spans it in one piece.
        $segmentDuration = (int) ceil((float) $video->duration) + 2;
        $builder = $this->makeBuilder();

        // Manifests are named after each output's own ulid now ({@see \App\Models\Output::manifestFile}),
        // so they're matched by extension and the `_subs.*` throwaway is excluded by its leading
        // underscore — a real output ulid never starts with one.
        if (glob("{$gatherDir}/[!_]*.mpd") && $this->runTextPackager($video, $builder, $subs, $gatherDir, $segmentDuration, 'dash')) {
            $subsXml = file_get_contents("{$gatherDir}/".PackagerCommandBuilder::SUBS_DASH_MANIFEST);

            foreach (glob("{$gatherDir}/[!_]*.mpd") ?: [] as $mpd) {
                $original = file_get_contents($mpd);
                $edited = $manifests->importDashSubtitles($original, $subsXml);

                if ($edited !== null && $edited !== $original) {
                    file_put_contents($mpd, $edited);
                }
            }

            @unlink("{$gatherDir}/".PackagerCommandBuilder::SUBS_DASH_MANIFEST); // throwaway master; the grafted text set references the kept segments
        }

        if (glob("{$gatherDir}/[!_]*.m3u8") && $this->runTextPackager($video, $builder, $subs, $gatherDir, $segmentDuration, 'hls')) {
            $subsContent = file_get_contents("{$gatherDir}/".PackagerCommandBuilder::SUBS_HLS_MANIFEST);

            foreach (glob("{$gatherDir}/[!_]*.m3u8") ?: [] as $m3u8) {
                $original = file_get_contents($m3u8);
                $edited = $manifests->hlsAddSubtitles($original, $subsContent);

                if ($edited !== null && $edited !== $original) {
                    file_put_contents($m3u8, $edited);
                }
            }

            @unlink("{$gatherDir}/".PackagerCommandBuilder::SUBS_HLS_MANIFEST);
        }
    }

    /**
     * Run the subtitle packager for one format ('dash'|'hls'); returns false (logged, non-fatal) when
     * the run fails or wrote no throwaway manifest, so the caller skips grafting that format.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     */
    private function runTextPackager(Video $video, PackagerCommandBuilder $builder, array $subs, string $gatherDir, int $segmentDuration, string $format): bool
    {
        $result = Process::timeout($this->timeout - 120)->run(
            $builder->buildText($subs, $gatherDir, $segmentDuration, $format),
            fn () => $this->heartbeat($video),
        );

        if (! $result->successful()) {
            Log::warning('Subtitle packaging failed; leaving the video without subtitles for this format', [
                'video' => $video->id, 'format' => $format, 'error' => $result->errorOutput(),
            ]);

            return false;
        }

        return is_file("{$gatherDir}/".($format === 'dash' ? PackagerCommandBuilder::SUBS_DASH_MANIFEST : PackagerCommandBuilder::SUBS_HLS_MANIFEST));
    }

    /**
     * Record each stream's stored footprint from disk after {@see pruneProcessedRenditions}, so what's
     * measured equals what reaches S3 — no template lookup needed. `package_size` = the CMAF package
     * (its own `{ulid}/` dir, always synced); `file_size` = the raw processed rendition
     * ({@see localRenditionPath}), present only when the template keeps processed files (pruning removed
     * it otherwise, so it stays null). The two are never summed here; the video total lives in
     * {@see \App\Data\VideoData}. The `original` isn't packaged and keeps its uploaded `file_size`.
     */
    private function recordStoredSizes(Video $video, string $gatherDir): void
    {
        foreach ($video->streams as $stream) {
            if ($stream->type === 'original') {
                continue;
            }

            $package = 0;

            foreach (glob("{$gatherDir}/{$stream->ulid}/*") ?: [] as $file) {
                $package += is_file($file) ? (int) filesize($file) : 0;
            }

            $raw = "{$gatherDir}/{$stream->relativePath()}";

            $stream->update([
                'package_size' => $package,
                'file_size' => is_file($raw) ? (int) filesize($raw) : null,
            ]);
        }
    }

    /**
     * The raw video/audio renditions are only packager inputs — the CMAF segments serve playback.
     * Drop them from the gather tree before the sync (so they never reach primary S3) unless the
     * template opts to keep them. Subtitles, thumbnail and storyboard live elsewhere and are kept.
     */
    private function pruneProcessedRenditions(Video $video): void
    {
        if ($video->template?->keep_processed_files ?? true) {
            return;
        }

        foreach (['video', 'audio'] as $type) {
            Storage::disk('local')->deleteDirectory($video->gatherDir()."/{$type}");
        }

        Log::info('Pruned processed renditions before sync', ['video' => $video->id]);
    }

    /** Forced on upload: s5cmd sniffs content instead of trusting the extension, and types `.mpd` as
     *  `text/xml` and `.m3u8` as `audio/x-mpegurl`. Neither is in the VOD edge's `secure_token_types`,
     *  so it never signs the segment URLs a manifest lists and players 403. Manifest values match
     *  {@see \App\Services\ManifestEditor}'s.
     *
     *  Order is load-bearing: {@see syncToPrimary} runs one pass per entry and each pass lists the
     *  destination, so the bulk extension goes last and the others list a near-empty prefix. */
    public const SYNC_CONTENT_TYPES = [
        'mpd' => 'application/dash+xml',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'vtt' => 'text/vtt',
        'mp4' => 'video/mp4',
        'm4s' => 'video/iso.segment',
    ];

    /**
     * `s5cmd sync` of the gathered tree (processed files + packages) to primary `{ulid}/`: one pass per
     * known extension with its Content-Type forced, then a catch-all for the rest. A pass whose filter
     * matches nothing (an HLS-less output has no `.m3u8`) is a no-op, not an error.
     */
    private function syncToPrimary(Video $video, string $gatherDir): void
    {
        $dest = $this->s3Uri('s3', "{$video->ulid}/");
        $timeout = $this->timeout - 120;

        foreach (self::SYNC_CONTENT_TYPES as $ext => $contentType) {
            $this->s5cmdSync('s3', "{$gatherDir}/", $dest, $video, $timeout, [
                '--include', "*.{$ext}", '--content-type', $contentType,
            ]);
        }

        $excludes = collect(self::SYNC_CONTENT_TYPES)->keys()
            ->flatMap(fn (string $ext) => ['--exclude', "*.{$ext}"])->all();

        $this->s5cmdSync('s3', "{$gatherDir}/", $dest, $video, $timeout, $excludes);

        Log::info('Processed files and packages synced to primary S3', ['video' => $video->id]);
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with('outputs')->find($this->videoId);

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $video->outputs()
            ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
            ->update(['status' => VideoStatus::FAILED->value]);

        $this->finalizeVideoIfReady($video);
    }
}
