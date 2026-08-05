<?php

namespace App\Services;

use App\Models\Video;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Intl\Languages;

/**
 * Probes the local source file (downloaded by {@see \App\Jobs\PrepareVideoJob}, never main S3) and
 * creates the video's rendition, audio and subtitle streams, filling the probe-derived fields left
 * empty at ingestion.
 */
class CreateVideoStreamsService
{
    use Concerns\ResolvesScale;

    /** Subtitle codecs convertible to webvtt; bitmap formats (PGS, DVD/DVB, xsub) are skipped. */
    private const TEXT_SUBTITLE_CODECS = ['subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'];

    /** How far back from the container's end a track's last packet is looked for before scanning the whole file. */
    private const TAIL_PROBE_SECONDS = 300.0;

    private string $localPath = '';

    private float $containerDuration = 0.0;

    /** Where each audio track's packets stop, keyed by ffprobe stream index. */
    private array $trackEnds = [];

    private array $videoStreamCache = [];

    private array $audioStreamCache = [];

    /** Track names already used in this video, bucketed per codec type, to keep each label unique within its type. */
    private array $usedNames = [];

    /** Per-track metadata from the mkvmerge header probe, keyed by track index (== ffprobe stream index):
     *  `[index => ['language' => ?string BCP-47, 'name' => ?string]]`. Empty for non-Matroska sources. */
    private array $trackMeta = [];

    /** Fallback for {@see sourceBitRate} when the video track states no rate of its own. */
    private int $containerVideoBitRate = 0;

    public function handle(Video $video, string $localPath): void
    {
        $outputs = $video->template->query['outputs'] ?? [];

        if (empty($outputs)) {
            throw new Exception("No outputs configured for template {$video->template->ulid}");
        }

        $this->trackMeta = $this->probeContainerTracks($localPath);

        $mediaInfo = $this->probeMedia($video, $localPath);

        $streamCollection = $mediaInfo['streamCollection'];

        $this->localPath = $localPath;
        $this->containerDuration = (float) $mediaInfo['duration'];
        $this->trackEnds = [];

        $this->validateSourceVideo($streamCollection->videos()->first());

        foreach ($streamCollection->audios() as $audio) {
            $this->validateSourceAudio($audio);

            // Probed here, before the write transaction: the local source is only around during
            // this job, and one probe per track serves however many renditions reuse it.
            $index = (int) $audio->get('index');
            $this->trackEnds[$index] = $this->trackEnd($index);
        }

        DB::transaction(function () use ($video, $streamCollection, $outputs, $mediaInfo) {
            $this->createOutputsAndStreams($video, $streamCollection, $outputs);

            $video->update([
                'duration' => $mediaInfo['duration'],
                'aspect_ratio' => $mediaInfo['aspectRatio'],
            ]);
        });
    }

    private function probeMedia(Video $video, string $localPath): array
    {
        $ffprobe = FFProbe::create();

        // Probe once and reuse the collection: each streams() call re-parses the file.
        $streamCollection = $ffprobe->streams($localPath);

        $videoStream = $streamCollection->videos()->first();

        if ($videoStream?->get('codec_type') != 'video') {
            throw new Exception("Invalid video file for video {$video->ulid}");
        }

        $format = $ffprobe->format($localPath);

        $duration = $format->get('duration')
            ?? $videoStream->get('duration');

        // A duration-less source would only die much later (the segment planner yields no
        // windows); reject it here where the error is attributable to the file.
        if (! is_numeric($duration) || (float) $duration <= 0) {
            throw new Exception("Could not determine a positive duration for video {$video->ulid}");
        }

        $this->containerVideoBitRate = $this->containerVideoBitRate($format, $streamCollection);

        return [
            'streamCollection' => $streamCollection,
            'duration' => $duration,
            'aspectRatio' => $videoStream->get('display_aspect_ratio', $this->calculateAspectRatio($videoStream->get('width'), $videoStream->get('height'))),
        ];
    }

