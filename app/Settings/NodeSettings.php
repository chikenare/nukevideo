<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NodeSettings extends Settings
{
    public string $environment;

    public static function group(): string
    {
        return 'node';
    }
}
