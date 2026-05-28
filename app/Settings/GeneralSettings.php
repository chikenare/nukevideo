<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $registration_enabled;

    public bool $include_subtitles;

    public static function group(): string
    {
        return 'general';
    }
}
