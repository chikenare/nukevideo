<?php

namespace App\Data\SshKey;

use App\Data\RequestData;
use App\Services\SshKeyService;
use phpseclib3\Crypt\PublicKeyLoader;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class StoreSshKeyData extends RequestData
{
    public function __construct(
        public string $name,
        /**
         * The private half only, and only when importing a pair made elsewhere. Absent, the
         * server generates one ({@see SshKeyService::createKey}). The public half is
         * never accepted: it is derived from the private key, so the two can never disagree, and
         * a pasted public key that did not match the private one used to be stored as if it did.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $privateKey = null,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'required|max:50',
            'privateKey' => [
                'nullable',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        $key = PublicKeyLoader::load($value);

                        if (! str_contains($value, 'PRIVATE KEY') || ! method_exists($key, 'getPublicKey')) {
                            return $fail('The private key format is invalid.');
                        }
                    } catch (\Throwable $e) {
                        return $fail('The private key is invalid.');
                    }
                },
            ],
        ];
    }
}
