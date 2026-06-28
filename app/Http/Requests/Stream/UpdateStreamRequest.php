<?php

namespace App\Http\Requests\Stream;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:64',
            'language' => 'nullable|string|max:20',
        ];
    }
}
