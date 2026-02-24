<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesStreamProcessing;
use App\Models\Stream;
use App\Services\StreamService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesStreamProcessing;

    public $tries = 1;

    private Stream $stream;

    public function __construct(
        public int $streamId
    ) {
    }

    public function handle(): void
    {
        $this->stream = Stream::with('video')->find($this->streamId);

        $service = new StreamService($this->stream);
        $service->handle();
        $this->updateVideoStatus($this->stream);
    }
    public function failed(Throwable $e): void
    {
        Log::error('Stream job failed', [
            'stream_id' => $this->stream->id,
            'stream_type' => $this->stream->type,
            'video_id' => $this->stream->video_id,
            'error' => $e->getMessage(),
        ]);

        $this->markStreamFailed($this->stream, $e->getMessage());
    }
}
