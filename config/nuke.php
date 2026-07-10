<?php

return [
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'internal' => [
        'url' => env('INTERNAL_API_URL', config('app.url')).'/api/internal',
        'secret' => env('INTERNAL_API_SECRET'),
    ],

    'video' => [
        // Per-chunk worker budget (seconds); mirrors the Horizon video supervisor timeout and is
        // exported to worker nodes by NodeService. Read via config so it survives config:cache.
        'worker_timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),
    ],
];
