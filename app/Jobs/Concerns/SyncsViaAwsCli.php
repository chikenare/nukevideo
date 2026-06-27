<?php

namespace App\Jobs\Concerns;

use App\Models\Video;
use App\Services\Concerns\EmitsHeartbeat;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper over `aws s3 sync` for transfers between local scratch and an S3-compatible disk.
 * The CLI parallelizes and retries transient failures on its own; we just feed it the disk's
 * credentials/endpoint explicitly (in dev the AWS_* vars only live in Laravel's .env, not the
 * process env) and heartbeat off its progress so the reaper never mistakes a long copy for a
 * dead worker. Hosts must also use {@see EmitsHeartbeat}.
 */
trait SyncsViaAwsCli
{
    use EmitsHeartbeat;

    /**
     * `aws s3 sync $source $dest` with the given disk's credentials. `$source`/`$dest` are a local
     * path and an `s3://…` URI in either order (download or upload). Passed as argv (not a shell
     * string) so the endpoint URL and globs need no quoting.
     *
     * @param  list<string>  $extraArgs  e.g. ['--exclude', '*', '--include', 'chunk_*.mp4']
     */
    private function awsS3Sync(string $disk, string $source, string $dest, Video $video, int $timeout, array $extraArgs = []): void
    {
        $cfg = config("filesystems.disks.{$disk}");

        $result = Process::timeout($timeout)
            ->env([
                'AWS_ACCESS_KEY_ID' => $cfg['key'],
                'AWS_SECRET_ACCESS_KEY' => $cfg['secret'],
                'AWS_DEFAULT_REGION' => $cfg['region'] ?: 'us-east-1',
                'AWS_S3_ADDRESSING_STYLE' => ! empty($cfg['use_path_style_endpoint']) ? 'path' : 'auto',
            ])
            ->run([
                'aws', 's3', 'sync', $source, $dest,
                '--endpoint-url', $cfg['endpoint'],
                '--only-show-errors',
                ...$extraArgs,
            ], function () use ($video) {
                $this->heartbeat($video);
            });

        if (! $result->successful()) {
            throw new RuntimeException("aws s3 sync failed ({$source} → {$dest}): {$result->errorOutput()}");
        }
    }

    /** `s3://` URI for a key on the given disk's bucket. */
    private function awsS3Uri(string $disk, string $key): string
    {
        return 's3://'.config("filesystems.disks.{$disk}.bucket").'/'.ltrim($key, '/');
    }
}
