<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Services\StreamService;
use App\Jobs\UploadStreamJob;
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
    ) {
    }

    public function handle(): void
    {
        $stream = Stream::with('video')->findOrFail($this->streamId);

        $service = new StreamService($stream);
        $service->handle();

        $this->appendToChain(new UploadStreamJob($this->streamId));
    }

    public function failed(Throwable $e): void
    {
        Stream::where('id', $this->streamId)->update([
            'status' => VideoStatus::FAILED->value,
            'error_log' => $e->getMessage(),
        ]);
    }
}
