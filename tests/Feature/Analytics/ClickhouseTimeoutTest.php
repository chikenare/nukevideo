<?php

use ClickHouseDB\Client;

/**
 * The package default was a 1s budget for the whole request, DNS included: a worker lost every
 * usage row whose lookup stalled ("Resolving timed out after 1000 milliseconds").
 */
it('gives a remote ClickHouse more than a second, lookup and handshake included', function () {
    // The published file's own defaults, read with the variables unset, so a developer's .env
    // setting them can't make this pass or fail.
    $keys = ['CLICKHOUSE_TIMEOUT', 'CLICKHOUSE_CONNECT_TIMEOUT'];
    $saved = array_map(fn (string $key) => [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null], $keys);

    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        $options = (require config_path('clickhouse.php'))['connection']['options'];
    } finally {
        foreach ($keys as $i => $key) {
            [$env, $superEnv, $server] = $saved[$i];
            if ($env !== false) {
                putenv("{$key}={$env}");
            }
            if ($superEnv !== null) {
                $_ENV[$key] = $superEnv;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }

    expect($options['timeout'])->toBe(5)
        ->and($options['connectTimeOut'])->toBe(3.0);
});

it('takes both budgets from its config, which the environment feeds', function () {
    config(['clickhouse.connection.options.timeout' => 12, 'clickhouse.connection.options.connectTimeOut' => 1.5]);
    app()->forgetInstance(Client::class);

    $client = app(Client::class);

    expect($client->getTimeout())->toBe(12)
        ->and($client->getConnectTimeOut())->toBe(1.5);
});
