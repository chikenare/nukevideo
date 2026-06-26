<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'resolution' => 'nullable|integer|min:144|max:4320',
            'external_resource_id' => 'nullable|string|max:255',
            'external_user_id' => 'nullable|string|max:255',
            'ip' => 'nullable|ip',
        ];
    }
}
