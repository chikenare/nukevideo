<?php

namespace App\Jobs\Concerns;

use App\Enums\VideoStatus;
use App\Models\Stream;

trait HandlesStreamProcessing
{

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
