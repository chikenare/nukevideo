<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class SubtitleData extends Data
{
    public function __construct(
        public string $name,
        public ?string $language,
        public string $url,
    ) {}
}
