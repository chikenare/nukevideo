<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class ProjectSettingsData extends Data
{
    public function __construct(
        #[MapInputName(CamelCaseMapper::class), Url, Max(2048)]
        public ?string $webhookUrl,
        #[MapInputName(CamelCaseMapper::class), Max(255)]
        public ?string $webhookSecret,
    ) {}
}
