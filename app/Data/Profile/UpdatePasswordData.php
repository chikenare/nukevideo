<?php

namespace App\Data\Profile;

use App\Data\RequestData;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class UpdatePasswordData extends RequestData
{
    public function __construct(
        #[MapInputName(CamelCaseMapper::class)]
        public string $currentPassword,
        public string $password,
    ) {}

    public static function rules(): array
    {
        return [
            'currentPassword' => 'required|string',
            'password' => ['required', 'string', Password::min(8), 'same:passwordConfirmation'],
        ];
    }
}
