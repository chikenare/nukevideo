<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\OnVideoUploadedService;
use App\Services\UppyS3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnVideoUploaded implements ShouldQueue
{
    use Queueable;

    // Storage object-created events are at-least-once; retries cover transient
    // probe/storage/DB blips. Ingestion is idempotent (unique streams.path), so
    // retries can never create a duplicate video.
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

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

        // Deliberately do NOT delete the uploaded source here. Ingestion can fail
        // for recoverable reasons (probe timeout, transient storage/DB error),
        // and deleting the user's only copy turns a recoverable failure into
        // permanent data loss. Orphaned sources are reclaimed by age-based GC.
        Log::error('Video upload processing failed permanently; source retained for recovery', [
            'key' => $this->key,
            'error' => $exception->getMessage(),
        ]);
    }
}
