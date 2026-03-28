<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\OnVideoUploadedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OnVideoUploaded implements ShouldQueue
{
    use Queueable;

    public array $backoff = [30, 60, 120, 300, 600];

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    public function __construct(
        private array $object,
    ) {}

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
        $metadata = $this->object['userMetadata'] ?? [];
        $userUlid = $metadata['X-Amz-Meta-User'] ?? $metadata['user'] ?? null;
        $filename = $metadata['X-Amz-Meta-Filename'] ?? $metadata['filename'] ?? $key;

        if ($userUlid) {
            $user = User::where('ulid', $userUlid)->first();

            if ($user) {
                activity('video')
                    ->causedBy($user)
                    ->withProperties(['error' => $exception->getMessage(), 'file' => $filename])
                    ->event('video_upload_processing_failed')
                    ->log("Video upload processing failed for \"{$filename}\": {$exception->getMessage()}");
            }
        }

        // Attempt final cleanup of uploaded file
        if (! Storage::delete($key)) {
            Log::error('Failed to delete file in job failure handler', [
                'file_path' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
