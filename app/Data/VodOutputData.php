<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class VodOutputData extends Data
{
    public function __construct(
        public string $ulid,
        public string $format,
        public string $url,
    ) {}
}
