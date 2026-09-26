<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Client Configuration
    |--------------------------------------------------------------------------
    |
    | Published from cybercog/laravel-clickhouse for its timeouts alone. The package ships a 1s
    | `timeout`, which the client uses both as the query's max_execution_time and as the whole HTTP
    | request's budget — DNS lookup and TLS handshake included. Against a ClickHouse reached over
    | the internet that is no margin at all: one slow lookup on a worker lost the usage row it was
    | writing ("Resolving timed out after 1000 milliseconds"), and an analytics query past one
    | second failed the same way.
    |
    */

    'connection' => [
        'host' => env('CLICKHOUSE_HOST', 'localhost'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'username' => env('CLICKHOUSE_USER', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'options' => [
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            // Whole seconds: the client truncates it to an int for max_execution_time.
            'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 5),
            // Lookup plus TCP/TLS connect; the containers retry a lost DNS packet after 1s.
            'connectTimeOut' => (float) env('CLICKHOUSE_CONNECT_TIMEOUT', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Migration Settings
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => env('CLICKHOUSE_MIGRATION_TABLE', 'migrations'),
        'path' => database_path('clickhouse-migrations'),
    ],
];
