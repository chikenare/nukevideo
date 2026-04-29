<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\OnVideoUploadedService;
use App\Services\UppyS3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OnVideoUploaded implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $key,
        private int $size,
    ) {}

    public function handle(OnVideoUploadedService $service): void
    {
        Log::info('OnVideoUploaded started', ['key' => $this->key, 'size' => $this->size]);
        $service->handle($this->key, $this->size);
        Log::info('OnVideoUploaded completed', ['key' => $this->key]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $meta = app(UppyS3Service::class)->getUploadMeta($this->key);
        $userUlid = $meta?->user;
        $filename = $meta?->filename ?? $this->key;

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
        if (! Storage::delete($this->key)) {
            Log::error('Failed to delete file in job failure handler', [
                'file_path' => $this->key,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
