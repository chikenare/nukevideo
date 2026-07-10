<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ServiceStatusData extends Data
{
    public function __construct(
        public string $name,
        public int $running,
        public ?int $desired,
        public string $state,
    ) {}
}
