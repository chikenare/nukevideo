<?php

namespace App\Data\Profile;

use App\Data\RequestData;
use Illuminate\Validation\Rule;

class UpdateProfileData extends RequestData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore(request()->user()->id),
            ],
        ];
    }
}
