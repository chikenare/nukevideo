<?php

namespace App\Data;

use App\Models\SshKey;
use Spatie\LaravelData\Data;

class SshKeyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $publicKey,
        public ?string $fingerprint,
        public string $createdAt,
    ) {}

    public static function fromModel(SshKey $key): self
    {
        return new self(
            id: $key->id,
            name: $key->name,
            publicKey: $key->public_key,
            fingerprint: $key->fingerprint,
            createdAt: $key->created_at->toIso8601String(),
        );
    }
}
