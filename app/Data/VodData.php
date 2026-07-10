<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
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
    ) {}
}
