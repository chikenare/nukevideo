<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Enums\VideoPriority;
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
        // Reorders the video while it is pending, and again whenever a retry puts it back there.
        public VideoPriority|Optional $priority,
    ) {}
}
