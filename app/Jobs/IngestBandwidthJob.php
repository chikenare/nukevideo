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
use Illuminate\Support\Str;

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
            $ip = (string) ($event['ip'] ?? '');

            // The ulid is parsed out of a public request path, so it is attacker-controlled text
            // until it matches the shape: anything else must never reach a stored column. The
            // address gets the same treatment for a blunter reason — the column is `IPv6`, which
            // cannot parse '' or '-', and ClickHouse rejects the whole block over one bad value.
            // Dropping the row loses one event; letting it through loses the entire batch.
            if (! Str::isUlid($videoUlid) || $bytes <= 0 || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $valid[] = [$videoUlid, $ip, $bytes, $this->eventDate($event)];
            $ulids[$videoUlid] = true;
        }

        if ($valid === []) {
            return;
        }

        // One batched lookup video_ulid -> owning user_id (0 when the video is gone).
        $owners = Video::whereIn('ulid', array_keys($ulids))->pluck('user_id', 'ulid');

        // Ingest time, not traffic time — the ingest runs every five minutes over a window that
        // ends two minutes in the past, so the run just after midnight books the tail of the old
        // day to the new one. `date` is the column every analytics query groups on and the
        // partition key, so that slice is misattributed permanently. Prefer a date carried on the
        // event; the fallback is only for events emitted before the edge started sending one.
        $ingestedOn = now()->format('Y-m-d');
        $columns = ['date', 'user_id', 'video_ulid', 'ip', 'bytes'];
        $rows = [];

        foreach ($valid as [$videoUlid, $ip, $bytes, $date]) {
            $rows[] = [$date ?? $ingestedOn, (int) ($owners[$videoUlid] ?? 0), $videoUlid, $ip, $bytes];
        }

        try {
            // ClickHouse is behind TLS everywhere but local dev — staging included. Keep this
            // rule in lockstep with UsageService.
            app(Client::class)
                ->https(! app()->isLocal())
                ->insert('video_usage', $rows, $columns);
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest bandwidth batch: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * The day the traffic actually happened, when the edge reports it. `video_usage` is a
     * SummingMergeTree, so a row can only ever be added — a date that drifts by a few minutes at
     * a boundary is not something a later correction can take back.
     *
     * @param  array<string, mixed>  $event
     */
    private function eventDate(array $event): ?string
    {
        foreach (['date', 'timestamp'] as $key) {
            if (empty($event[$key])) {
                continue;
            }

            $stamp = strtotime((string) $event[$key]);

            if ($stamp !== false) {
                return date('Y-m-d', $stamp);
            }
        }

        return null;
    }
}
