<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class BandwidthPointData extends Data
{
    public function __construct(
        public string $date,
        public float $bytes,
        public int $sessions,
    ) {}
}
