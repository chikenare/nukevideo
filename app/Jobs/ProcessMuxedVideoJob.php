<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesStreamProcessing;
use App\Models\Stream;
use App\Services\MuxedStreamService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMuxedVideoJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesStreamProcessing;

    public $tries = 1;

    private Stream $stream;

    public function __construct(
        public int $streamId,
        public string $outputFormat,
    ) {
    }

    public function handle(): void
    {
        $this->stream = Stream::with('video')->find($this->streamId);

        $service = new MuxedStreamService($this->stream, $this->outputFormat);
        $service->handle();
        $this->updateVideoStatus($this->stream);
    }
    public function failed(Throwable $e): void
    {
        Log::error('Muxed video processing failed', [
            'stream_id' => $this->stream->id,
            'video_id' => $this->stream->video_id,
            'output_format' => $this->outputFormat,
            'error' => $e->getMessage(),
        ]);

        $this->markStreamFailed($this->stream, $e->getMessage());
    }
}
