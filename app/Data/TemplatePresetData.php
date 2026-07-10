<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class TemplatePresetData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $category,
        /** @var array<string, mixed> */
        public array $query,
    ) {}
}
