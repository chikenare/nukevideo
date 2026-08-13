<?php

return [
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'internal' => [
        'url' => env('INTERNAL_API_URL', config('app.url')).'/api/internal',
        'secret' => env('INTERNAL_API_SECRET'),
    ],

    // Where node images live. Unset in production, which leaves the Docker Hub namespace the
    // released images are published under. Point it at a registry host (`10.0.0.240:5000`) and that
    // host becomes part of the image name — which is how docker knows where to pull from, and what
    // lets a development build reach a test node without going anywhere public.
    'registry' => env('DOCKER_REGISTRY'),

    'video' => [
        // Per-chunk worker budget (seconds); mirrors the Horizon video supervisor timeout and is
        // exported to worker nodes by NodeService. Read via config so it survives config:cache.
        'worker_timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),

        // There is deliberately no `concurrent` here any more. How many videos may be in flight is
        // derived from the active nodes of each hardware family
        // ({@see \App\Console\Commands\DispatchPendingVideosCommand}), because a fixed number
        // drifts silently the moment the fleet changes.
    ],
];
