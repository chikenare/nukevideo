<?php

namespace App\Data\ApiToken;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\Validation\Max;

class StoreApiTokenData extends RequestData
{
    public function __construct(
        #[Max(255)]
        public string $name,
    ) {}
}
