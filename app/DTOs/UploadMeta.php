<?php

namespace App\DTOs;

use App\Models\Project;
use App\Models\User;

class UploadMeta
{
    public function __construct(
        public readonly string $user,
        public readonly string $project,
        public readonly string $template,
        public readonly string $filename,
        public readonly ?string $externalUserId = null,
        public readonly ?string $externalResourceId = null,
    ) {}

    public static function fromRequest(User $user, Project $project, array $input): self
    {
        return new self(
            user: $user->ulid,
            project: $project->ulid,
            template: $input['metadata']['template'],
            filename: $input['filename'],
            externalUserId: $input['metadata']['externalUserId'] ?? null,
            externalResourceId: $input['metadata']['externalResourceId'] ?? null,
        );
    }
}
