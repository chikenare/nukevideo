<?php

namespace App\Services;

use App\DTOs\UploadMeta;
use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnVideoUploadedService
{
    use Concerns\ResolvesScale;

    /** Subtitle codecs convertible to webvtt; bitmap formats (PGS, DVD/DVB, xsub) are skipped. */
    private const TEXT_SUBTITLE_CODECS = ['subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'];

    private UploadMeta $meta;

    private array $videoStreamCache = [];

    private array $audioStreamCache = [];

    public function __construct(private UppyS3Service $uppyService) {}

    public function handle(string $key, int $size): void
    {
        $meta = $this->uppyService->getUploadMeta($key);

        if (! $meta) {
            throw new Exception("Upload metadata not found for key: {$key}");
        }

        $this->meta = $meta;

        // Idempotency: the upload key is the original stream's path. Already ingested means a
        // redelivery/retry — skip. The unique streams.path index handles concurrent races below.
        if (Stream::where('path', $key)->exists()) {
            Log::info('Upload already ingested; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        $mediaInfo = $this->probeMedia($key);

        [$user, $project, $template] = $this->resolveUserProjectAndTemplate();

        $outputs = $template->query['outputs'] ?? [];

        $streamCollection = $mediaInfo['streamCollection'];

        $this->validateSourceVideo($streamCollection->videos()->first());

        foreach ($streamCollection->audios() as $audio) {
            $this->validateSourceAudio($audio);
        }

        if (empty($outputs)) {
            throw new Exception("No outputs configured for template {$this->meta->template}");
        }

        try {
            $video = DB::transaction(function () use ($user, $project, $template, $mediaInfo, $key, $size, $outputs) {
                $video = Video::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'template_id' => $template->id,
                    'name' => $this->meta->filename,
                    'status' => VideoStatus::PENDING->value,
                    'duration' => $mediaInfo['duration'],
                    'aspect_ratio' => $mediaInfo['aspectRatio'],
                    'external_user_id' => $this->meta->externalUserId,
                    'external_resource_id' => $this->meta->externalResourceId,
                ]);

                $video->streams()->create([
                    'path' => $key,
                    'name' => 'Original',
                    'type' => 'original',
                    'size' => $size,
                    'meta' => [],
                ]);

                $this->createOutputsAndStreams($video, $mediaInfo['streamCollection'], $outputs);

                return $video;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery won the race to insert the original stream; it owns this upload.
            Log::info('Concurrent ingestion detected; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        activity('video')
            ->performedOn($video)
            ->causedBy($user)
            ->event('video_processing_started')
            ->log("Video queued for processing: {$video->name}");

        UsageService::record($user->id, 'upload_bytes', $size, $this->meta->externalUserId ?? '');

        $this->uppyService->forgetUploadMeta($key);

        WebhookDispatcher::forVideo('video.created', $video);
    }

    private function resolveUserProjectAndTemplate(): array
    {
        $user = User::where('ulid', $this->meta->user)->first();

        if (! $user) {
            throw new Exception("User with ulid {$this->meta->user} not found");
        }

        $project = $user->projects()->where('ulid', $this->meta->project)->first();

        if (! $project) {
            throw new Exception("Project with ulid {$this->meta->project} not found");
        }

        $template = $project->templates()->where('ulid', $this->meta->template)->first();

        if (! $template) {
            throw new Exception("Template with ulid {$this->meta->template} not found");
        }

        return [$user, $project, $template];
    }

    private function probeMedia(string $key): array
    {
        $url = Storage::temporaryUrl($key, now()->addDay());
        $ffprobe = FFProbe::create();

        // Probe once: each streams() call re-reads the remote object.
        $streamCollection = $ffprobe->streams($url);

        $videoStream = $streamCollection->videos()->first();

        if ($videoStream?->get('codec_type') != 'video') {
            throw new Exception("Invalid video file: $key");
        }

        $duration = $ffprobe->format($url)->get('duration')
            ?? $videoStream->get('duration');

        return [
            'streamCollection' => $streamCollection,
            'duration' => $duration,
            'aspectRatio' => $videoStream->get('display_aspect_ratio', $this->calculateAspectRatio($videoStream->get('width'), $videoStream->get('height'))),
        ];
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

        $videoIds = $this->resolveVideoStreams($video, $sourceVideo, $outputConfig['variants'] ?? []);

        // A misconfigured template (e.g. an empty/malformed `variants` list) would otherwise attach
        // zero video streams; PackageVideoJob only discovers that deep into packaging, as an empty
        // input list to shaka-packager. Fail loudly here instead.
        if (empty($videoIds)) {
            throw new Exception("Output {$index} resolved no video renditions from template {$this->meta->template}");
        }

        $audioConfig = $outputConfig['audio'] ?? [];
        $audioIds = $this->getOrCreateAudioStreams($video, $streamCollection, $audioConfig);

        $output->streams()->attach([...$videoIds, ...$audioIds]);
    }

    private function filterVariants(FFStream $sourceVideo, array $variants): array
    {
        $sourceMax = max($sourceVideo->get('width'), $sourceVideo->get('height'));

        $kept = array_values(array_filter($variants, function (array $variant) use ($sourceMax) {
            $variantMax = max($variant['width'] ?? 0, $variant['height'] ?? 0);

            return $sourceMax >= $variantMax;
        }));

        if (empty($kept) && ! empty($variants)) {
            usort($variants, fn (array $a, array $b) => max($a['width'] ?? 0, $a['height'] ?? 0) <=> max($b['width'] ?? 0, $b['height'] ?? 0));

            return [$variants[0]];
        }

        return $kept;
    }

    private function resolveVideoStreams(Video $video, FFStream $sourceVideo, array $variants): array
    {
        $streamIds = [];

        foreach ($this->filterVariants($sourceVideo, $variants) as $variantConfig) {
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

    private function getOrCreateAudioStreams(Video $video, StreamCollection $streamCollection, array $audioConfig): array
    {
        $channelConfigsList = $audioConfig['channels'] ?? [];
        $channelConfigs = collect($channelConfigsList)->keyBy('channels');
        $singleConfig = count($channelConfigsList) === 1 ? $channelConfigsList[0] : null;
        $sharedAudioParams = collect($audioConfig)->except('channels')->toArray();

        $streamIds = [];

        foreach ($streamCollection->audios() as $stream) {
            $sourceChannels = (string) $stream->get('channels');
            $channelConfig = $singleConfig ?? $channelConfigs->get($sourceChannels);

            if (! $channelConfig) {
                continue;
            }

            $inputParams = array_merge($sharedAudioParams, $channelConfig);
            $key = $stream->get('index').':'.$this->streamSignature($inputParams);

            if (! isset($this->audioStreamCache[$key])) {
                $created = $this->createStream(
                    video: $video,
                    stream: $stream,
                    codecType: $stream->get('codec_type'),
                    inputParams: $inputParams,
                );
                $this->audioStreamCache[$key] = $created->id;
            }

            $streamIds[] = $this->audioStreamCache[$key];
        }

        return $streamIds;
    }

    private function streamSignature(array $params): string
    {
        ksort($params);

        return md5(json_encode($params));
    }

    private function createSubtitleStreams(Video $video, array $streams): void
    {
        $usedNames = [];

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

            $tags = $stream->get('tags') ?? [];

            $this->createStream(
                video: $video,
                stream: $stream,
                codecType: 'subtitle',
                inputParams: null,
                name: $this->uniqueName($this->streamDisplayName($tags), $usedNames),
            );
        }
    }

    private function isTextSubtitle(FFStream $stream): bool
    {
        return in_array($stream->get('codec_name'), self::TEXT_SUBTITLE_CODECS, true);
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
     * Keep each track's display name unique within the video: a repeated name gets a " (n)" suffix
     * so players (and the HLS subtitle group) don't collide on same-named tracks. Case-insensitive.
     */
    private function uniqueName(string $name, array &$used): string
    {
        $candidate = $name;
        $n = 1;

        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $name.' ('.(++$n).')';
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /** Source titles often already carry the language, so avoid "Inglés (eng) (eng)". */
    private function streamDisplayName(array $tags): string
    {
        $title = $tags['title'] ?? 'Unknown';
        $language = $tags['language'] ?? null;

        if ($language && ! str_contains($title, "({$language})")) {
            return "{$title} ({$language})";
        }

        return $title;
    }

    private function createStream(
        Video $video,
        FFStream $stream,
        string $codecType,
        ?array $inputParams = null,
        ?string $name = null,
    ) {
        $ulid = Str::ulid();
        $extension = $this->getStreamExtension($codecType, $inputParams);
        $path = "$video->ulid/$codecType/$ulid.$extension";

        [$width, $height] = $this->resolveStreamDimensions($stream, $codecType, $inputParams);

        $tags = $stream->get('tags') ?? [];

        $attributes = [
            'path' => $path,
            'type' => $codecType,
            'size' => 0,
            'meta' => [
                'index' => $stream->get('index'),
                ...($codecType === 'video' ? [
                    'source_height' => $stream->get('height'),
                    // DetectsStreamCopy needs both dimensions for the copy fast-path.
                    'source_width' => $stream->get('width'),
                    'source_codec' => $stream->get('codec_name'),
                    'source_bit_rate' => (int) $stream->get('bit_rate'),
                ] : []),
                ...($codecType === 'audio' ? [
                    'source_codec' => $stream->get('codec_name'),
                    'source_bit_rate' => (int) $stream->get('bit_rate'),
                    'source_sample_rate' => $stream->get('sample_rate'),
                    'source_channels' => $stream->get('channels'),
                ] : []),
                // Carry the source's forced flag so packaging emits forced_subtitle=1 for it.
                ...($codecType === 'subtitle' ? [
                    'forced' => (bool) data_get($stream->get('disposition'), 'forced', 0),
                ] : []),
            ],
            'name' => $name ?? $this->streamDisplayName($tags),
            'input_params' => $inputParams,
            'width' => $width,
            'height' => $height,
        ];

        if ($codecType === 'audio') {
            $attributes['language'] = ($stream->get('tags') ?? [])['language'] ?? null;
            $attributes['channels'] = $stream->get('channels');
        }

        if ($codecType === 'subtitle') {
            $attributes['language'] = ($stream->get('tags') ?? [])['language'] ?? null;
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
