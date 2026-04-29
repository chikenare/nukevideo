<?php

namespace App\Http\Requests\SshKey;

use Illuminate\Foundation\Http\FormRequest;
use phpseclib3\Crypt\PublicKeyLoader;

class StoreSshKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|max:50',
            'public_key' => [
                'required',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        $key = PublicKeyLoader::load($value);
                        if (str_contains($value, 'PRIVATE KEY')) {
                            return $fail('The public key must not be a private key.');
                        }

                    } catch (\Throwable $e) {
                        return $fail('The public key is invalid.');
                    }
                },
            ],

            'private_key' => [
                'required',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        $key = PublicKeyLoader::load($value);

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
