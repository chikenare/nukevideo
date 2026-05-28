<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'Hello world';
});

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout']);

Route::get('/videos/{ulid}/{filename}', [VideoController::class, 'getAsset'])
    ->where('filename', 'storyboard(_\d+)?\.(vtt|jpg)|thumbnail\.jpg')
    ->withoutMiddleware('web');
