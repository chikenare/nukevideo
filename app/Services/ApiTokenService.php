<?php

namespace App\Services;

use App\Models\User;

class ApiTokenService
{
    public function createToken(User $user, array $data)
    {
        return $user->createToken($data['name']);
    }

    public function revokeToken(User $user, int $tokenId): void
    {
        $token = $user->tokens()->findOrFail($tokenId);
        $token->delete();
    }
}