    /**
     * What the container spends on video, for sources whose video track states no rate of its own.
     * The container's rate covers every track, so the tracks that do state one are discounted; what
     * remains still bounds the video from above rather than measuring it, which is all its readers
     * ask of it — a ceiling. Zero when the container states no rate either.
     */
    private function containerVideoBitRate(Format $format, StreamCollection $streams): int
    {
        $containerRate = $format->get('bit_rate');

        if (! is_numeric($containerRate)) {
            return 0;
        }

        $stated = 0;

        foreach ($streams as $stream) {
            if ($stream->get('codec_type') !== 'video' && is_numeric($rate = $stream->get('bit_rate'))) {
                $stated += (int) $rate;
            }
        }

        return max(0, (int) $containerRate - $stated);
    }

    /**
     * ffprobe only surfaces the ISO 639-2 language and drops the BCP-47 region (`es-419`/`pt-PT`),
     * so probe the container with mkvmerge for the richer `language_ietf`. Returns an empty map for
     * non-Matroska inputs (MP4 carries no BCP-47) or any failure — callers fall back to ffprobe tags.
     *
     * @return array<int, array{language: ?string, name: ?string}>
     */
    private function probeContainerTracks(string $localPath): array
    {
        try {
            $result = Process::timeout(60)->run(['mkvmerge', '-J', $localPath]);

            $map = [];

            foreach (data_get(json_decode($result->output(), true), 'tracks', []) as $track) {
                $props = $track['properties'] ?? [];

                $map[(int) $track['id']] = [
                    // language_ietf carries the region (es-419); mkvmerge derives it from the ISO code
                    // when absent, so it's always the richest form. Blank/null for MP4.
                    'language' => $props['language_ietf'] ?? $props['language'] ?? null,
                    'name' => $props['track_name'] ?? null,
                ];
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning('mkvmerge probe failed; falling back to ffprobe language tags', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function validateSourceVideo(?FFStream $source): void
    {
        if (! $source) {
            throw new Exception('Video stream not found in source media');
        }

        $width = $source->get('width');
        $height = $source->get('height');
        $codec = $source->get('codec_name');

        if (! $width || ! is_numeric($width) || (int) $width <= 0) {
            throw new Exception("Invalid source video width: {$width}");
        }

        if (! $height || ! is_numeric($height) || (int) $height <= 0) {
            throw new Exception("Invalid source video height: {$height}");
        }

        if (! $codec || ! is_string($codec)) {
            throw new Exception('Source video codec not detected');
        }
    }

    private function validateSourceAudio(?FFStream $source): void
    {
        if (! $source) {
            return;
        }

        $codec = $source->get('codec_name');

        if (! $codec || ! is_string($codec)) {
            throw new Exception('Source audio codec not detected');
        }
    }

    private function createOutputsAndStreams(Video $video, StreamCollection $streamCollection, array $outputs): void
    {
        $this->videoStreamCache = [];
        $this->audioStreamCache = [];
        $this->usedNames = [];

        foreach ($outputs as $index => $outputConfig) {
            $this->createRenditionOutput($video, $streamCollection, $outputConfig, $index);
        }

        $this->createSubtitleStreams($video, $streamCollection->all());
    }

    private function createRenditionOutput(
        Video $video,
        StreamCollection $streamCollection,
        array $outputConfig,
        int $index,
    ): void {
        $output = $video->outputs()->create();
        $sourceVideo = $streamCollection->videos()->first();

        $videoIds = $this->resolveVideoStreams(
            $video,
            $sourceVideo,
            $outputConfig['variants'] ?? [],
            $outputConfig['video_codec'] ?? null,
        );

        // A misconfigured template (e.g. an empty/malformed `variants` list) would otherwise attach
        // zero video streams; PackageVideoJob only discovers that deep into packaging, as an empty
        // input list to shaka-packager. Fail loudly here instead.
        if (empty($videoIds)) {
            throw new Exception("Output {$index} resolved no video renditions from template {$video->template->ulid}");
        }

        $audioConfig = $outputConfig['audio'] ?? [];
        $audioIds = $this->getOrCreateAudioStreams($video, $streamCollection, $audioConfig);

        $output->streams()->attach([...$videoIds, ...$audioIds]);
    }

    /**
     * Which template variants THIS source gets, decided by what each would actually produce.
     * {@see ResolvesScale::resolveOutputDimensions} never upscales, so comparing nominal sizes was
     * both redundant as an upscale guard and wrong at the edges: a real 4K master is rarely 3840
     * exactly (cinema crops — 3832x1596 — run a few pixels short) and lost its 4K rung to that
     * shortfall. Instead, resolve every variant against the source and keep one per DISTINCT
     * output size; among variants that would produce the same pixels, the nominally smallest wins —
     * its quality knobs are the ones tuned for that resolution. Template order is preserved.
     */
    private function filterVariants(FFStream $sourceVideo, array $variants): array
    {
        $sourceWidth = (int) $sourceVideo->get('width');
        $sourceHeight = (int) $sourceVideo->get('height');

        $bySize = collect($variants)
            ->sortBy(fn (array $variant) => max($variant['width'] ?? 0, $variant['height'] ?? 0));

        $seen = [];
        $keptKeys = [];

        foreach ($bySize as $key => $variant) {
            [$width, $height] = $this->resolveOutputDimensions($variant, $sourceWidth, $sourceHeight);

            if (isset($seen["{$width}x{$height}"])) {
                continue;
            }

            $seen["{$width}x{$height}"] = true;
            $keptKeys[$key] = true;
        }

        return array_values(array_intersect_key($variants, $keptKeys));
    }

    private function resolveVideoStreams(Video $video, FFStream $sourceVideo, array $variants, ?string $videoCodec): array
    {
        $streamIds = [];

        $derivedGop = $this->deriveGopSize($this->sourceFrameRate($sourceVideo));

        foreach ($this->filterVariants($sourceVideo, $variants) as $variantConfig) {
            $variantConfig = array_merge(['video_codec' => $videoCodec], $variantConfig);

            if (empty($variantConfig['gop_size']) && $derivedGop) {
                $variantConfig['gop_size'] = $derivedGop;
            }

            $key = $this->streamSignature($variantConfig);

            if (! isset($this->videoStreamCache[$key])) {
                $stream = $this->createStream(
                    video: $video,
                    stream: $sourceVideo,
                    codecType: 'video',
                    inputParams: $variantConfig,
                );
                $this->videoStreamCache[$key] = $stream->id;
            }

            $streamIds[] = $this->videoStreamCache[$key];
        }

        return $streamIds;
    }

    /**
     * One audio rendition per (source track × distinct resolved channel count). The template's
     * `channels` list is a LADDER, not a lookup: each entry resolves against the track — clamped
     * to the track's own channels, never upmixed, mirroring {@see filterVariants} — and entries
     * that resolve to the same count collapse into the nominally smallest, whose bitrate was tuned
     * for that layout. A 5.1 source with a [stereo, 5.1] template yields both (the stereo one a
     * downmix, `-ac 2`); a stereo source yields only its stereo. The old semantics matched entries
     * to tracks by exact channel count, so a 5.1-only source silently produced no stereo at all.
     */
    private function getOrCreateAudioStreams(Video $video, StreamCollection $streamCollection, array $audioConfig): array
    {
        $ladder = collect($audioConfig['channels'] ?? [])
            ->sortBy(fn (array $config) => (int) ($config['channels'] ?? PHP_INT_MAX))
            ->values();
        $sharedAudioParams = collect($audioConfig)->except('channels')->toArray();

        $streamIds = [];

        foreach ($streamCollection->audios() as $stream) {
            $sourceChannels = max(1, (int) $stream->get('channels'));

            // Keyed by resolved count: the first (nominally smallest) entry wins each rung.
            $rungs = [];
            foreach ($ladder as $config) {
                $channels = min((int) ($config['channels'] ?? 0) ?: $sourceChannels, $sourceChannels);
                $rungs[$channels] ??= $config;
            }

            foreach ($rungs as $channels => $config) {
                $inputParams = array_merge($sharedAudioParams, $config, ['channels' => (string) $channels]);
                $key = $stream->get('index').':'.$this->streamSignature($inputParams);

                if (! isset($this->audioStreamCache[$key])) {
                    $created = $this->createStream(
                        video: $video,
                        stream: $stream,
                        codecType: $stream->get('codec_type'),
                        inputParams: $inputParams,
                        // Only when the track splits into several rungs: the layout is what tells
                        // "Español" apart from "Español" in every player menu (and keeps the
                        // packager from merging same-language same-label sets).
                        nameSuffix: count($rungs) > 1 ? ' ('.self::channelLayoutLabel($channels).')' : '',
                    );
                    $this->audioStreamCache[$key] = $created->id;
                }

                $streamIds[] = $this->audioStreamCache[$key];
            }
        }

        return $streamIds;
    }

    private static function channelLayoutLabel(int $channels): string
    {
        return match ($channels) {
            1 => 'Mono',
            2 => 'Stereo',
            6 => '5.1',
            8 => '7.1',
            default => "{$channels}ch",
        };
    }

    private function streamSignature(array $params): string
    {
        ksort($params);

        return md5(json_encode($params));
    }

    private function createSubtitleStreams(Video $video, array $streams): void
    {
        foreach ($streams as $stream) {
            if ($stream->get('codec_type') !== 'subtitle') {
                continue;
            }

            if (! $this->isTextSubtitle($stream)) {
                Log::info('Skipping bitmap subtitle stream', [
                    'video' => $video->id,
                    'index' => $stream->get('index'),
                    'codec' => $stream->get('codec_name'),
                ]);

                continue;
            }

            if ($this->isEmptySubtitle($stream)) {
                Log::info('Skipping empty subtitle stream', [
                    'video' => $video->id,
                    'index' => $stream->get('index'),
                ]);

                continue;
            }

            $this->createStream(
                video: $video,
                stream: $stream,
                codecType: 'subtitle',
                inputParams: null,
            );
        }
    }

    private function isTextSubtitle(FFStream $stream): bool
    {
        return in_array($stream->get('codec_name'), self::TEXT_SUBTITLE_CODECS, true);
    }

    /**
     * Best language for a track: the container-probed BCP-47 (mkvmerge, e.g. `es-419`) → the ISO code
     * in ffprobe's stream tags → null. Only real ISO 639 codes survive (validated on the primary
     * subtag so `es-419` passes); `und`/`mis`/`mul`/`zxx` placeholders and nonexistent codes become
     * null, so shaka emits a valid empty `lang=` and the track is kept rather than failing the run.
     */
    private function sourceLanguage(FFStream $stream): ?string
    {
        $fromContainer = $this->trackMeta[$stream->get('index')]['language'] ?? null;

        $language = ! empty($fromContainer)
            ? $fromContainer
            : (($stream->get('tags') ?? [])['language'] ?? null);

        if ($language === null || trim($language) === '') {
            return null;
        }

        $primary = strtolower(explode('-', trim($language))[0]);

        return Languages::exists($primary) || Languages::alpha3CodeExists($primary) ? $language : null;
    }

    /** One of ffprobe's `disposition` flags, as a boolean. */
    private function hasDisposition(FFStream $stream, string $flag): bool
    {
        return (bool) data_get($stream->get('disposition'), $flag, 0);
    }

    /**
     * Source bit rate in bps, most precise source first: Matroska carries none per stream, so fall
     * back to mkvmerge's `BPS` tag (suffixed per language, e.g. `BPS-eng`) and then to the container
     * ({@see containerVideoBitRate}). Zero only when the file states no rate anywhere.
     *
     * The container used to be rejected here as too coarse — it sums every track — which was true of
     * the only reader at the time ({@see \App\Services\Concerns\DetectsStreamCopy}, whose fast-path is
     * unreachable anyway: video is always window-cut). It stopped being the whole story once
     * {@see \App\Services\Concerns\ResolvesRateControl} began reading it, because there a coarse
     * number is a ceiling and zero is *no* ceiling: every MKV re-encoded uncapped.
     */
    private function sourceBitRate(FFStream $stream): int
    {
        $bitRate = $stream->get('bit_rate');

        if (is_numeric($bitRate)) {
            return (int) $bitRate;
        }

        foreach (($stream->get('tags') ?? []) as $tag => $value) {
            if (stripos((string) $tag, 'BPS') === 0 && is_numeric($value)) {
                return (int) $value;
            }
        }

        return $this->containerVideoBitRate;
    }

    /**
     * Where THIS track's own packets stop. The container duration is the longest track, so an audio
     * pass measured against it reads as truncated whenever another track outlives it — and a source
     * read that dies mid-file exits 0, so that check is the only thing standing between a half-length
     * audio track and the manifest. Null when the track can't be timed; the caller falls back.
     */
    private function trackEnd(int $index): ?float
    {
        $tail = max(0.0, $this->containerDuration - self::TAIL_PROBE_SECONDS);

        // A track ending before the tail window reads as empty; only then pay for a full scan.
        return $this->lastPacketTime($index, $tail) ?? ($tail > 0.0 ? $this->lastPacketTime($index, 0.0) : null);
    }

    private function lastPacketTime(int $index, float $from): ?float
    {
        // Generous: the fallback path walks the whole file when a track ends before the tail window.
        $result = Process::timeout(600)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', (string) $index,
            '-show_entries', 'packet=pts_time',
            '-of', 'csv=p=0',
            // From this second to EOF, so the tail is read without walking the whole file.
            '-read_intervals', sprintf('%.3f%%', $from),
            $this->localPath,
        ]);

        if (! $result->successful()) {
            Log::warning('Track tail probe failed', ['index' => $index, 'error' => $result->errorOutput()]);

            return null;
        }

        $end = 0.0;

        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);

            if (is_numeric($line)) {
                $end = max($end, (float) $line);
            }
        }

        return $end > 0.0 ? $end : null;
    }

