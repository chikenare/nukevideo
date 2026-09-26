<?php

use ClickHouseDB\Client;

/**
 * The package default was a 1s budget for the whole request, DNS included: a worker lost every
 * usage row whose lookup stalled ("Resolving timed out after 1000 milliseconds").
 */
it('gives a remote ClickHouse more than a second, lookup and handshake included', function () {
    $client = app(Client::class);

    expect($client->getTimeout())->toBe(5)
        ->and($client->getConnectTimeOut())->toBe(3.0);
});

it('takes both budgets from its config, which the environment feeds', function () {
    config(['clickhouse.connection.options.timeout' => 12, 'clickhouse.connection.options.connectTimeOut' => 1.5]);
    app()->forgetInstance(Client::class);

    $client = app(Client::class);

    expect($client->getTimeout())->toBe(12)
        ->and($client->getConnectTimeOut())->toBe(1.5);
});
