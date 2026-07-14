<?php

namespace App\Data\Video;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateVideoData extends RequestData
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[MapInputName(CamelCaseMapper::class), Max(255)]
        public string|Optional|null $externalUserId,
        #[MapInputName(CamelCaseMapper::class), Max(255)]
        public string|Optional|null $externalResourceId,
    ) {}
}
