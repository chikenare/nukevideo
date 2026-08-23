<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class VodData extends RequestData
{
    public function __construct(
        #[Min(144), Max(4320)]
        public ?int $resolution,
        #[Max(255)]
        public ?string $externalResourceId,
        #[Max(255)]
        public ?string $externalUserId,
        #[IP]
        public ?string $ip,
        #[In(['dash', 'hls'])]
        public ?string $format,
        // The caller's own label for this viewer — a customer, a campaign — so the playback
        // traffic can be attributed to it ({@see \App\Services\Cdn\CdnProvider::manifestUrl()}).
        // Same alphabet as the download links': it becomes a path segment on the self-hosted edge.
        #[Max(64), Regex('/^[A-Za-z0-9_-]+\z/')]
        public ?string $tid,
    ) {}
}
