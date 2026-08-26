<?php

namespace App\Data;

use App\Settings\AppSettings;
use Spatie\LaravelData\Data;

class AppSettingsData extends Data
{
    public function __construct(
        // The public half of the panel's SSH key, or null before one exists. The private half is
        // never exposed: it is what the panel connects with.
        public ?string $sshPublicKey,
        public ?string $sshFingerprint,
    ) {}

    public static function fromSettings(AppSettings $settings): self
    {
        return new self(
            sshPublicKey: $settings->ssh_public_key !== '' ? $settings->ssh_public_key : null,
            sshFingerprint: $settings->ssh_fingerprint !== '' ? $settings->ssh_fingerprint : null,
        );
    }
}
