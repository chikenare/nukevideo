<?php

namespace App\Data\AppSettings;

use App\Data\RequestData;
use App\Services\CloudflareDns;
use App\Settings\CloudflareSettings;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class UpdateCloudflareData extends RequestData
{
    public function __construct(
        /**
         * Absent or null keeps the stored token, so the panel can flip `proxied` without ever
         * having been handed the token back. Required the first time.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $apiToken = null,
        /** Create the records behind Cloudflare's proxy instead of DNS only. */
        public bool $proxied = false,
    ) {}

    public static function rules(): array
    {
        return [
            'apiToken' => [
                Rule::requiredIf(fn () => app(CloudflareSettings::class)->api_token === ''),
                'nullable',
                'string',
                'max:255',
                // Tried before it is stored: a token that cannot list zones cannot do anything
                // else here either, and finding that out at the next deploy is finding it out
                // in the middle of one.
                function ($attr, $value, $fail) {
                    try {
                        app(CloudflareDns::class)->zones($value);
                    } catch (\Throwable $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
            'proxied' => ['sometimes', 'boolean'],
        ];
    }
}
