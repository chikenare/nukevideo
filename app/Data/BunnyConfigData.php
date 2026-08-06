<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class BunnyConfigData extends Data
{
    public function __construct(
        public string $host = '',
        public string $tokenKey = '',
        public int $tokenWindow = 3600,
        // Logging API credentials, only needed for bandwidth analytics ingestion.
        public string $apiKey = '',
        public string $pullZoneId = '',
    ) {}
}
