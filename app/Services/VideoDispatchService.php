<?php

namespace App\Services;

use App\Enums\OutputFormat;
use App\Jobs\CleanupVideoResourcesJob;
use App\Jobs\DownloadOriginalFileJob;
use App\Jobs\ExtractThumbnailJob;
use App\Jobs\GenerateVideoStoryboard;
use App\Jobs\ProcessStreamJob;
use App\Jobs\ProcessSubtitlesJob;
use App\Jobs\UploadStreamsJob;
use App\Models\Node;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;

class VideoDispatchService
{
    public static function buildStreamJobs(int $videoId, array $types): array
    {
        return Video::findOrFail($videoId)->streams()
            ->whereIn('type', $types)
            ->pluck('id')
            ->map(fn ($id) => new ProcessStreamJob($id))
            ->toArray();
    }

    public static function dispatch(Video $video, string $originalPath, Node $node): void
    {
        $queue = $node->resolveQueue();
        $hasStreaming = $video->outputs()->whereIn('format', [OutputFormat::HLS, OutputFormat::DASH])->exists();

        $videoId = $video->id;
        $videoUlid = $video->ulid;
        $nodeId = $node->id;

        $onFail = function () use ($videoId, $videoUlid, $nodeId, $queue) {
            $video = Video::findOrFail($videoId);
            $video->markAsFailed();
            Node::find($nodeId)?->releaseSlot($videoId);
            CleanupVideoResourcesJob::dispatch($videoUlid)->onQueue($queue);
        };

        $videoJobs = self::buildStreamJobs($videoId, ['video', 'muxed']);
        $audioJobs = self::buildStreamJobs($videoId, ['audio']);

        $chain = [
            new DownloadOriginalFileJob($videoId, $originalPath),
        ];

        if (! empty($videoJobs)) {
            $chain[] = Bus::batch($videoJobs)->onQueue($queue);
        }

        if (! empty($audioJobs)) {
            $chain[] = Bus::batch($audioJobs)->onQueue($queue);
        }

        if ($hasStreaming) {
            $chain[] = new ProcessSubtitlesJob($videoId, $originalPath);
        }

        $chain[] = new ExtractThumbnailJob($videoId, $originalPath);
        $chain[] = new GenerateVideoStoryboard($videoId, $originalPath);
        $chain[] = new UploadStreamsJob($videoId);
        $chain[] = new CleanupVideoResourcesJob($videoUlid);

        $node->reserveSlot($videoId);
        $video->update(['node_id' => $nodeId]);

        Bus::chain($chain)
            ->onQueue($queue)
            ->catch($onFail)
            ->dispatch();
    }
}
