<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class DownloadLinkData extends Data
{
    public function __construct(
        public string $url,
        public string $expiresAt,
        public string $filename,
        public string $type,
        public ?int $size,
    ) {}
}
