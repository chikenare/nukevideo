<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * One proxy node's delivery over a window: what it served, what it had to fetch from S3 to do
 * so, and how much of what it served came out of its cache. `hitRatio` is null when the node
 * served nothing the cache had a say in — no traffic, or only manifests and downloads.
 */
class EdgeDeliveryData extends Data
{
    public function __construct(
        public int $nodeId,
        public float $deliveredBytes,
        public float $originBytes,
        public ?float $hitRatio,
    ) {}
}
