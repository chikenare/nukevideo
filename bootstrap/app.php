<?php

use App\Http\Middleware\DenyProjectKey;
use App\Http\Middleware\ResolveProject;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // No `throttleApi()`, deliberately. A rate limit on the whole api group also lands on the
        // S3 multipart upload routes, which sign ONE request per part — a 5 GB source in 5 MB parts
        // is a thousand of them for a single upload — so any per-minute ceiling that leaves room
        // for uploading breaks nothing else, and any ceiling that protects anything breaks uploads.
        // Rate limiting, if it is wanted, belongs on the specific routes that need it.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'resolve.project' => ResolveProject::class,
            'no-project-key' => DenyProjectKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    })->create();
