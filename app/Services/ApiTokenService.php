<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

class ApiTokenService
{
    public function createToken(User $user, array $data)
    {
        return $user->createToken($data['name']);
    }

    /** Revokes the project's current API key and issues a new one. The project is the tokenable. */
    public function regenerateProjectKey(Project $project): NewAccessToken
    {
        $project->tokens()->delete();

        return $project->createToken($project->name.' API key');
    }

    public function revokeToken(User $user, int $tokenId): void
    {
        $token = $user->tokens()->findOrFail($tokenId);
        $token->delete();
    }
}
