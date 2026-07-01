<?php

return [
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'internal' => [
        'secret' => env('INTERNAL_API_SECRET'),
    ],
];
