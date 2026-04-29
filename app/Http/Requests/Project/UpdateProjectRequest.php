<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $ulid = $this->route('project');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at')
                    ->ignore($ulid, 'ulid'),
            ],
            'settings' => 'sometimes|array',
            'settings.webhook_url' => 'nullable|url|max:2048',
            'settings.webhook_secret' => 'nullable|string|max:255',
        ];
    }
}
