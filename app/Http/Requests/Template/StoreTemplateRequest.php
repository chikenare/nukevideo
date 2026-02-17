<?php

namespace App\Http\Requests\Template;

use App\Rules\TemplateFormatRule;
use App\Rules\TemplateRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'query.output_format' => 'sometimes|string|in:hls,mp4,mkv',
            'query.variants' => 'array',
            'query.variants.*' => new TemplateFormatRule
        ];
    }
}
