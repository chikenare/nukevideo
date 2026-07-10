<?php

namespace App\Data\Project;

use App\Data\ProjectSettingsData;
use App\Data\RequestData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Optional;

class UpdateProjectData extends RequestData
{
    public function __construct(
        public string|Optional $name,
        public ProjectSettingsData|Optional $settings,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where('user_id', request()->user()->id)
                    ->whereNull('deleted_at')
                    ->ignore(request()->route('project'), 'ulid'),
            ],
            'settings' => 'sometimes|array',
        ];
    }
}
