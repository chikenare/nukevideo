<?php

namespace App\Http\Requests\ApiToken;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}
