<?php

namespace App\Services;

use App\Enums\OutputFormat;
use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Jobs\DownloadOriginalFileJob;
use App\Jobs\ExtractThumbnailJob;
use App\Jobs\GenerateVideoStoryboard;
use App\Jobs\ProcessStreamJob;
use App\Jobs\ProcessSubtitlesJob;
use App\Jobs\UploadStreamJob;
use App\Models\Node;
use App\Models\User;
use App\Models\Video;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class OnVideoUploadedService
{
    private array $videoStreamCache = [];

    private array $audioStreamCache = [];
    /**
     * @param array{
     * key: string,
     * size: int,
     * eTag: string,
     * contentType: string,
     * userMetadata: array{
     * "X-Amz-Meta-User": string,
     * "X-Amz-Meta-Template": string,
     * "X-Amz-Meta-Filename": string,
     * "content-disposition": string,
     * "content-type": string
     * },
     * sequencer: string
     * } $object
     */
    public function handle(array $object): void
    {
        $node = Node::leastBusy();

        if (!$node) {
            throw new Exception('No active worker nodes available');
        }

        $key = urldecode($object['key']);

        $this->validateContentType($object['contentType']);

        [$user, $template] = $this->resolveUserAndTemplate($object['userMetadata']);

        $mediaInfo = $this->probeMedia($key);

        $outputs = $template->query['outputs'] ?? [];

        $filename = $object['userMetadata']['X-Amz-Meta-Filename'] ?? $object['userMetadata']['filename'];

        if (empty($outputs)) {
            throw new Exception('No outputs configured for template');
        }

        $video = DB::transaction(function () use ($user, $template, $filename, $mediaInfo, $key, $object, $outputs) {
            $video = Video::create([
                'user_id' => $user->id,
                'template_id' => $template->id,
                'name' => $filename,
                'duration' => $mediaInfo['duration'],
                'aspect_ratio' => $mediaInfo['aspectRatio'],
            ]);

            $video->refresh();

            $video->streams()->create([
                'path' => $key,
                'name' => 'Original',
                'type' => 'original',
                'size' => $object['size'],
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
            ->log("Video processing started: {$video->name}");

        $this->dispatchJobs($video, $key, $node);
    }

    private function resolveUserAndTemplate(array $metadata): array
    {
        $userUlid = $metadata['X-Amz-Meta-User'] ?? $metadata['user'];
        $user = User::where('ulid', $userUlid)->first();

        if (!$user) {
            throw new Exception("User with ulid $userUlid not found");
        }

        $templateUlid = $metadata['X-Amz-Meta-Template'] ?? $metadata['template'];
        $template = $user->templates()->where('ulid', $templateUlid)->first();

        if (!$template) {
            throw new Exception("Template with ulid $templateUlid not found");
        }

        return [$user, $template];
    }

    private function probeMedia(string $key): array
    {
        $url = Storage::temporaryUrl($key, now()->addDay());
        $ffprobe = FFProbe::create();

        $streamCollection = $ffprobe->streams($url);
        $duration = $ffprobe->format($url)->get('duration');

        if (!$duration) {
            throw new Exception("The duration could not be obtained. {$key}");
        }

        $videoStream = $streamCollection->videos()->first();

        if (!$videoStream) {
            throw new Exception('Video stream not found');
        }

        return [
            'streamCollection' => $streamCollection,
            'duration' => $duration,
            'aspectRatio' => $videoStream->get('display_aspect_ratio'),
        ];
    }

    private function prepareStreamJobs(Video $video, string $type): array
    {
        $jobs = [];

        foreach ($video->streams()->where('type', $type)->get() as $stream) {
            $jobs[] = new ProcessStreamJob($stream->id);
            $jobs[] = new UploadStreamJob($stream->id);
        }

        return $jobs;
    }

    private function dispatchJobs(Video $video, string $originalPath, Node $node): void
    {
        $videoQueue = $node->resolveQueue($video->getSourceHeight());
        $audioQueue = $node->resolveQueue();
        $lightQueue = $node->resolveQueue();

        $videoTasks = [...$this->prepareStreamJobs($video, 'video'), ...$this->prepareStreamJobs($video, 'muxed')];
        $audioTasks = $this->prepareStreamJobs($video, 'audio');
        $hasStreaming = $video->outputs()->whereIn('format', [OutputFormat::HLS, OutputFormat::DASH])->exists();

        $videoId = $video->id;
        $videoUlid = $video->ulid;

        $onFail = function () use ($videoId, $videoUlid, $lightQueue) {
            Video::findOrFail($videoId)->markAsFailed();
            CleanupVideoResourcesJob::dispatch($videoUlid)->onQueue($lightQueue);
        };

        $lightJobs = array_filter([
            $hasStreaming ? new ProcessSubtitlesJob($videoId, $originalPath) : null,
            new ExtractThumbnailJob($videoId, $originalPath),
            new GenerateVideoStoryboard($videoId, $originalPath),
            new CleanupVideoResourcesJob($videoUlid),
        ]);

        Bus::chain([
            new DownloadOriginalFileJob($videoId, $originalPath),

            Bus::batch([$videoTasks])
                ->name("Video Phase: {$videoId}")
                ->onQueue($videoQueue)
                ->then(function () use ($audioTasks, $audioQueue, $videoId, $lightJobs, $lightQueue, $onFail) {
                    Bus::batch([$audioTasks])
                        ->name("Audio Phase: {$videoId}")
                        ->onQueue($audioQueue)
                        ->then(function () use ($lightJobs, $lightQueue, $onFail) {
                            Bus::chain($lightJobs)
                                ->onQueue($lightQueue)
                                ->catch($onFail)
                                ->dispatch();
                        })
                        ->catch($onFail)
                        ->dispatch();
                })
                ->catch($onFail),
        ])
            ->onQueue($videoQueue)
            ->catch($onFail)
            ->dispatch();
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

        $streamIds = [];

        foreach ($outputConfig['variants'] ?? [] as $variantConfig) {
            if ($sourceVideo->get('height') < $variantConfig['resolution']) {
                continue;
            }

            $streamIds[] = $this->getOrCreateVideoStream($video, $sourceVideo, $variantConfig);
        }

        $audioConfig = $outputConfig['audio'] ?? [];
        $audioIds = $this->getOrCreateAudioStreams($video, $streamCollection, $audioConfig);
        $streamIds = array_merge($streamIds, $audioIds);

        $output->streams()->attach($streamIds);
    }

    private function createMp4Output(Video $video, StreamCollection $streamCollection, array $outputConfig): void
    {
        $output = $video->outputs()->create(['format' => OutputFormat::MP4]);

        $variantConfig = $outputConfig['variants'][0] ?? null;

        if (!$variantConfig) {
            return;
        }

        $audioConfig = collect($outputConfig['audio'] ?? [])->except('channels')->toArray();

        $sourceVideo = $streamCollection->videos()->first();

        if (!$sourceVideo) {
            throw new Exception('Video stream not found in source media');
        }

        $stream = $this->createMuxedStream($video, $sourceVideo, $variantConfig, $audioConfig);

        $output->streams()->attach([$stream->id]);
    }

    private function createMuxedStream(
        Video $video,
        FFStream $sourceVideo,
        array $variantConfig,
        array $audioConfig,
    ) {
        $ulid = Str::ulid();
        $path = "$video->ulid/mp4/$ulid.mp4";

        $targetHeight = (int) $variantConfig['resolution'];
        $width = $this->calculateScaledWidth($sourceVideo->get('width'), $sourceVideo->get('height'), $targetHeight);

        return $video->streams()->create([
            'path' => $path,
            'type' => 'muxed',
            'size' => 0,
            'meta' => [
                'source_height' => $sourceVideo->get('height'),
            ],
            'name' => 'MP4',
            'input_params' => array_merge($variantConfig, $audioConfig),
            'status' => VideoStatus::PENDING->value,
            'width' => $width,
            'height' => $targetHeight,
        ]);
    }

    private function getOrCreateVideoStream(Video $video, FFStream $sourceVideo, array $variantConfig): int
    {
        $key = $this->streamSignature($variantConfig);

        if (!isset($this->videoStreamCache[$key])) {
            $stream = $this->createStream(
                video: $video,
                stream: $sourceVideo,
                codecType: 'video',
                inputParams: $variantConfig,
            );
            $this->videoStreamCache[$key] = $stream->id;
        }

        return $this->videoStreamCache[$key];
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

            if (!$channelConfig) {
                continue;
            }

            $inputParams = array_merge($sharedAudioParams, $channelConfig);
            $key = $stream->get('index') . ':' . $this->streamSignature($inputParams);

            if (!isset($this->audioStreamCache[$key])) {
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
        $extension = $this->getStreamExtension($codecType, $inputParams);
        $path = "$video->ulid/$codecType/$ulid.$extension";

        [$width, $height] = $this->resolveStreamDimensions($stream, $codecType, $inputParams);

        return $video->streams()->create([
            'path' => $path,
            'type' => $codecType,
            'size' => 0,
            'meta' => [
                'index' => $stream->get('index'),
                ...($codecType === 'video' ? ['source_height' => $stream->get('height')] : []),
            ],
            'name' => $stream->get('tags')['title'] ?? $stream->get('tags')['language'] ?? null,
            'input_params' => $inputParams,
            'status' => VideoStatus::PENDING->value,
            'width' => $width,
            'height' => $height,
            'language' => $stream->get('tags')['language'] ?? null,
            'channels' => $stream->get('channels'),
        ]);
    }

    private function calculateScaledWidth(int $originalWidth, int $originalHeight, int $targetHeight): int
    {
        return (int) (round($originalWidth * ($targetHeight / $originalHeight) / 2) * 2);
    }

    private function resolveStreamDimensions(FFStream $stream, string $codecType, ?array $inputParams): array
    {
        if ($codecType !== 'video' || !isset($inputParams['resolution'])) {
            return [$stream->get('width'), $stream->get('height')];
        }

        $targetHeight = (int) $inputParams['resolution'];
        $width = $this->calculateScaledWidth($stream->get('width'), $stream->get('height'), $targetHeight);

        return [$width, $targetHeight];
    }

    private function getStreamExtension(string $codecType, ?array $inputParams = null): string
    {
        if ($codecType === 'subtitle') {
            return 'vtt';
        }

        $codec = $inputParams['video_codec'] ?? $inputParams['audio_codec'] ?? null;

        if ($codec) {
            $codecConfig = collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec);

            if ($codecConfig && isset($codecConfig['container'])) {
                return $codecConfig['container'];
            }
        }

        return 'mp4';
    }

    private function validateContentType(string $contentType): void
    {
        if (!in_array($contentType, ['video/mp4', 'video/x-matroska', 'video/matroska'])) {
            throw new Exception("Invalid content type: $contentType");
        }
    }
}
