<?php

namespace App\Jobs;
use App\Services\OnVideoUploadedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OnVideoUploaded implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private array $object,
    ) {
    }

    public function handle(OnVideoUploadedService $service): void
    {
        $service->handle($this->object);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $key = urldecode($this->object['key'] ?? 'unknown');

        // Attempt final cleanup of uploaded file
        if (!Storage::delete($key)) {
            Log::error('Failed to delete file in job failure handler', [
                'file_path' => $key,
                'error' => $exception->getMessage()
            ]);

        }
    }
}
