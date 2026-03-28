<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Services\Mp4Service;
use App\Services\StreamService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public int $streamId,
    ) {}

    public function handle(): void
    {
        $stream = Stream::with('video')->findOrFail($this->streamId);

        $service = $stream->type === 'muxed'
            ? new Mp4Service($stream)
            : new StreamService($stream);

        $service->handle();
    }

    public function failed(Throwable $e): void
    {
        $stream = Stream::with('video.user')->find($this->streamId);

        if ($stream) {
            $stream->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => $e->getMessage(),
            ]);

            if ($stream->video) {
                activity('video')
                    ->performedOn($stream->video)
                    ->causedBy($stream->video->user)
                    ->withProperties(['stream_id' => $stream->id, 'stream_type' => $stream->type, 'error' => $e->getMessage()])
                    ->event('stream_processing_failed')
                    ->log("Stream processing failed: {$e->getMessage()}");
            }
        }
    }
}
