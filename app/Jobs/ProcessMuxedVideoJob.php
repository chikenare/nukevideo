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

class ProcessMuxedVideoJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesStreamProcessing;

    private Stream $stream;

    public function __construct(
        public int $streamId,
        public string $outputFormat,
    ) {
    }

    public function handle(): void
    {
        $this->stream = Stream::with('video')->find($this->streamId);

        try {
            $service = new MuxedStreamService($this->stream, $this->outputFormat);
            $service->handle();
        } catch (Exception $e) {
            Log::error('Muxed video processing failed', [
                'stream_id' => $this->stream->id,
                'video_id' => $this->stream->video_id,
                'output_format' => $this->outputFormat,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->markStreamFailed($this->stream, $e->getMessage());

            throw $e;
        } finally {
            $this->updateVideoStatus($this->stream);
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('Muxed video job permanently failed', [
            'stream_id' => $this->stream->id,
            'video_id' => $this->stream->video_id,
            'error' => $exception->getMessage(),
        ]);

        $this->markStreamFailed($this->stream, 'Job failed after all attempts: ' . $exception->getMessage());
        $this->updateVideoStatus($this->stream);

        $this->delete();
    }
}
