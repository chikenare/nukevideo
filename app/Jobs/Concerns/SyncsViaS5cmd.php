<?php

namespace App\Jobs\Concerns;

use App\Models\Video;
use App\Services\Concerns\EmitsHeartbeat;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper over `s5cmd sync` for transfers between local scratch and an S3-compatible disk.
 * s5cmd parallelizes aggressively (256 workers by default), which is what keeps trees of
 * thousands of tiny CMAF segments fast; we just feed it the disk's credentials/endpoint
 * explicitly (in dev the AWS_* vars only live in Laravel's .env, not the process env) and
 * heartbeat off its per-object output so the reaper never mistakes a long copy for a dead
 * worker. Hosts must also use {@see EmitsHeartbeat}.
 */
trait SyncsViaS5cmd
{
    use EmitsHeartbeat;

    /**
     * `s5cmd sync $source $dest` with the given disk's credentials. `$source`/`$dest` are a local
     * path and an `s3://…` URI in either order; s3 sources need a glob (`s3://bucket/prefix/*`).
     * Passed as argv (not a shell string) so the globs need no quoting. With a custom endpoint
     * s5cmd resolves buckets path-style on its own.
     *
     * @param  list<string>  $extraArgs  e.g. ['--include', '*.mp4', '--content-type', 'video/mp4']
     */
    private function s5cmdSync(string $disk, string $source, string $dest, Video $video, int $timeout, array $extraArgs = []): void
    {
        $cfg = config("filesystems.disks.{$disk}");

        $result = Process::timeout($timeout)
            ->env($this->s5cmdEnv($cfg))
            ->run([...$this->s5cmdBase($cfg), 'sync', ...$extraArgs, $source, $dest], function () use ($video) {
                $this->heartbeat($video);
            });

        if (! $result->successful()) {
            throw new RuntimeException("s5cmd sync failed ({$source} → {$dest}): {$result->errorOutput()}");
        }
    }

    /**
     * `s5cmd cp $source $dest` for a SINGLE object (one side local, the other an `s3://…` URI).
     * Unlike {@see s5cmdSync}, a lone large file emits no per-object output mid-transfer, so run it
     * async and heartbeat off a poll loop — otherwise the reaper would mistake a long copy for a
     * dead worker. `cp` overwrites the destination, so a redelivery re-copies cleanly.
     */
    private function s5cmdCopy(string $disk, string $source, string $dest, Video $video, int $timeout): void
    {
        $cfg = config("filesystems.disks.{$disk}");

        $process = Process::timeout($timeout)
            ->env($this->s5cmdEnv($cfg))
            ->start([...$this->s5cmdBase($cfg), 'cp', $source, $dest]);

        while ($process->running()) {
            $this->heartbeat($video); // self-throttles to one write per interval
            usleep(5_000_000);
        }

        $result = $process->wait();

        if (! $result->successful()) {
            throw new RuntimeException("s5cmd cp failed ({$source} → {$dest}): {$result->errorOutput()}");
        }
    }

    /** AWS credential env for the disk; the region falls back since s5cmd requires one. */
    private function s5cmdEnv(array $cfg): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $cfg['key'],
            'AWS_SECRET_ACCESS_KEY' => $cfg['secret'],
            'AWS_REGION' => $cfg['region'] ?: 'us-east-1',
        ];
    }

    /** `s5cmd` argv prefix with the disk's custom endpoint (path-style buckets resolve on their own). */
    private function s5cmdBase(array $cfg): array
    {
        return ['s5cmd', ...($cfg['endpoint'] ? ['--endpoint-url', $cfg['endpoint']] : [])];
    }

    /** `s3://` URI for a key or glob on the given disk's bucket. */
    private function s3Uri(string $disk, string $key): string
    {
        return 's3://'.config("filesystems.disks.{$disk}.bucket").'/'.ltrim($key, '/');
    }
}
