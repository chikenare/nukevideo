<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class EncodingPointData extends Data
{
    public function __construct(
        public string $date,
        public string $device,
        public float $seconds,
    ) {}
}
