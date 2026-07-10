<?php

namespace App\Data;

use App\Models\Project;
use Spatie\LaravelData\Data;

class ProjectData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        public ?ProjectSettingsData $settings,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            ulid: $project->ulid,
            name: $project->name,
            settings: $project->settings ? ProjectSettingsData::from([
                'webhookUrl' => $project->settings['webhookUrl'] ?? $project->settings['webhook_url'] ?? null,
                'webhookSecret' => $project->settings['webhookSecret'] ?? $project->settings['webhook_secret'] ?? null,
            ]) : null,
            createdAt: $project->created_at->toIso8601String(),
            updatedAt: $project->updated_at?->toIso8601String(),
        );
    }
}