    /** What a derived keyframe interval aims for, before it is rounded to divide a segment. */
    private const TARGET_KEYFRAME_SECONDS = 2.0;

    /**
     * Frames between keyframes, for a template that pinned none. A GOP only means something in
     * seconds — the same 60 frames is 2.503s of a 23.976fps film and 2.4s of a 25fps broadcast — so
     * a fixed frame count drifts against `packager.segment_duration`, which the packager can only
     * honour by cutting at a keyframe. Derived the other way round: a whole number of GOPs per
     * segment, each as close to {@see TARGET_KEYFRAME_SECONDS} as the source's rate allows.
     * Null when the rate is unknown, leaving the encoder its own default.
     */
    private function deriveGopSize(float $fps): ?int
    {
        if ($fps <= 0) {
            return null;
        }

        $segment = (float) config('packager.segment_duration');
        $gopsPerSegment = max(1, (int) round($segment / self::TARGET_KEYFRAME_SECONDS));

        return max(1, (int) round($fps * $segment / $gopsPerSegment));
    }

    /** Source frame rate, from the `avg_frame_rate` fraction ("24000/1001"). Zero when unknown. */
    private function sourceFrameRate(FFStream $stream): float
    {
        [$num, $den] = array_pad(explode('/', (string) $stream->get('avg_frame_rate')), 2, '1');

        return (float) $den > 0 ? round((float) $num / (float) $den, 3) : 0.0;
    }

