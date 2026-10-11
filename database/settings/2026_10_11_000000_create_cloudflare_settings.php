<?php

use App\Settings\CloudflareSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The Cloudflare API token the panel creates the proxies' DNS records with
 * ({@see CloudflareSettings}). Empty by default: an installation that never sets one behaves
 * exactly as before, with the hostnames pointed at the nodes by hand.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('cloudflare.api_token', '');
        $this->migrator->add('cloudflare.proxied', false);
    }

    public function down(): void
    {
        $this->migrator->delete('cloudflare.api_token');
        $this->migrator->delete('cloudflare.proxied');
    }
};
