<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\ThumbnailService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractThumbnailJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const POSITION_PERCENT = 30;

    public function __construct(
        public int $videoId,
        public string $mirrorPath,
    ) {}

    public function handle(): void
    {
        Log::info('ExtractThumbnail started', ['video' => $this->videoId]);

        try {
            $video = Video::find($this->videoId);

            if (! $video) {
                throw new Exception("Video {$this->videoId} not found");
            }

            $video->heartbeat();

            $sourceUrl = SegmentVideoJob::sourceUrl($this->mirrorPath);

            $thumbnailLocalPath = Storage::disk('tmp')->path("{$video->ulid}/thumbnail.jpg");

            $offset = (int) (self::POSITION_PERCENT / 100 * $video->duration);

            app(ThumbnailService::class)->extractThumbnail($sourceUrl, $thumbnailLocalPath, $offset);

            $this->publish($video->stagingKey('thumbnail.jpg'), $thumbnailLocalPath);
        } catch (Throwable $e) {
            $this->reportFailure($e);
        }
    }

    private function publish(string $key, string $localPath): void
    {
        $handle = fopen($localPath, 'r');

        try {
            Storage::disk('chunks')->writeStream($key, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($localPath);
        }
    }

    private function reportFailure(Throwable $e): void
    {
        Log::warning("Thumbnail extraction failed for video {$this->videoId}: {$e->getMessage()}");

        $video = Video::with('user')->find($this->videoId);

        if ($video) {
            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->withProperties(['error' => $e->getMessage()])
                ->event('thumbnail_failed')
                ->log("Thumbnail extraction failed: {$e->getMessage()}");
        }
    }
}
