<?php

namespace App\Data;

use App\Enums\DownloadSkipReason;
use Spatie\LaravelData\Data;

class SkippedTrackData extends Data
{
    public function __construct(
        public string $ulid,
        public DownloadSkipReason $reason,
    ) {}
}
