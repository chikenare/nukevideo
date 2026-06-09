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

        // Discriminates this run from a later one. The reaper increments
        // dispatch_attempts when it re-queues a stuck video, so a superseded
        // chain's failure handler can tell it no longer owns the video and must
        // not fail it or wipe the new run's resources.
        $attempt = $video->dispatch_attempts;

        $onFail = function () use ($videoId, $videoUlid, $nodeId, $queue, $attempt) {
            $video = Video::find($videoId);

            if (! $video || $video->dispatch_attempts !== $attempt) {
                return; // a newer run owns this video; leave it alone
            }

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
        // Stamp a fresh heartbeat so the reaper doesn't reclaim the video before
        // its first stage gets a chance to run.
        $video->update(['node_id' => $nodeId, 'last_heartbeat_at' => now()]);

        Bus::chain($chain)
            ->onQueue($queue)
            ->catch($onFail)
            ->dispatch();
    }
}
