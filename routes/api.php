<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\NodeEnvironmentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SshKeyController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MyCustomUppyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\VodController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;
use Tapp\LaravelUppyS3MultipartUpload\Http\Controllers\UppyS3MultipartController;

Route::middleware('auth:sanctum')->group(function () {
    // Me & Profile
    Route::get('me', MeController::class);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    // Projects
    Route::apiResource('projects', ProjectController::class);

    // API Tokens
    Route::get('tokens', [ApiTokenController::class, 'index']);
    Route::post('tokens', [ApiTokenController::class, 'store']);
    Route::delete('tokens/{id}', [ApiTokenController::class, 'destroy']);

    // Templates
    Route::get('templates-config', [TemplateController::class, 'getConfig']);
    Route::get('template-presets', [TemplateController::class, 'presets']);
    Route::post('template-presets/{slug}/adopt', [TemplateController::class, 'adoptPreset'])->middleware('resolve.project');
    Route::get('templates', [TemplateController::class, 'index'])->middleware('resolve.project');
    Route::post('templates', [TemplateController::class, 'store'])->middleware('resolve.project');
    Route::get('templates/{template}', [TemplateController::class, 'show']);
    Route::match(['put', 'patch'], 'templates/{template}', [TemplateController::class, 'update']);
    Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

    // Videos
    Route::get('videos', [VideoController::class, 'index'])->middleware('resolve.project');
    Route::get('videos/{video}', [VideoController::class, 'show']);
    Route::match(['put', 'patch'], 'videos/{video}', [VideoController::class, 'update']);
    Route::delete('videos/{video}', [VideoController::class, 'destroy']);

    // Streams
    Route::apiResource('streams', StreamController::class)->except(['show', 'index']);

    // Usage / Analytics / Activity Log
    Route::get('usage', [UsageController::class, 'index'])->middleware('resolve.project');
    Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('resolve.project');
    Route::get('analytics/queue', [AnalyticsController::class, 'queueStatus']);
    Route::get('activity-log', [ActivityLogController::class, 'index'])->middleware('resolve.project');

    // Upload (S3) — project viene por metadata (Uppy no pasa por nuestro axios interceptor)
    Route::get('s3/params', [MyCustomUppyController::class, 'getUploadParameters']);
    Route::post('s3/multipart', [MyCustomUppyController::class, 'createMultipartUpload']);
    Route::get('s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'getUploadedParts']);
    Route::post('s3/multipart/{uploadId}/complete', [UppyS3MultipartController::class, 'completeMultipartUpload']);
    Route::delete('s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'abortMultipartUpload']);
    Route::get('s3/multipart/{uploadId}/{partNumber}', [UppyS3MultipartController::class, 'signPartUpload']);

    // Admin
    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::apiResource('ssh-keys', SshKeyController::class)->except(['update']);

        Route::get('nodes/metrics', [NodeController::class, 'metrics']);
        Route::apiResource('nodes', NodeController::class);
        Route::get('nodes/{node}/containers', [NodeController::class, 'containers']);
        Route::get('nodes/{node}/pending-jobs', [NodeController::class, 'pendingJobs']);
        Route::post('nodes/{node}/deploy', [NodeController::class, 'deploy']);
        Route::post('nodes/{node}/setup', [NodeController::class, 'setup']);
        Route::post('nodes/{node}/validate', [NodeController::class, 'validateNode']);

        Route::get('node-environment', [NodeEnvironmentController::class, 'show']);
        Route::patch('node-environment', [NodeEnvironmentController::class, 'update']);

        Route::apiResource('users', UserController::class);

        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings', [SettingsController::class, 'update']);
        Route::get('settings/version', [SettingsController::class, 'versionCheck']);
    });
});

// Public
Route::get('settings/public', [SettingsController::class, 'publicSettings']);

// Webhooks
Route::post('webhooks/video-uploaded', [VideoWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);

// VOD
Route::get('vod/config/{id}/{session}', [VodController::class, 'getConfig']);
Route::get('videos/{ulid}/outputs', [VodController::class, 'getOutputs'])->middleware('auth:sanctum');
Route::get('videos/{ulid}/subtitles', [VodController::class, 'subtitles'])->middleware('auth:sanctum');
