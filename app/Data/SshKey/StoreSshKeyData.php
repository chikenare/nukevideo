<?php

namespace App\Data\SshKey;

use App\Data\RequestData;
use phpseclib3\Crypt\PublicKeyLoader;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class StoreSshKeyData extends RequestData
{
    public function __construct(
        public string $name,
        #[MapInputName(CamelCaseMapper::class)]
        public string $publicKey,
        #[MapInputName(CamelCaseMapper::class)]
        public string $privateKey,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'required|max:50',
            'publicKey' => [
                'required',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        PublicKeyLoader::load($value);

                        if (str_contains($value, 'PRIVATE KEY')) {
                            return $fail('The public key must not be a private key.');
                        }
                    } catch (\Throwable $e) {
                        return $fail('The public key is invalid.');
                    }
                },
            ],
            'privateKey' => [
                'required',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        PublicKeyLoader::load($value);

                        if (! str_contains($value, 'PRIVATE KEY')) {
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
