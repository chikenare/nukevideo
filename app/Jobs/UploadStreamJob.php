<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public function __construct(
        public int $streamId,
    ) {}

    public function handle(): void
    {
        $stream = Stream::with('video')->findOrFail($this->streamId);

        $localPath = Storage::disk('tmp')->path($stream->path);

        $this->upload($stream, $localPath);

        $stream->update([
            'size' => filesize($localPath),
            'status' => VideoStatus::COMPLETED->value,
            'progress' => 100,
            'completed_at' => now(),
        ]);

        $this->markVideoCompletedIfReady($stream->video);
    }

    private function markVideoCompletedIfReady($video): void
    {
        DB::transaction(function () use ($video) {
            $hasPending = $video->streams()
                ->lockForUpdate()
                ->whereIn('type', ['video', 'audio', 'muxed'])
                ->where('status', '!=', VideoStatus::COMPLETED->value)
                ->exists();

            if (! $hasPending) {
                $video->update(['status' => VideoStatus::COMPLETED->value]);

                activity('video')
                    ->performedOn($video)
                    ->causedBy($video->user)
                    ->event('video_completed')
                    ->log("Video processing completed: {$video->name}");
            }
        });
    }

    private function upload(Stream $stream, string $localPath): void
    {
        $stream->update(['status' => VideoStatus::UPLOADING->value]);

        $fileResource = fopen($localPath, 'r');

        if (! $fileResource) {
            throw new Exception("Could not open local file: $localPath");
        }

        try {
            $uploaded = Storage::put($stream->path, $fileResource);

            if (! $uploaded) {
                throw new Exception("Error uploading stream to S3: {$stream->path}");
            }
        } finally {
            if (is_resource($fileResource)) {
                fclose($fileResource);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $stream = Stream::with('video.user')->find($this->streamId);

        if ($stream) {
            $stream->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => $e->getMessage(),
            ]);

            if ($stream->video) {
                activity('video')
                    ->performedOn($stream->video)
                    ->causedBy($stream->video->user)
                    ->withProperties(['stream_id' => $stream->id, 'stream_type' => $stream->type, 'error' => $e->getMessage()])
                    ->event('stream_upload_failed')
                    ->log("Stream upload failed: {$e->getMessage()}");
            }
        }
    }
}
