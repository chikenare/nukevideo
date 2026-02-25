<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MyCustomUppyController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\VodController;
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
    Route::apiResource('templates', TemplateController::class);

    // Node Management API
    Route::apiResource('nodes', \App\Http\Controllers\Api\NodeController::class);
    Route::get('nodes/{node}/metrics', [\App\Http\Controllers\Api\NodeController::class, 'metrics']);

    Route::apiResource('/videos', VideoController::class)->except(['store']);
    Route::apiResource('/streams', StreamController::class)->except(['show', 'index']);
});

Route::post('webhooks/video-uploaded', [VideoWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);


//Upload
Route::get('/s3/params', [MyCustomUppyController::class, 'getUploadParameters'])
    ->middleware('auth:sanctum');
Route::post('/s3/multipart', [MyCustomUppyController::class, 'createMultipartUpload'])
    ->middleware('auth:sanctum');
Route::get('/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'getUploadedParts']);
Route::post('/s3/multipart/{uploadId}/complete', [UppyS3MultipartController::class, 'completeMultipartUpload']);
Route::delete('/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'abortMultipartUpload']);
Route::get('/s3/multipart/{uploadId}/{partNumber}', [UppyS3MultipartController::class, 'signPartUpload']);

//VOD
Route::get('/vod/config/{ulid}', [VodController::class, 'getConfig']);
Route::get('/vod/link/{ulid}', [VodController::class, 'getLink']);
