<?php

namespace App\Services;

use App\Models\SshKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

class SshKeyService
{
    /**
     * Create a key from a private key, or generate one when none is given.
     *
     * The private key is the only input that matters: it is what the panel connects with. The
     * public half and the fingerprint are derived from it here rather than accepted from the
     * caller, so what the panel shows — and what the operator pastes into a node's
     * `authorized_keys` — is guaranteed to be the pair the panel will actually present.
     *
     * Generated keys are Ed25519: small, fast, and what every OpenSSH from the last decade
     * accepts; there is no reason to offer a choice.
     *
     * @param  array{name: string, private_key?: string|null}  $data
     */
    public function createKey(array $data): SshKey
    {
        $privateKey = $data['private_key'] ?? null;

        if ($privateKey === null || $privateKey === '') {
            $privateKey = EC::createKey('Ed25519')->toString('OpenSSH');
        }

        // The comment is the trailing word of an OpenSSH public line; phpseclib writes its own,
        // and the key's name is what an operator reading `authorized_keys` on a node wants there.
        $publicKey = PublicKeyLoader::load($privateKey)->getPublicKey()->toString('OpenSSH', ['comment' => $data['name']]);

        return SshKey::create([
            'name' => $data['name'],
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'fingerprint' => $this->generateFingerprint($publicKey),
        ]);
    }

    private function generateFingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $keyData = base64_decode($parts[1] ?? $parts[0]);

        return implode(':', str_split(md5($keyData), 2));
    }
}
