<?php

namespace App\Jobs;

use App\Models\Video;
use ClickHouseDB\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Writes a batch of aggregated bandwidth events (video_ulid + ip + bytes, already
 * aggregated by Vector) to ClickHouse `video_usage`, deriving the owning `user_id`
 * from the video in one batched lookup. No session/user metadata is involved —
 * the video and IP come straight from the edge log (the URL path + request).
 */
class IngestBandwidthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    /** @param array<int, array{video_ulid?: string, ip?: string, bytes?: int|string}> $events */
    public function __construct(public array $events) {}

    public function handle(): void
    {
        $valid = [];
        $ulids = [];

        foreach ($this->events as $event) {
            $videoUlid = (string) ($event['video_ulid'] ?? '');
            $bytes = (int) ($event['bytes'] ?? 0);

            if ($videoUlid === '' || $bytes <= 0) {
                continue;
            }

            $valid[] = [$videoUlid, (string) ($event['ip'] ?? ''), $bytes];
            $ulids[$videoUlid] = true;
        }

        if ($valid === []) {
            return;
        }

        // One batched lookup video_ulid -> owning user_id (0 when the video is gone).
        $owners = Video::whereIn('ulid', array_keys($ulids))->pluck('user_id', 'ulid');

        $date = now()->format('Y-m-d');
        $columns = ['date', 'user_id', 'video_ulid', 'ip', 'bytes'];
        $rows = [];

        foreach ($valid as [$videoUlid, $ip, $bytes]) {
            $rows[] = [$date, (int) ($owners[$videoUlid] ?? 0), $videoUlid, $ip, $bytes];
        }

        try {
            app(Client::class)
                ->https(app()->isProduction())
                ->insert('video_usage', $rows, $columns);
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest bandwidth batch: '.$e->getMessage());
            throw $e;
        }
    }
}
