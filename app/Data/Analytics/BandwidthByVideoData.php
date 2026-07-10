<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class BandwidthByVideoData extends Data
{
    public function __construct(
        public string $date,
        public string $video,
        public float $bytes,
    ) {}
}
