<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Sensible defaults; secrets start empty and are set from the admin UI.
        $this->migrator->add('cdn.provider', 'self_hosted');

        $this->migrator->add('cdn.providers', [
            'self_hosted' => [
                'token_secret' => '',
                'token_name' => '__hdnea__',
                'token_window' => 3600,
                'secure_token_expires' => '100d',
                'secure_token_query_expires' => '1h',
                'cache_max_size' => '10g',
                'cache_inactive' => '1h',
            ],
            'bunny' => [
                'host' => '',
                'token_key' => '',
                'token_window' => 3600,
            ],
        ]);
    }
};
