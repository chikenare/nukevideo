<?php

namespace App\Jobs\Concerns;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Services\NodeService;
use Exception;

trait HandlesStreamProcessing
{
    protected function validateNodeAssignment(Stream $stream): void
    {
        if (!$stream->video->node_id) {
            throw new Exception("Video {$stream->video_id} has no node assigned");
        }
    }

    protected function updateNodeHealth(NodeService $nodeService, Stream $stream): void
    {
        $node = $stream->video->node;

        if ($node) {
            $nodeService->updateNodeHealth($node);
        }
    }

    protected function markStreamFailed(Stream $stream, string $message): void
    {
        $stream->update([
            'status' => VideoStatus::FAILED->value,
            'error_log' => $message,
        ]);
    }

    protected function updateVideoStatus(Stream $stream): void
    {
        $video = $stream->video()->first();

        if (!$video) {
            return;
        }

        $allStreams = $video->streams()->get();

        $hasFailedStreams = $allStreams->contains('status', VideoStatus::FAILED->value);
        $allCompleted = $allStreams->every(fn($s) => $s->status === VideoStatus::COMPLETED->value);

        if ($hasFailedStreams) {
            $video->update(['status' => VideoStatus::FAILED->value]);
        } elseif ($allCompleted) {
            $video->update(['status' => VideoStatus::COMPLETED->value]);
        }
    }
}
