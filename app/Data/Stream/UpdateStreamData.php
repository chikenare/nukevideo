<?php

namespace App\Data\Stream;

use App\Data\RequestData;
use Closure;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Symfony\Component\Intl\Languages;

class UpdateStreamData extends RequestData
{
    public function __construct(
        /**
         * A comma would break the shaka stream descriptor this is interpolated into, and a quote or
         * a newline would break out of the HLS `#EXT-X-MEDIA` line it is written into — the master
         * playlist is edited as text, so a newline here would inject arbitrary tags for every viewer
         * ({@see \App\Services\PackagerCommandBuilder}, {@see \App\Services\ManifestEditor}).
         */
        #[Max(255), Regex('/^[^",\r\n]+\z/u')]
        public string $name,
        public ?string $language,
        public bool $forced,
    ) {}

    /** Same BCP-47 criterion the ingest applies ({@see \App\Services\CreateVideoStreamsService::sourceLanguage}). */
    public static function rules(): array
    {
        return [
            'language' => [
                'nullable',
                // `\z`, not `$`: PCRE's `$` also matches before a trailing newline.
                'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*\z/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $primary = strtolower(explode('-', (string) $value)[0]);

                    if (! Languages::exists($primary) && ! Languages::alpha3CodeExists($primary)) {
                        $fail('The :attribute is not a known language.');
                    }
                },
            ],
        ];
    }
}
