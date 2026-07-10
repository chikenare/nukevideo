<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class TopExternalUserData extends Data
{
    public function __construct(
        public string $externalUserId,
        public float $bytes,
    ) {}
}
