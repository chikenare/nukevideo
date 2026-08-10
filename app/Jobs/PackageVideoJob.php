<?php

namespace App\Jobs;

use App\Data\VideoData;
use App\Enums\VideoStatus;
use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Services\CreateVideoStreamsService;
use App\Services\ManifestEditor;
use App\Services\PackagerCommandBuilder;
use App\Services\SubtitlePackager;
use App\Support\Scratch;
use App\Support\WebVtt;
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

    public function handle(SubtitlePackager $subtitles): void
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

            $this->packageSubtitles($video, $gatherDir, $subtitles);

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
        $chunks = $this->orderedChunks($video, $stream, $stageDir);

        if (empty($chunks)) {
            throw new RuntimeException("No chunk segments staged for stream {$stream->id}");
        }

        $finalLocal = $this->localRenditionPath($stream, $gatherDir);
        $listLocal = "{$finalLocal}.concat.txt";

        Scratch::ensureDirectory(dirname($finalLocal));

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

    /**
     * The staged chunks in encode order, resolved index by index rather than by sorting the glob:
     * chunk names only sort into numeric order while the index fits the zero-padding, and a
     * count-only check cannot tell a scrambled timeline from a correct one — the rendition would
     * publish with its windows spliced in the wrong order and nothing would flag it.
     *
     * A missing index throws, which fails this attempt: if the encode batch completed early (a
     * redelivered chunk can double-decrement Laravel's batch counter and fire then() before every
     * window is encoded), the retry re-runs once the late chunk has landed.
     *
     * @return array<int, string>
     */
    private function orderedChunks(Video $video, Stream $stream, string $stageDir): array
    {
        $dir = "{$stageDir}/{$stream->ulid}";

        // Pre-migration videos never recorded a count. They predate the wider padding too, so
        // every index they hold is 3 digits and sorts correctly.
        if ($video->chunk_count === null) {
            $chunks = glob("{$dir}/".Video::CHUNK_FILENAME_GLOB) ?: [];
            sort($chunks);

            return $chunks;
        }

        $chunks = [];

        for ($index = 0; $index < $video->chunk_count; $index++) {
            $path = null;

            foreach (Video::chunkFilenameCandidates($index) as $filename) {
                if (is_file("{$dir}/{$filename}")) {
                    $path = "{$dir}/{$filename}";
                    break;
                }
            }

            if ($path === null) {
                throw new RuntimeException(
                    "Stream {$stream->id} is missing chunk {$index} of {$video->chunk_count}"
                );
            }

            $chunks[] = $path;
        }

        return $chunks;
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

        Scratch::ensureDirectory($gatherDir);

        $this->packageManifests($video, $output, $inputs, $gatherDir, $formats);
        $output->recordFormats($formats);

        Log::info('Output packaged', ['video' => $video->id, 'output' => $output->id, 'formats' => $formats]);
    }

    /**
     * One packager run per supported resolution (the distinct video heights), all sharing the same
     * segment tree. The tallest is the full master; each lower height yields a capped manifest
     * listing only the renditions at or below it, plus every audio track. Filenames are keyed by
     * `$output`'s own ulid ({@see Output::manifestFile}), so a second output of the same
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
     * source) are already dropped at stream creation ({@see CreateVideoStreamsService}); here we
     * additionally skip any VTT that didn't reach the gather tree or carries no cue (`-->`), since a
     * cue-less VTT makes shaka fail the whole run with PARSER_FAILURE.
     *
     * Tracks staged by an older build (or by a retry that predates the fix) can still carry the
     * malformed cues {@see WebVtt} repairs, so they are patched here too rather than only at encode
     * time — the gather copy is what the packager reads.
     *
     * @return list<array{path:string,type:string,ulid:string,height:?int,language:?string,forced:bool,hearing_impaired:bool,name:?string}>
     */
    private function subtitleInputs(Video $video, string $gatherDir): array
    {
        $inputs = [];

        foreach ($video->streams->where('type', 'subtitle') as $sub) {
            $local = $this->localRenditionPath($sub, $gatherDir);

            if (! is_file($local) || ! str_contains($vtt = (string) file_get_contents($local), '-->')) {
                Log::warning('Skipping subtitle with missing/cue-less VTT', ['video' => $video->id, 'stream' => $sub->id]);

                continue;
            }

            if (($repaired = WebVtt::sanitize($vtt)) !== $vtt) {
                file_put_contents($local, $repaired);
                Log::info('Repaired malformed WebVTT before packaging', ['video' => $video->id, 'stream' => $sub->id]);
            }

            $inputs[] = [
                'path' => $local,
                'type' => 'subtitle',
                'ulid' => $sub->ulid,
                'height' => null,
                'language' => $sub->language,
                'forced' => $sub->forced,
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
     * Package the subtitle tracks and graft them into the manifests already written in the gather
     * tree, before the sync. Delegated to {@see SubtitlePackager} so the repair command can redo
     * exactly this against a tree rebuilt from S3. Subtitles are non-critical, so a format that
     * cannot be packaged leaves the video servable without them.
     */
    private function packageSubtitles(Video $video, string $gatherDir, SubtitlePackager $subtitles): void
    {
        $subtitles->packageAndGraft(
            $this->subtitleInputs($video, $gatherDir),
            $gatherDir,
            // A segment longer than the video spans it in one piece.
            (int) ceil((float) $video->duration) + 2,
            $this->timeout - 120,
            ['video' => $video->id],
            fn () => $this->heartbeat($video),
        );
    }

    /**
     * Record each stream's stored footprint from disk after {@see pruneProcessedRenditions}, so what's
     * measured equals what reaches S3 — no template lookup needed. `package_size` = the CMAF package
     * (its own `{ulid}/` dir, always synced); `file_size` = the raw processed rendition
     * ({@see localRenditionPath}), present only when the template keeps processed files (pruning removed
     * it otherwise, so it stays null). The two are never summed here; the video total lives in
     * {@see VideoData}. The `original` isn't packaged and keeps its uploaded `file_size`.
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
     *  {@see ManifestEditor}'s.
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

        $this->cancelEncodeBatches($video);
        $this->finalizeVideoIfReady($video, $e->getMessage());
    }
}
