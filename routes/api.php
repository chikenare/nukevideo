<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SshKeyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MyCustomUppyController;
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
    Route::get('me', MeController::class);

    // Profile
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    // API Tokens
    Route::get('tokens', [ApiTokenController::class, 'index']);
    Route::post('tokens', [ApiTokenController::class, 'store']);
    Route::delete('tokens/{id}', [ApiTokenController::class, 'destroy']);

    Route::get('/templates-config', [TemplateController::class, 'getConfig']);
    Route::get('/template-presets', [TemplateController::class, 'presets']);
    Route::post('/template-presets/{slug}/adopt', [TemplateController::class, 'adoptPreset']);
    Route::apiResource('templates', TemplateController::class);

    Route::apiResource('/videos', VideoController::class)->except(['store']);
    Route::apiResource('/streams', StreamController::class)->except(['show', 'index']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index']);

    // Activity Log
    Route::get('activity-log', [ActivityLogController::class, 'index']);

    // Admin
    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::apiResource('ssh-keys', SshKeyController::class)->except(['update']);

        Route::get('nodes/metrics', [NodeController::class, 'metrics']);
        Route::apiResource('nodes', NodeController::class);
        Route::get('nodes/{node}/containers', [NodeController::class, 'containers']);
        Route::get('nodes/{node}/pending-jobs', [NodeController::class, 'pendingJobs']);
        Route::get('nodes/{node}/deploy/steps', [NodeController::class, 'deploySteps']);
        Route::post('nodes/{node}/deploy', [NodeController::class, 'deploy']);

        Route::apiResource('users', UserController::class);
    });
});

Route::post('webhooks/video-uploaded', [VideoWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);

// Upload
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/s3/params', [MyCustomUppyController::class, 'getUploadParameters']);
    Route::post('/s3/multipart', [MyCustomUppyController::class, 'createMultipartUpload']);
    Route::get('/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'getUploadedParts']);
    Route::post('/s3/multipart/{uploadId}/complete', [UppyS3MultipartController::class, 'completeMultipartUpload']);
    Route::delete('/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'abortMultipartUpload']);
    Route::get('/s3/multipart/{uploadId}/{partNumber}', [UppyS3MultipartController::class, 'signPartUpload']);

});

// VOD
Route::get('/vod/config/{ulid}', [VodController::class, 'getConfig']);
Route::get('/videos/{ulid}/sources', [VodController::class, 'getSources'])->middleware('auth:sanctum');