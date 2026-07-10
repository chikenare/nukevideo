<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $isAdmin,
        /** @var ProjectData[] */
        public ?array $projects,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            isAdmin: (bool) $user->is_admin,
            projects: $user->relationLoaded('projects')
                ? ProjectData::collect($user->projects->all())
                : null,
        );
    }
}
