<?php

namespace App\Http\Requests\Template;

use App\Rules\TemplateAudioRule;
use App\Rules\TemplateFormatRule;
use App\Rules\TemplateVideoCodecRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'keep_processed_files' => 'sometimes|boolean',
            'query.outputs' => 'required|array|min:1',
            'query.outputs.*.video_codec' => ['required', 'string', new TemplateVideoCodecRule],
            'query.outputs.*.variants' => 'required|array|min:1',
            'query.outputs.*.variants.*' => new TemplateFormatRule,
            'query.outputs.*.audio' => ['required', 'array', new TemplateAudioRule],
        ];
    }
}
