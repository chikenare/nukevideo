<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ValidationCheckData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $output,
    ) {}
}
