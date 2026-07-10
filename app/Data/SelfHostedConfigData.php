<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class SelfHostedConfigData extends Data
{
    public function __construct(
        public string $tokenSecret = '',
        public string $tokenName = '__hdnea__',
        public int $tokenWindow = 3600,
        public string $secureTokenExpires = '100d',
        public string $secureTokenQueryExpires = '1h',
        public string $cacheMaxSize = '10g',
        public string $cacheInactive = '1h',
    ) {}
}
