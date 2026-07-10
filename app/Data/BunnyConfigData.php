<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class BunnyConfigData extends Data
{
    public function __construct(
        public string $host = '',
        public string $tokenKey = '',
        public int $tokenWindow = 3600,
    ) {}
}
