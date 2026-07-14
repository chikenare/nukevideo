<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\CdnSettingsController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\NodeEnvironmentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SshKeyController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\BandwidthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MyCustomUppyController;
use App\Http\Controllers\ProjectApiKeyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\VodController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\VerifyInternalSecret;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.project'])->group(function () {
    // Account-wide: these span every project (or the whole instance), so a project API key has no
    // business here — usage and analytics are keyed by user in ClickHouse, not by project.
    Route::middleware('no-project-key')->group(function () {
        Route::get('me', MeController::class);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);

        Route::post('projects/{project}/api-key', ProjectApiKeyController::class);
        Route::apiResource('projects', ProjectController::class);

        Route::get('tokens', [ApiTokenController::class, 'index']);
        Route::post('tokens', [ApiTokenController::class, 'store']);
        Route::delete('tokens/{id}', [ApiTokenController::class, 'destroy']);

        Route::get('usage', [UsageController::class, 'index']);
        Route::get('analytics', [AnalyticsController::class, 'index']);
        Route::get('analytics/queue', [AnalyticsController::class, 'queueStatus']);
    });

    // Templates
    Route::get('templates-config', [TemplateController::class, 'getConfig']);
    Route::get('template-presets', [TemplateController::class, 'presets']);
    Route::post('template-presets/{slug}/adopt', [TemplateController::class, 'adoptPreset']);
    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates', [TemplateController::class, 'store']);
    Route::get('templates/{template}', [TemplateController::class, 'show']);
    Route::match(['put', 'patch'], 'templates/{template}', [TemplateController::class, 'update']);
    Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

    // Videos
    Route::get('videos', [VideoController::class, 'index']);
    Route::get('videos/{video}', [VideoController::class, 'show']);
    Route::match(['put', 'patch'], 'videos/{video}', [VideoController::class, 'update']);
    Route::delete('videos/{video}', [VideoController::class, 'destroy']);

    // Streams
    Route::delete('streams/{stream}', [StreamController::class, 'destroy']);

    // Activity log (scoped to the project's videos)
    Route::get('activity-log', [ActivityLogController::class, 'index']);

    // Upload (S3) — project viene por metadata (Uppy no pasa por nuestro axios interceptor)
    Route::get('s3/params', [MyCustomUppyController::class, 'getUploadParameters']);
    Route::post('s3/multipart', [MyCustomUppyController::class, 'createMultipartUpload']);
    Route::get('s3/multipart/{uploadId}', [MyCustomUppyController::class, 'getUploadedParts']);
    Route::post('s3/multipart/{uploadId}/complete', [MyCustomUppyController::class, 'completeMultipartUpload']);
    Route::delete('s3/multipart/{uploadId}', [MyCustomUppyController::class, 'abortMultipartUpload']);
    Route::get('s3/multipart/{uploadId}/{partNumber}', [MyCustomUppyController::class, 'signPartUpload']);

    // Admin
    Route::middleware(['no-project-key', EnsureAdmin::class])->group(function () {
        Route::apiResource('ssh-keys', SshKeyController::class)->except(['update']);

        Route::apiResource('nodes', NodeController::class);
        Route::post('nodes/{node}/deploy', [NodeController::class, 'deploy']);
        Route::post('nodes/{node}/validate', [NodeController::class, 'validateNode']);
        Route::post('nodes/{node}/bootstrap-token', [NodeController::class, 'generateBootstrapToken']);

        Route::get('node-environment', [NodeEnvironmentController::class, 'show']);
        Route::patch('node-environment', [NodeEnvironmentController::class, 'update']);

        Route::get('cdn-settings', [CdnSettingsController::class, 'show']);
        Route::patch('cdn-settings', [CdnSettingsController::class, 'update']);

        Route::apiResource('users', UserController::class);

        Route::get('settings/version', [SettingsController::class, 'versionCheck']);
    });
});

// Public
Route::get('nodes/{node}/bootstrap', [NodeController::class, 'bootstrapScript'])
    ->middleware('signed')->name('nodes.bootstrap');

// Webhooks
Route::post('webhooks/video-uploaded', [VideoWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);

// Bandwidth ingest (Vector -> queue -> ClickHouse)
Route::post('internal/bandwidth', [BandwidthController::class, 'ingest'])
    ->middleware(VerifyInternalSecret::class);

// VOD — playback link, scoped to the caller's project like every other resource route.
Route::post('outputs/{ulid}', [VodController::class, 'getOutputLink'])
    ->middleware(['auth:sanctum', 'resolve.project']);

Route::get('/videos/{ulid}/{filename}', [VideoController::class, 'getAsset'])
    ->where('filename', 'storyboard(_\d+)?\.(vtt|jpg)|thumbnail\.jpg')
    ->withoutMiddleware(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class)
    ->middleware('cache.headers:public;max_age=604800;s_maxage=604800;etag');
