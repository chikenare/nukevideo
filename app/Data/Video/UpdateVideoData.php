<?php

namespace App\Data\Video;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\Validation\Max;

class UpdateVideoData extends RequestData
{
    public function __construct(
        #[Max(255)]
        public string $name,
    ) {}
}
