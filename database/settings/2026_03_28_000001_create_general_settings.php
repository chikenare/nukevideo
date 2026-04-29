<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.registration_enabled', true);
        $this->migrator->add('general.include_subtitles', true);
    }
};
