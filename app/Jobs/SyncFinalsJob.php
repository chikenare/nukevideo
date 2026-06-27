<?php

namespace App\Jobs;

use App\Jobs\Concerns\CompletesVideo;
use App\Models\Video;
use App\Services\Concerns\EmitsHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SyncFinalsJob implements ShouldBeUnique, ShouldQueue
{
    use CompletesVideo, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

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
     * Pull `{ulid}/final/` off the mirror into local scratch, preserving the relative layout (the
     * `final/` prefix is stripped so the tree matches the destination `{ulid}/` prefix exactly).
     */
    private function gatherFinals(Video $video, string $gatherDir): void
    {
        $prefix = "{$video->ulid}/final/";
        $keys = Storage::disk('chunks')->allFiles("{$video->ulid}/final");

        if (empty($keys)) {
            throw new RuntimeException("No staged finals on mirror for video {$video->id}");
        }

        $store = Storage::disk('chunks');
        $lastBeat = microtime(true);

        foreach ($keys as $key) {
            $relative = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : basename($key);
            $local = "{$gatherDir}/{$relative}";

            $dir = dirname($local);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $in = $store->readStream($key);
            if ($in === null) {
                throw new RuntimeException("Failed to read staged final from mirror: {$key}");
            }
            $out = fopen($local, 'w');
            try {
                if ($out === false || stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException("Failed to gather staged final locally: {$key}");
                }
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
            }

            if ((microtime(true) - $lastBeat) >= 15) {
                $video->heartbeat();
                $lastBeat = microtime(true);
            }
        }
    }

    /**
     * One `aws s3 sync` of the gathered tree to the primary bucket under `{ulid}/`. Credentials and
     * endpoint are passed explicitly (in dev the AWS_* vars only live in Laravel's .env, not the
     * process env), and as argv (not a shell string) to avoid quoting the endpoint URL.
     */
    private function syncToPrimary(Video $video, string $gatherDir): void
    {
        $s3 = config('filesystems.disks.s3');

        $result = Process::timeout($this->timeout - 120)
            ->env([
                'AWS_ACCESS_KEY_ID' => $s3['key'],
                'AWS_SECRET_ACCESS_KEY' => $s3['secret'],
                'AWS_DEFAULT_REGION' => $s3['region'] ?: 'us-east-1',
                // rustfs / S3-compatible stores generally require path-style addressing.
                'AWS_S3_ADDRESSING_STYLE' => ! empty($s3['use_path_style_endpoint']) ? 'path' : 'auto',
            ])
            ->run([
                'aws', 's3', 'sync', $gatherDir, "s3://{$s3['bucket']}/{$video->ulid}/",
                '--endpoint-url', $s3['endpoint'],
                '--only-show-errors',
            ], function () use ($video) {
                $this->heartbeat($video);
            });

        if (! $result->successful()) {
            Log::error('Finals sync failed', ['video' => $video->id, 'error' => $result->errorOutput()]);
            throw new RuntimeException("aws s3 sync failed: {$result->errorOutput()}");
        }

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
