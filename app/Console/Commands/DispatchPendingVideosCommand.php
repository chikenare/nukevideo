<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Node;
use App\Models\Video;
use App\Services\CodecService;
use App\Services\VideoDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchPendingVideosCommand extends Command
{
    protected $signature = 'videos:dispatch';

    protected $description = 'Dispatch pending videos to available worker nodes';

    public function handle(): void
    {
        $nodes = Node::active()->worker()->get()
            ->filter(fn (Node $node) => $node->availableWorkers() > 0);

        if ($nodes->isEmpty()) {
            return;
        }

        $totalWorkers = $nodes->sum(fn (Node $node) => $node->availableWorkers());

        $videos = Video::where('status', VideoStatus::PENDING->value)
            ->whereNull('node_id')
            ->oldest()
            ->limit($totalWorkers)
            ->get();

        if ($videos->isEmpty()) {
            return;
        }

        $hasPendingGpuVideos = $videos->contains(
            fn (Video $video) => CodecService::outputsRequireGpu($video->template->query['outputs'] ?? [])
        );

        foreach ($videos as $video) {
            $originalPath = $video->streams()->where('type', 'original')->value('path');

            if (! $originalPath) {
                $video->markAsFailed();

                continue;
            }

            $requiresGpu = CodecService::outputsRequireGpu(
                $video->template->query['outputs'] ?? []
            );

            $allowGpuFallback = ! $requiresGpu && ! $hasPendingGpuVideos;

            $node = Node::findAvailableNode($requiresGpu, $allowGpuFallback);

            if (! $node) {
                continue;
            }

            try {
                VideoDispatchService::dispatch($video, $originalPath, $node);
            } catch (\Throwable $e) {
                Log::error("Failed to dispatch video {$video->id}: {$e->getMessage()}");
            }
        }
    }
}
