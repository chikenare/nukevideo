<?php

namespace App\Services;

use App\Enums\OutputFormat;
use App\Exceptions\EncodeInterruptedException;
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
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoDispatchService
{
    public static function buildStreamJobs(int $videoId, array $types, ?int $attempt = null): array
    {
        return Video::findOrFail($videoId)->streams()
            ->whereIn('type', $types)
            ->pluck('id')
            ->map(fn ($id) => new ProcessStreamJob($id, $attempt))
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
        // dispatch_attempts when it re-queues a stuck video, so every job of a
        // superseded chain (not just the failure handler) can tell it no longer
        // owns the video and must not touch it or wipe the new run's resources.
        $attempt = $video->dispatch_attempts;

        $onFail = function (?Throwable $e = null) use ($videoId, $videoUlid, $nodeId, $queue, $attempt) {
            $video = Video::find($videoId);

            if (! $video || $video->dispatch_attempts !== $attempt) {
                return; // a newer run owns this video; leave it alone
            }

            // A worker shutdown (deploy/restart) killed ffmpeg mid-encode. The
            // video isn't broken — heartbeats stop with the dead worker and the
            // reaper requeues it within minutes, so don't fail it permanently
            // or delete the resources the retry will need.
            if ($e instanceof EncodeInterruptedException) {
                Log::warning('Chain interrupted by shutdown; leaving recovery to the reaper', ['video' => $videoId]);

                return;
            }

            $video->markAsFailed();
            Node::find($nodeId)?->releaseSlot($videoId);
            CleanupVideoResourcesJob::dispatch($videoUlid, $attempt)->onQueue($queue);
        };

        $videoJobs = self::buildStreamJobs($videoId, ['video', 'muxed'], $attempt);
        $audioJobs = self::buildStreamJobs($videoId, ['audio'], $attempt);

        $chain = [
            new DownloadOriginalFileJob($videoId, $originalPath, $attempt),
        ];

        if (! empty($videoJobs)) {
            $chain[] = Bus::batch($videoJobs)->onQueue($queue);
        }

        if (! empty($audioJobs)) {
            $chain[] = Bus::batch($audioJobs)->onQueue($queue);
        }

        if ($hasStreaming) {
            $chain[] = new ProcessSubtitlesJob($videoId, $originalPath, $attempt);
        }

        $chain[] = new ExtractThumbnailJob($videoId, $originalPath, $attempt);
        $chain[] = new GenerateVideoStoryboard($videoId, $originalPath, $attempt);
        $chain[] = new UploadStreamsJob($videoId, $attempt);
        $chain[] = new CleanupVideoResourcesJob($videoUlid, $attempt);

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