    /**
     * A source subtitle track with no cues yields an empty (header-only) VTT that the packager
     * rejects with PARSER_FAILURE, so drop it at ingestion. Detected from the cue count ffprobe
     * exposes (MKV carries it as `NUMBER_OF_FRAMES` tags); when no count is available we keep the
     * track rather than guess it away.
     */
    private function isEmptySubtitle(FFStream $stream): bool
    {
        $nbFrames = $stream->get('nb_frames');

        if (is_numeric($nbFrames)) {
            return (int) $nbFrames === 0;
        }

        foreach (($stream->get('tags') ?? []) as $tag => $value) {
            if (stripos((string) $tag, 'NUMBER_OF_FRAMES') === 0 && is_numeric($value)) {
                return (int) $value === 0;
            }
        }

        return false;
    }

    /**
     * Keep each track's display name unique within its codec type: a repeated name gets a " (n)"
     * suffix so players (and the HLS subtitle group) don't collide on same-named tracks. An audio
     * and a subtitle track may share a name. Case-insensitive.
     */
    private function uniqueName(string $name, string $codecType): string
    {
        $used = &$this->usedNames[$codecType];
        $used ??= [];
        $candidate = $name;
        $n = 1;

        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $name.' ('.(++$n).')';
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * Track label: the source title, else its language, else the media type. Commas are stripped
     * because they delimit shaka descriptor fields ({@see \App\Services\PackagerCommandBuilder}).
     */
    private function baseName(FFStream $stream, string $codecType): string
    {
        $tags = $stream->get('tags') ?? [];

        $label = $this->trackMeta[$stream->get('index')]['name']
            ?? (! empty($tags['title']) ? $tags['title'] : $this->sourceLanguage($stream));

        return ! empty($label) ? str_replace(',', ' ', $label) : ucfirst($codecType);
    }

    private function createStream(
        Video $video,
        FFStream $stream,
        string $codecType,
        ?array $inputParams = null,
        string $nameSuffix = '',
    ) {
        $ulid = Str::ulid();
        $extension = $this->getStreamExtension($codecType, $inputParams);
        $path = "$video->ulid/$codecType/$ulid.$extension";

        [$width, $height] = $this->resolveStreamDimensions($stream, $codecType, $inputParams);

        $attributes = [
            'path' => $path,
            'type' => $codecType,
            'meta' => [
                'index' => $stream->get('index'),
                ...($codecType === 'video' ? [
                    'source_height' => $stream->get('height'),
                    // DetectsStreamCopy needs both dimensions for the copy fast-path.
                    'source_width' => $stream->get('width'),
                    'source_codec' => $stream->get('codec_name'),
                    // GPU jobs hardware-decode only when codec AND pixel format are supported.
                    'source_pix_fmt' => $stream->get('pix_fmt'),
                    'source_bit_rate' => $this->sourceBitRate($stream),
                    'source_fps' => $this->sourceFrameRate($stream),
                ] : []),
                // Accessibility dispositions; packaging turns them into DASH Role/Accessibility and
                // HLS CHARACTERISTICS ({@see PackagerCommandBuilder}). The rest of the audio probe
                // has no reader — the source's codec and bit rate live only on video renditions.
                ...($codecType === 'audio' ? [
                    'hearing_impaired' => $this->hasDisposition($stream, 'hearing_impaired'),
                    'visual_impaired' => $this->hasDisposition($stream, 'visual_impaired'),
                    // What this track's encode is measured against ({@see \App\Jobs\EncodeSidecarTracksJob}).
                    'source_end' => $this->trackEnds[(int) $stream->get('index')] ?? null,
                ] : []),
                ...($codecType === 'subtitle' ? [
                    'forced' => $this->hasDisposition($stream, 'forced'),
                    'hearing_impaired' => $this->hasDisposition($stream, 'hearing_impaired'),
                ] : []),
            ],
            // A rendition carries no label anywhere — not in a manifest, not in the panel (which
            // shows its height) — so storing the source title on it only made "name" look load-bearing.
            'name' => $codecType === 'video' ? null : $this->uniqueName($this->baseName($stream, $codecType).$nameSuffix, $codecType),
            'input_params' => $inputParams,
            'width' => $width,
            'height' => $height,
        ];

        if ($codecType === 'audio') {
            $attributes['language'] = $this->sourceLanguage($stream);
            $attributes['channels'] = (int) ($inputParams['channels'] ?? $stream->get('channels'));
        }

        if ($codecType === 'subtitle') {
            $attributes['language'] = $this->sourceLanguage($stream);
            $attributes['forced'] = $this->hasDisposition($stream, 'forced');
        }

        return $video->streams()->create($attributes);
    }

    private function resolveStreamDimensions(FFStream $stream, string $codecType, ?array $inputParams): array
    {
        if ($codecType !== 'video') {
            return [$stream->get('width'), $stream->get('height')];
        }

        return $this->resolveOutputDimensions(
            $inputParams ?? [],
            $stream->get('width'),
            $stream->get('height'),
        );
    }

    /**
     * The stored file extension for a rendition. Subtitles are always WebVTT; audio/video take
     * the container of their target codec (config/ffmpeg.php `format`) so the stored key matches
     * the muxed bytes — nginx-vod identifies the file by content, not extension.
     */
    private function getStreamExtension(string $codecType, ?array $inputParams): string
    {
        if ($codecType === 'subtitle') {
            return 'vtt';
        }

        $codec = $codecType === 'video'
            ? data_get($inputParams, 'video_codec')
            : data_get($inputParams, 'audio_codec', 'aac');

        return ChunkTranscodeService::formatForCodec($codec);
    }

    private function calculateAspectRatio(int $width, int $height): string
    {
        $a = $width;
        $b = $height;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $width / $a.':'.$height / $a;
    }
}
