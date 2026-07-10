<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class TopVideoData extends Data
{
    public function __construct(
        public string $video,
        public ?string $externalResourceId,
        public float $bytes,
        public int $sessions,
        public int $uniqueIps,
    ) {}
}
