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

        // How many videos may be in flight at once, fleet-wide.
        //
        // NOT the node count. A single video already fans out across every worker — that is what
        // chunking is for — so this does not gate parallelism, it gates how deep the queue gets.
        // Every CPU node drains one shared `video-processing` list, FIFO: dispatch a 4K feature
        // and a three-minute clip together and the clip's twenty jobs sit behind the feature's
        // fifteen hundred for hours, with nothing beating its heartbeat, until `videos:reap` calls
        // it dead. Keep it low; raise it only if short videos routinely leave the fleet idle.
        'concurrent' => max(1, (int) env('VIDEO_CONCURRENT', 2)),
    ],
];
