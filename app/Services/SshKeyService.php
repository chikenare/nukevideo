<?php

namespace App\Services;

use App\Models\SshKey;

class SshKeyService
{
    public function createKey(array $data): SshKey
    {
        $data['fingerprint'] = $this->generateFingerprint($data['public_key']);

        return SshKey::create($data);
    }

    private function generateFingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $keyData = base64_decode($parts[1] ?? $parts[0]);

        return implode(':', str_split(md5($keyData), 2));
    }
}
