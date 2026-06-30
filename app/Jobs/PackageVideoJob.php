<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\Concerns\SyncsViaAwsCli;
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
 * packages to primary S3 in a single `aws s3 sync`. Folding concat + packaging here avoids staging
 * the renditions to the mirror and reading them back. Packaging precedes completion, so a COMPLETED
 * video is already synced and servable.
 */
class PackageVideoJob implements ShouldBeUnique, ShouldQueue
{
    use CompletesVideo, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaAwsCli;

    public $tries = 1;

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
            self::dispatch($video->id)->onQueue('video-processing');
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

            $this->pruneProcessedRenditions($video);
            $this->syncToPrimary($video, $gatherDir);

            $video->outputs()->update(['status' => VideoStatus::COMPLETED->value]);
            $this->finalizeVideoIfReady($video);
        } finally {
            Storage::disk('local')->deleteDirectory($video->gatherDir());
            Storage::disk('local')->deleteDirectory($video->chunkstageDir());
        }
    }

    /**
     * Pull the single-pass tracks staged on the mirror (`final/`: audio, subtitle, thumbnail,
     * storyboard) into the gather tree. Sync drops the prefix, so they land beside the concatenated
     * video under the destination `{ulid}/` layout.
     */
    private function gatherSidecars(Video $video, string $gatherDir): void
    {
        $this->awsS3Sync(
            'chunks',
            $this->awsS3Uri('chunks', "{$video->ulid}/".Video::FINAL_DIR.'/'),
            $gatherDir,
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

        $this->awsS3Sync(
            'chunks',
            $this->awsS3Uri('chunks', "{$video->ulid}/".Video::CHUNKS_DIR.'/'),
            $stageDir,
            $video,
            $this->timeout - 120,
        );

        foreach ($videoStreams as $stream) {
            $this->concatStream($video, $stream, $stageDir, $gatherDir);
        }
    }

    private function concatStream(Video $video, Stream $stream, string $stageDir, string $gatherDir): void
    {
        $chunks = glob("{$stageDir}/{$stream->ulid}/chunk_*.mp4") ?: [];
        sort($chunks); // zero-padded names sort into concat order

        if (empty($chunks)) {
            throw new RuntimeException("No chunk segments staged for stream {$stream->id}");
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

            $size = filesize($finalLocal);
            if ($size !== false) {
                $stream->update(['size' => $size]);
            }
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
        $formats = $output->formats();

        if (empty($formats)) {
            return;
        }

        $inputs = $this->resolveInputs($output, $gatherDir);

        if (empty($inputs)) {
            return;
        }

        if (! is_dir($gatherDir)) {
            mkdir($gatherDir, 0755, true);
        }

        $this->packageManifests($video, $inputs, $gatherDir, $formats);

        Log::info('Output packaged', ['video' => $video->id, 'output' => $output->id, 'formats' => $formats]);
    }

    /**
     * One packager run per supported resolution (the distinct video heights), all sharing the same
     * segment tree. The tallest is the full `master`; each lower height yields a capped manifest
     * (`master.720`, …) listing only the renditions at or below it, plus every audio track.
     *
     * @param  list<array{path:string,type:string,ulid:string,height:?int}>  $inputs
     * @param  list<string>  $formats
     */
    private function packageManifests(Video $video, array $inputs, string $outputDir, array $formats): void
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

            $this->runPackager($video, $subset, $outputDir, $formats, $isMax ? null : $height);
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
                'height' => $stream->type === 'video' ? (int) $stream->height : null,
            ];
        }

        return $inputs;
    }

    /**
     * Subtitle packager inputs for the video (shared across outputs). Invalid tracks (bitmap, empty
     * source) are already dropped at ingestion ({@see \App\Services\OnVideoUploadedService}); here we
     * additionally skip any VTT that didn't reach the gather tree or carries no cue (`-->`), since a
     * cue-less VTT makes shaka fail the whole run with PARSER_FAILURE.
     *
     * @return list<array{path:string,type:string,ulid:string,height:?int,language:?string,forced:bool,name:?string}>
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
                'name' => $sub->name,
            ];
        }

        return $inputs;
    }

    /** Local path of a rendition in the gather tree, mirroring its `{ulid}/{type}/x.ext` S3 key. */
    private function localRenditionPath(Stream $stream, string $gatherDir): string
    {
        return $gatherDir.'/'.preg_replace('#^[^/]+/#', '', $stream->path, 1);
    }

    /**
     * @param  list<array{path:string,type:string,ulid:string,height:?int}>  $inputs
     * @param  list<string>  $formats
     */
    private function runPackager(Video $video, array $inputs, string $outputDir, array $formats, ?int $cap): void
    {
        $builder = new PackagerCommandBuilder(config('packager.bin'), (int) config('packager.segment_duration'));

        $result = Process::timeout($this->timeout - 120)->run(
            $builder->build($inputs, $outputDir, $formats, $cap),
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
        $builder = new PackagerCommandBuilder(config('packager.bin'), (int) config('packager.segment_duration'));

        if (glob("{$gatherDir}/manifest*.mpd") && $this->runTextPackager($video, $builder, $subs, $gatherDir, $segmentDuration, 'dash')) {
            $subsXml = file_get_contents("{$gatherDir}/_subs.mpd");

            foreach (glob("{$gatherDir}/manifest*.mpd") ?: [] as $mpd) {
                $original = file_get_contents($mpd);
                $edited = $manifests->importDashSubtitles($original, $subsXml);

                if ($edited !== null && $edited !== $original) {
                    file_put_contents($mpd, $edited);
                }
            }

            @unlink("{$gatherDir}/_subs.mpd"); // throwaway master; the grafted text set references the kept segments
        }

        if (glob("{$gatherDir}/master*.m3u8") && $this->runTextPackager($video, $builder, $subs, $gatherDir, $segmentDuration, 'hls')) {
            $subsContent = file_get_contents("{$gatherDir}/_subs.m3u8");

            foreach (glob("{$gatherDir}/master*.m3u8") ?: [] as $m3u8) {
                $original = file_get_contents($m3u8);
                $edited = $manifests->hlsAddSubtitles($original, $subsContent);

                if ($edited !== null && $edited !== $original) {
                    file_put_contents($m3u8, $edited);
                }
            }

            @unlink("{$gatherDir}/_subs.m3u8");
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

        return is_file("{$gatherDir}/".($format === 'dash' ? '_subs.mpd' : '_subs.m3u8'));
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

    /** One `aws s3 sync` of the gathered tree (processed files + packages) to primary `{ulid}/`. */
    private function syncToPrimary(Video $video, string $gatherDir): void
    {
        $this->awsS3Sync(
            's3',
            $gatherDir,
            $this->awsS3Uri('s3', "{$video->ulid}/"),
            $video,
            $this->timeout - 120,
        );

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
