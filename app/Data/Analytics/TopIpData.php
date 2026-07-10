<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class TopIpData extends Data
{
    public function __construct(
        public string $ip,
        public float $bytes,
        public int $sessions,
    ) {}
}
