<?php

namespace App\Data\User;

use App\Data\RequestData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateUserData extends RequestData
{
    public function __construct(
        public string|Optional $name,
        public string|Optional $email,
        public string|Optional $password,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isAdmin,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore(request()->route('user'))],
            'password' => 'sometimes|string|min:8',
            'isAdmin' => 'sometimes|boolean',
        ];
    }
}
