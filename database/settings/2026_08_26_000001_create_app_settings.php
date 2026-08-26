<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The single SSH key, as a setting, replacing the `ssh_keys` table that the schema migration
 * after this one drops. Nothing is carried over on purpose: an existing installation imports its
 * key again under Settings → App, which is a one-line paste, whereas guessing which of several
 * old rows the fleet actually trusts is not something a migration can get right silently.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('app.ssh_private_key', '');
        $this->migrator->add('app.ssh_public_key', '');
        $this->migrator->add('app.ssh_fingerprint', '');
    }

    public function down(): void
    {
        $this->migrator->delete('app.ssh_private_key');
        $this->migrator->delete('app.ssh_public_key');
        $this->migrator->delete('app.ssh_fingerprint');
    }
};
