<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Jobs\Concerns\HandlesStreamProcessing;
use App\Models\Stream;
use App\Services\NodeService;
use App\Services\StreamService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesStreamProcessing;

    private Stream $stream;

    public function __construct(
        public int $streamId
    ) {
    }

    public function handle(NodeService $nodeService): void
    {
        $this->stream = Stream::with('video')->find($this->streamId);

        try {
            $this->validateNodeAssignment($this->stream);
            $this->updateNodeHealth($nodeService, $this->stream);

            $service = new StreamService($this->stream);
            $service->handle();
        } catch (Exception $e) {
            Log::error('Stream processing failed', [
                'stream_id' => $this->stream->id,
                'stream_type' => $this->stream->type,
                'video_id' => $this->stream->video_id,
                'node_id' => $this->stream->video->node_id,
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
        Log::error('Stream job permanently failed after all retries', [
            'stream_id' => $this->stream->id,
            'stream_type' => $this->stream->type,
            'video_id' => $this->stream->video_id,
            'error' => $exception->getMessage(),
        ]);

        $this->markStreamFailed($this->stream, 'Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage());
        $this->updateVideoStatus($this->stream);

        $this->delete();
    }
}
