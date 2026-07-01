<?php

return [
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'internal' => [
        'url' =>   env('INTERNAL_API_URL', config('app.url')) . '/api/internal',
        'secret' => env('INTERNAL_API_SECRET'),
    ],
];
