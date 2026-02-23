<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Jobs\DownloadOriginalFileJob;
use App\Jobs\ExtractThumbnailJob;
use App\Jobs\GenerateVideoStoryboard;
use App\Jobs\ProcessMuxedVideoJob;
use App\Jobs\ProcessStreamJob;
use App\Jobs\ProcessSubtitlesJob;
use App\Models\User;
use App\Models\Video;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OnVideoUploadedService
{
    public function __construct(
        private NodeService $nodeService
    ) {}

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
        $key = urldecode($object['key']);

        $this->validateContentType($object['contentType']);

        [$user, $template] = $this->resolveUserAndTemplate($object['userMetadata']);

        $mediaInfo = $this->probeMedia($key);

        $variants = $template->query['variants'] ?? [];
        $audioConfig = $template->query['audio'] ?? [];
        $outputFormat = $template->query['output_format'] ?? 'hls';
        $isMuxed = in_array($outputFormat, ['mp4', 'mkv']);

        $filename = $object['userMetadata']['X-Amz-Meta-Filename'] ?? $object['userMetadata']['filename'];

        DB::beginTransaction();

        try {
            $node = $this->nodeService->selectNode();

            if (!$node) {
                throw new Exception("Nodes not available");
            }

            $video = Video::create([
                'user_id' => $user->id,
                'template_id' => $template->id,
                'node_id' => $node->id,
                'name' => $filename,
                'duration' => $mediaInfo['duration'],
                'aspect_ratio' => $mediaInfo['aspectRatio'],
                'output_format' => $outputFormat,
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

            if ($isMuxed) {
                $this->createMuxedStream($video, $mediaInfo['streamCollection'], $variants, $outputFormat);
            } else {
                $this->createProcessingStreams($video, $mediaInfo['streamCollection'], $variants, $audioConfig);
            }

            DB::commit();

            if ($isMuxed) {
                $this->dispatchMuxedJobs($video, $outputFormat);
            } else {
                $this->dispatchHlsJobs($video);
            }
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveUserAndTemplate(array $metadata): array
    {
        $userUuid = $metadata['X-Amz-Meta-User'] ?? $metadata['user'];
        $user = User::where('uuid', $userUuid)->first();

        if (!$user) {
            throw new Exception("User with uuid $userUuid not found");
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

    private function dispatchHlsJobs(Video $video): void
    {
        $jobs = $video->streams()
            ->whereIn('type', ['video', 'audio'])
            ->get()
            ->map(fn($stream) => new ProcessStreamJob($stream->id));

        $parallelGroup = $jobs->all();
        $parallelGroup[] = new ProcessSubtitlesJob($video->id);

        Bus::chain([
            new DownloadOriginalFileJob($video->id),
            new ExtractThumbnailJob($video->id),

            Bus::batch($parallelGroup)
                ->name("Processing video: {$video->id}")
                ->onQueue('streams'),

            new GenerateVideoStoryboard($video->id),

            new CleanupVideoResourcesJob($video->ulid)
        ])
            ->onQueue('streams')
            ->catch(function (Throwable $e) use ($video) {
                CleanupVideoResourcesJob::dispatch($video->ulid)->onQueue('streams');
            })
            ->dispatch();
    }

    private function createMuxedStream(Video $video, StreamCollection $streamCollection, array $variants, string $outputFormat): void
    {
        $formatConfig = $variants[0] ?? null;

        if (!$formatConfig) {
            throw new Exception('No variant configured for muxed output');
        }

        $videoStream = $streamCollection->videos()->first();

        if ($videoStream->get('height') < $formatConfig['resolution']) {
            $formatConfig['resolution'] = $videoStream->get('height');
        }

        $ulid = Str::ulid();

        $video->streams()->create([
            'path' => "$video->ulid/download/$ulid.$outputFormat",
            'type' => 'download',
            'size' => 0,
            'meta' => [],
            'name' => $outputFormat,
            'input_params' => $formatConfig,
            'status' => VideoStatus::PENDING->value,
            'width' => $videoStream->get('width'),
            'height' => $videoStream->get('height'),
        ]);
    }

    private function dispatchMuxedJobs(Video $video, string $outputFormat): void
    {
        $downloadStream = $video->streams()->where('type', 'download')->first();

        Bus::chain([
            new DownloadOriginalFileJob($video->id),
            new ExtractThumbnailJob($video->id),

            new ProcessMuxedVideoJob($downloadStream->id, $outputFormat),

            new CleanupVideoResourcesJob($video->ulid)
        ])
            ->onQueue('streams')
            ->catch(function (Throwable $e) use ($video) {
                CleanupVideoResourcesJob::dispatch($video->ulid)->onQueue('streams');
            })
            ->dispatch();
    }

    private function createProcessingStreams(Video $video, StreamCollection $streamCollection, array $variants, array $audioConfig): void
    {
        foreach ($variants as $formatConfig) {
            if ($streamCollection->videos()->first()->get('height') < $formatConfig['resolution']) {
                continue;
            }

            $this->createStreamNode(
                video: $video,
                stream: $streamCollection->videos()->first(),
                codecType: 'video',
                inputParams: $formatConfig
            );
        }

        $channelConfigsList = $audioConfig['channels'] ?? [];
        $channelConfigs = collect($channelConfigsList)->keyBy('channels');
        $singleConfig = count($channelConfigsList) === 1 ? $channelConfigsList[0] : null;
        $sharedAudioParams = collect($audioConfig)->except('channels')->toArray();

        foreach ($streamCollection->audios() as $stream) {
            $sourceChannels = (string) $stream->get('channels');
            $channelConfig = $singleConfig ?? $channelConfigs->get($sourceChannels);

            if (!$channelConfig) {
                continue;
            }

            $inputParams = array_merge($sharedAudioParams, $channelConfig);

            $this->createStreamNode(
                video: $video,
                stream: $stream,
                codecType: $stream->get('codec_type'),
                inputParams: $inputParams,
            );
        }

        $this->createSubtitleStreams($video, $streamCollection->all());
    }

    private function createSubtitleStreams(Video $video, array $streams): void
    {
        foreach ($streams as $stream) {
            if ($stream->get('codec_type') === 'subtitle') {
                $this->createStreamNode(
                    video: $video,
                    stream: $stream,
                    codecType: 'subtitle',
                    inputParams: null
                );
            }
        }
    }

    private function createStreamNode(
        Video $video,
        Stream $stream,
        string $codecType,
        ?array $inputParams = null,
    ) {
        $ulid = Str::ulid();
        $extension = $this->getStreamExtension($codecType);
        $name = $this->getStreamName($stream);

        $path = "$video->ulid/$codecType/$ulid.$extension";

        return $video->streams()->create([
            'path' => $path,
            'type' => $codecType,
            'size' => 0,
            'meta' => [
                'index' => $stream->get('index')
            ],
            'name' => $name,
            'input_params' => $inputParams,
            'status' => VideoStatus::PENDING->value,
            'width' => $stream->get('width'),
            'height' => $stream->get('height'),
            'language' => $stream->get('tags')['language'] ?? null,
            'channels' => $stream->get('channels'),
        ]);
    }

    private function getStreamName(Stream $stream): ?string
    {
        $title = $stream->get('tags')['title'] ?? null;
        $language = $stream->get('tags')['language'] ?? null;

        return empty($title) ? $language : $title;
    }

    private function getStreamExtension(string $codecType): string
    {
        return match ($codecType) {
            'subtitle' => 'vtt',
            default => 'mp4',
        };
    }

    private function validateContentType(string $contentType): void
    {
        if (!in_array($contentType, ['video/mp4', 'video/x-matroska', 'video/matroska'])) {
            throw new Exception("Invalid content type: $contentType");
        }
    }
}
