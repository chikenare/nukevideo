<?php

namespace App\Services;

use App\DTOs\UploadMeta;
use App\Enums\OutputFormat;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnVideoUploadedService
{
    use Concerns\ResolvesScale;

    private UploadMeta $meta;

    private array $videoStreamCache = [];

    private array $audioStreamCache = [];

    public function __construct(private UppyS3Service $uppyService) {}

    public function handle(string $key, int $size): void
    {
        $meta = $this->uppyService->getUploadMeta($key);

        if (! $meta) {
            Log::error('Upload metadata not found', ['key' => $key]);
            throw new Exception("Upload metadata not found for key: {$key}");
        }

        $this->meta = $meta;

        $mediaInfo = $this->probeMedia($key);

        [$user, $project, $template] = $this->resolveUserProjectAndTemplate();

        $outputs = $template->query['outputs'] ?? [];

        $streamCollection = $mediaInfo['streamCollection'];

        $this->validateSourceVideo($streamCollection->videos()->first());

        foreach ($streamCollection->audios() as $audio) {
            $this->validateSourceAudio($audio);
        }

        if (empty($outputs)) {
            Log::error('No outputs configured for template', ['template' => $this->meta->template, 'user' => $this->meta->user]);
            throw new Exception('No outputs configured for template');
        }

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
                'status' => VideoStatus::COMPLETED->value,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $this->createOutputsAndStreams($video, $mediaInfo['streamCollection'], $outputs);

            return $video;
        });

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
            Log::error('User not found for upload', ['ulid' => $this->meta->user]);
            throw new Exception("User with ulid {$this->meta->user} not found");
        }

        $project = $user->projects()->where('ulid', $this->meta->project)->first();

        if (! $project) {
            Log::error('Project not found for upload', ['project' => $this->meta->project, 'user' => $this->meta->user]);
            throw new Exception("Project with ulid {$this->meta->project} not found");
        }

        $template = $project->templates()->where('ulid', $this->meta->template)->first();

        if (! $template) {
            Log::error('Template not found for upload', ['template' => $this->meta->template, 'project' => $this->meta->project]);
            throw new Exception("Template with ulid {$this->meta->template} not found");
        }

        return [$user, $project, $template];
    }

    private function probeMedia(string $key): array
    {
        $url = Storage::temporaryUrl($key, now()->addDay());
        $ffprobe = FFProbe::create();

        if ($ffprobe->streams($url)->videos()->first()?->get('codec_type') != 'video') {
            Log::error('Invalid video file: no video codec detected', ['key' => $key]);
            throw new Exception("Invalid video file: $key");
        }

        $streamCollection = $ffprobe->streams($url);

        $videoStream = $streamCollection->videos()->first();

        if (! $videoStream) {
            Log::error('Video stream not found in file', ['key' => $key]);
            throw new Exception('Video stream not found');
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
            Log::error('Video stream not found in source media');
            throw new Exception('Video stream not found in source media');
        }

        $width = $source->get('width');
        $height = $source->get('height');
        $codec = $source->get('codec_name');

        if (! $width || ! is_numeric($width) || (int) $width <= 0) {
            Log::error('Invalid source video width', ['width' => $width]);
            throw new Exception("Invalid source video width: {$width}");
        }

        if (! $height || ! is_numeric($height) || (int) $height <= 0) {
            Log::error('Invalid source video height', ['height' => $height]);
            throw new Exception("Invalid source video height: {$height}");
        }

        if (! $codec || ! is_string($codec)) {
            Log::error('Source video codec not detected');
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
            Log::error('Source audio codec not detected');
            throw new Exception('Source audio codec not detected');
        }
    }

    private function createOutputsAndStreams(Video $video, StreamCollection $streamCollection, array $outputs): void
    {
        $this->videoStreamCache = [];
        $this->audioStreamCache = [];
        $hasStreamingOutputs = false;

        foreach ($outputs as $outputConfig) {
            $format = OutputFormat::from($outputConfig['format']);

            if ($format === OutputFormat::MP4) {
                $this->createMp4Output($video, $streamCollection, $outputConfig);
            } else {
                $hasStreamingOutputs = true;
                $this->createStreamingOutput($video, $streamCollection, $outputConfig, $format);
            }
        }

        if ($hasStreamingOutputs) {
            $this->createSubtitleStreams($video, $streamCollection->all());
        }
    }

    private function createStreamingOutput(
        Video $video,
        StreamCollection $streamCollection,
        array $outputConfig,
        OutputFormat $format,
    ): void {
        $output = $video->outputs()->create(['format' => $format]);
        $sourceVideo = $streamCollection->videos()->first();

        $streamIds = $this->resolveVideoStreams($video, $sourceVideo, $outputConfig['variants'] ?? []);

        $audioConfig = $outputConfig['audio'] ?? [];
        $audioIds = $this->getOrCreateAudioStreams($video, $streamCollection, $audioConfig);
        $streamIds = array_merge($streamIds, $audioIds);

        $output->streams()->attach($streamIds);
    }

    private function createMp4Output(Video $video, StreamCollection $streamCollection, array $outputConfig): void
    {
        $sourceVideo = $streamCollection->videos()->first();

        $variants = $this->filterVariants($sourceVideo, $outputConfig['variants'] ?? []);

        if (empty($variants)) {
            return;
        }

        $output = $video->outputs()->create(['format' => OutputFormat::MP4]);
        $sourceAudio = $streamCollection->audios()->first();
        $audioConfig = collect($outputConfig['audio'] ?? [])->except('channels')->toArray();

        $stream = $this->createMuxedStream($video, $sourceVideo, $sourceAudio, $variants[0], $audioConfig);

        $output->streams()->attach([$stream->id]);
    }

    private function createMuxedStream(
        Video $video,
        FFStream $sourceVideo,
        ?FFStream $sourceAudio,
        array $variantConfig,
        array $audioConfig,
    ) {
        $ulid = Str::ulid();
        $path = "$video->ulid/mp4/$ulid.mp4";

        [$width, $targetHeight] = $this->resolveOutputDimensions(
            $variantConfig,
            $sourceVideo->get('width'),
            $sourceVideo->get('height'),
        );

        return $video->streams()->create([
            'path' => $path,
            'type' => 'muxed',
            'size' => 0,
            'meta' => [
                'source_height' => $sourceVideo->get('height'),
                'source_codec' => $sourceVideo->get('codec_name'),
                'source_bit_rate' => (int) $sourceVideo->get('bit_rate'),
                ...($sourceAudio ? [
                    'source_audio_codec' => $sourceAudio->get('codec_name'),
                    'source_audio_bit_rate' => (int) $sourceAudio->get('bit_rate'),
                    'source_sample_rate' => $sourceAudio->get('sample_rate'),
                    'source_channels' => $sourceAudio->get('channels'),
                ] : []),
            ],
            'name' => 'MP4',
            'input_params' => array_merge($variantConfig, $audioConfig),
            'status' => VideoStatus::PENDING->value,
            'width' => $width,
            'height' => $targetHeight,
        ]);
    }

    private function filterVariants(FFStream $sourceVideo, array $variants): array
    {
        $sourceMax = max($sourceVideo->get('width'), $sourceVideo->get('height'));

        return array_values(array_filter($variants, function (array $variant) use ($sourceMax) {
            $variantMax = max($variant['width'] ?? 0, $variant['height'] ?? 0);

            return $sourceMax >= $variantMax;
        }));
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
        foreach ($streams as $stream) {
            if ($stream->get('codec_type') === 'subtitle') {
                $this->createStream(
                    video: $video,
                    stream: $stream,
                    codecType: 'subtitle',
                    inputParams: null
                );
            }
        }
    }

    private function createStream(
        Video $video,
        FFStream $stream,
        string $codecType,
        ?array $inputParams = null,
    ) {
        $ulid = Str::ulid();
        $extension = $this->getStreamExtension($codecType);
        $path = "$video->ulid/$codecType/$ulid.$extension";

        [$width, $height] = $this->resolveStreamDimensions($stream, $codecType, $inputParams);

        $attributes = [
            'path' => $path,
            'type' => $codecType,
            'size' => 0,
            'meta' => [
                'index' => $stream->get('index'),
                ...($codecType === 'video' ? [
                    'source_height' => $stream->get('height'),
                    'source_codec' => $stream->get('codec_name'),
                    'source_bit_rate' => (int) $stream->get('bit_rate'),
                ] : []),
                ...($codecType === 'audio' ? [
                    'source_codec' => $stream->get('codec_name'),
                    'source_bit_rate' => (int) $stream->get('bit_rate'),
                    'source_sample_rate' => $stream->get('sample_rate'),
                    'source_channels' => $stream->get('channels'),
                ] : []),
            ],
            'name' => ($stream->get('tags') ?? [])['title'] ?? ($stream->get('tags') ?? [])['language'] ?? null,
            'input_params' => $inputParams,
            'status' => VideoStatus::PENDING->value,
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

    private function getStreamExtension(string $codecType): string
    {
        return $codecType === 'subtitle' ? 'vtt' : 'mp4';
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
