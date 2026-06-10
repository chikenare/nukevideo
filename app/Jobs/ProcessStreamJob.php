<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Exceptions\EncodeInterruptedException;
use App\Jobs\Concerns\FencedByRun;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Mp4Service;
use App\Services\StreamService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, FencedByRun, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    // Must stay below the worker --timeout (21600) and the queue's retry_after
    // (21900): if the job outlived retry_after the queue would redeliver it
    // mid-encode and the duplicate would die with MaxAttemptsExceeded, clobbering
    // the stream's state while the real encode is still running.
    public $timeout = 21000;

    public function __construct(
        public int $streamId,
        ?int $runAttempt = null,
    ) {
        $this->runAttempt = $runAttempt;
    }

    public function middleware(): array
    {
        // A failed sibling cancels the batch; without this, already-queued
        // renditions of a doomed video still encode at full CPU cost.
        return [new SkipIfBatchCancelled];
    }

    public function handle(): void
    {
        $stream = Stream::with('video')->find($this->streamId);

        if (! $stream || $this->supersededRun($stream->video)) {
            Log::info('ProcessStream skipped: stream gone or run superseded', ['stream' => $this->streamId]);

            return;
        }

        Log::info('ProcessStream started', ['stream' => $this->streamId, 'type' => $stream->type, 'video' => $stream->video->id]);

        $service = $stream->type === 'muxed'
            ? new Mp4Service($stream)
            : new StreamService($stream);

        $service->handle();

        Log::info('ProcessStream completed', ['stream' => $this->streamId]);
    }

    public function failed(Throwable $e): void
    {
        // ffmpeg was killed by a worker shutdown (deploy/restart), not by the
        // media: leave the stream untouched so the reaper requeues the video on
        // a healthy worker instead of this counting as a permanent failure.
        if ($e instanceof EncodeInterruptedException) {
            Log::warning('ProcessStream interrupted by shutdown; leaving recovery to the reaper', [
                'stream' => $this->streamId,
            ]);

            return;
        }

        $stream = Stream::with('video.user')->find($this->streamId);

        if (! $stream) {
            return;
        }

        // A superseded run's failure (or a queue redelivery dying with
        // MaxAttemptsExceeded long after the video reached a terminal state)
        // must not overwrite the current run's stream state.
        if ($this->supersededRun($stream->video)
            || ! in_array($stream->video?->status, Video::ACTIVE_STATUSES, true)) {
            Log::info('ProcessStream failure ignored: run superseded or video terminal', [
                'stream' => $this->streamId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $stream->update([
            'status' => VideoStatus::FAILED->value,
            'error_log' => $e->getMessage(),
        ]);

        activity('video')
            ->performedOn($stream->video)
            ->causedBy($stream->video->user)
            ->withProperties(['stream_id' => $stream->id, 'stream_type' => $stream->type, 'error' => $e->getMessage()])
            ->event('stream_processing_failed')
            ->log("Stream processing failed: {$e->getMessage()}");
    }
}
