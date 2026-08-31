<?php

namespace App\Data;

use App\Data\Stream\DownloadStreamData;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class VodData extends RequestData
{
    public function __construct(
        #[Min(144), Max(4320)]
        public ?int $resolution,
        #[Max(255)]
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $externalResourceId,
        #[Max(255)]
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $externalUserId,
        #[IP]
        public ?string $ip,
        #[In(['dash', 'hls'])]
        public ?string $format,
        // The caller's own label for this viewer — a customer, a campaign — so the playback
        // traffic can be attributed to it. Never part of the URL: the mint records it against the
        // link's token ({@see \App\Services\Cdn\TrackingRegistry}). Same alphabet as the download
        // links'. Validated in rules(), not here.
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId,
    ) {}

    /**
     * Keyed on the properties' own names, not the mapped snake_case ones. That is what the package
     * documents, and what makes a rule cover a payload in either spelling: Spatie normalises the
     * keys to property names before validating ({@see DownloadStreamData::rules()}).
     */
    public static function rules(): array
    {
        return [
            'trackingId' => TrackingId::rules(),
        ];
    }
}
