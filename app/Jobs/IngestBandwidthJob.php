<?php

namespace App\Jobs;

use App\Services\VodSessionService;
use ClickHouseDB\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Enriches a batch of raw bandwidth events (session_id + ip + bytes, already
 * aggregated by Vector) with the session metadata stashed in Redis at link-issue
 * time, then writes the final rows straight to ClickHouse `sessions`. This is the
 * "join" that used to live in ClickHouse (sessions_active + dictionary + MV).
 */
class IngestBandwidthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    /** @param array<int, array{session_id?: string, ip?: string, bytes?: int|string}> $events */
    public function __construct(public array $events) {}

    public function handle(): void
    {
        $columns = ['date', 'session_id', 'user_id', 'video_ulid', 'output_ulid', 'external_resource_id', 'external_user_id', 'ip', 'bytes'];
        $date = now()->format('Y-m-d');
        $rows = [];

        foreach ($this->events as $event) {
            $sessionId = (string) ($event['session_id'] ?? '');
            $ip = (string) ($event['ip'] ?? '');
            $bytes = (int) ($event['bytes'] ?? 0);

            if ($sessionId === '' || $bytes <= 0) {
                continue;
            }

            $meta = VodSessionService::resolve($sessionId) ?? [];

            $rows[] = [
                $date,
                $sessionId,
                (int) ($meta['user_id'] ?? 0),
                (string) ($meta['video_ulid'] ?? ''),
                (string) ($meta['output_ulid'] ?? ''),
                (string) ($meta['external_resource_id'] ?? ''),
                (string) ($meta['external_user_id'] ?? ''),
                $ip,
                $bytes,
            ];
        }

        if ($rows === []) {
            return;
        }

        try {
            app(Client::class)
                ->https(app()->isProduction())
                ->insert('sessions', $rows, $columns);
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest bandwidth batch: '.$e->getMessage());
            throw $e;
        }
    }
}
