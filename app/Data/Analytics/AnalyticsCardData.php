<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class AnalyticsCardData extends Data
{
    public function __construct(
        public string $label,
        public float $value,
        public string $format,
    ) {}
}
