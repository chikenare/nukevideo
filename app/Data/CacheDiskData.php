<?php

namespace App\Data;

use App\Services\ProxyCacheService;
use Spatie\LaravelData\Data;

/**
 * One whole disk on a proxy node's host as the deploy sees it. `state` is what decides its
 * fate ({@see ProxyCacheService::inventoryScript()}): `system` is left alone,
 * `nukevideo` is mounted as the existing cache, `empty` and `foreign` are formatted into it.
 */
class CacheDiskData extends Data
{
    public function __construct(
        public string $device,
        public int $size,
        public string $model,
        public string $state,
        public string $detail,
    ) {}
}
