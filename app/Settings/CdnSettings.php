<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class CdnSettings extends Settings
{
    public string $provider;

    // Per-driver config keyed by CdnDriver value (JSON); shape mirrors CdnSettingsData.
    public array $providers;

    public static function group(): string
    {
        return 'cdn';
    }
}
