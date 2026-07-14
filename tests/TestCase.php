<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * A cached config (bootstrap/cache/config.php) shadows phpunit.xml's sqlite settings, which
     * would point RefreshDatabase at the real database and wipe it. Refuse to run instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException(
                'Tests must run on sqlite, got "'.config('database.default').'". Run `php artisan config:clear`.'
            );
        }
    }
}
