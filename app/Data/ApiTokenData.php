<?php

namespace App\Data;

use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ApiTokenData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        /** @var string[]|null */
        public ?array $abilities,
        public ?string $lastUsedAt,
        public string $createdAt,
        public ?string $expiresAt,
        public string|Optional $token,
    ) {}

    public static function fromModel(PersonalAccessToken $token): self
    {
        return new self(
            id: $token->id,
            name: $token->name,
            abilities: $token->abilities,
            lastUsedAt: $token->last_used_at?->toISOString(),
            createdAt: $token->created_at->toISOString(),
            expiresAt: $token->expires_at?->toISOString(),
            token: Optional::create(),
        );
    }

    public static function fromNewAccessToken(NewAccessToken $newToken): self
    {
        $data = self::fromModel($newToken->accessToken);
        $data->token = $newToken->plainTextToken;

        return $data;
    }
}
