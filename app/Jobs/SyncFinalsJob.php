<?php

namespace App\Jobs;

use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\Concerns\SyncsViaAwsCli;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SyncFinalsJob implements ShouldBeUnique, ShouldQueue
{
    use CompletesVideo, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaAwsCli;

    public $tries = 1;

    public $timeout = 300;

    public function __construct(
        public int $videoId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function handle(): void
    {
        $video = Video::with(['streams', 'outputs.streams'])->find($this->videoId);

        if (! $video) {
            return;
        }

        // Idempotent: a prior run already synced and finalized the video.
        if (! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $video->heartbeat();

        $gatherDir = Storage::disk('local')->path("{$video->ulid}/gather");

        try {
            $this->gatherFinals($video, $gatherDir);
            $this->syncToPrimary($video, $gatherDir);

            foreach ($video->streams as $stream) {
                $this->markOutputCompletedIfReady($stream);
            }

            $this->finalizeVideoIfReady($video);
        } finally {
            Storage::disk('local')->deleteDirectory("{$video->ulid}/gather");
        }
    }

    /**
     * Pull `{ulid}/final/` off the mirror into local scratch with one `aws s3 sync`. Sync drops the
     * source prefix, so the local tree matches the destination `{ulid}/` prefix exactly.
     */
    private function gatherFinals(Video $video, string $gatherDir): void
    {
        if (empty(Storage::disk('chunks')->allFiles("{$video->ulid}/final"))) {
            throw new RuntimeException("No staged finals on mirror for video {$video->id}");
        }

        $this->awsS3Sync(
            'chunks',
            $this->awsS3Uri('chunks', "{$video->ulid}/final/"),
            $gatherDir,
            $video,
            $this->timeout - 60,
        );
    }

    /** One `aws s3 sync` of the gathered tree to the primary bucket under `{ulid}/`. */
    private function syncToPrimary(Video $video, string $gatherDir): void
    {
        $this->awsS3Sync(
            's3',
            $gatherDir,
            $this->awsS3Uri('s3', "{$video->ulid}/"),
            $video,
            $this->timeout - 60,
        );

        Log::info('Finals synced to primary S3', ['video' => $video->id]);
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with(['streams', 'outputs.streams'])->find($this->videoId);

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        foreach ($video->streams as $stream) {
            $this->markOutputsFailedForStream($stream);
        }

        $this->finalizeVideoIfReady($video);
    }
}
