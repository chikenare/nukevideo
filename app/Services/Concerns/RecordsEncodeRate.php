<?php

namespace App\Services\Concerns;

use App\Models\Stream;
use App\Services\ChunkPlanner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what a rendition costs on THIS node, in wall seconds per second of source, from encodes
 * the probes were already running. Three of them pass through here — per-title anchors, the quality
 * bitrate probe and the preflight — and none exists to measure this; they just happen to run the
 * real chunk command over real footage, which is the only honest way to answer the question
 * {@see ChunkPlanner} would otherwise have to estimate.
 */
trait RecordsEncodeRate
{
    /**
     * The highest reading wins, whichever probe got there first.
     *
     * The three differ in how much company their encode had: per-title anchors run two at a time,
     * the bitrate probe runs alone, the preflight measures one second of the opening — often a
     * title card, the cheapest second in the file. All three are optimistic against a chunk that
     * will run against the node's full encode pool, so taking the maximum keeps the least
     * optimistic of them and makes the order the probes happen to run in stop mattering.
     */
    protected function recordEncodeRate(Stream $stream, float $wallSeconds, float $sourceSeconds): void
    {
        if ($wallSeconds <= 0.0 || $sourceSeconds <= 0.0) {
            return;
        }

        $rate = round($wallSeconds / $sourceSeconds, 4);

        if ($rate <= (float) ($stream->meta['encode_rate'] ?? 0.0)) {
            return;
        }

        // This is a measurement, not a decision: two of the three callers already run inside a
        // catch that keeps a failed probe from failing the video, but {@see RenditionPreflight}
        // does not — it is on the critical path, and a video it fails is one `videos:prune` deletes
        // with the user's only source 24 hours later. Nothing here is worth that.
        try {
            $stream->update(['meta' => [...$stream->meta ?? [], 'encode_rate' => $rate]]);
        } catch (Throwable $e) {
            Log::warning('Could not record the measured encode rate', [
                'stream' => $stream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
