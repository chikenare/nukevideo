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

    // Object-created events are at-least-once; ingestion is idempotent (unique streams.path),
    // so retries cover transient blips without ever creating a duplicate video.
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    // Probing a large non-faststart source over HTTP can take minutes (the moov atom lives at
    // the end); an unset timeout would inherit the supervisor's and kill legitimate probes.
    public int $timeout = 900;

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

        // Do NOT delete the uploaded source: the failure may be recoverable and this is the
        // user's only copy. Orphaned sources are reclaimed by age-based GC.
        Log::error('Video upload processing failed permanently; source retained for recovery', [
            'key' => $this->key,
            'error' => $exception->getMessage(),
        ]);
    }
}
