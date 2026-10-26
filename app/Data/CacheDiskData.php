<?php

namespace App\Data;

use App\Services\ProxyCacheService;
use Spatie\LaravelData\Data;

/**
 * One whole disk on a proxy node's host as the deploy sees it. `state` is what decides its
 * fate ({@see ProxyCacheService::inventory()}): `system` is left alone, `nukevideo` is mounted
 * as the existing cache, `empty` and `foreign` are formatted into it, and `shared` — a system
 * disk with `free` bytes still unpartitioned — gets a partition over that space and nothing else.
 */
class CacheDiskData extends Data
{
    public function __construct(
        public string $device,
        public int $size,
        public string $model,
        public string $state,
        public string $detail,
        public int $free,
        /** SSD/NVMe rather than spinning: what an existing tiered pool can add to its fast tier. */
        public bool $fast = false,
    ) {}
}
