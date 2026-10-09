<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1,api-login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1,api-refresh');

        Route::middleware(['auth:api', 'api.active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Các endpoint Trip Planner / AI sau này đặt trong nhóm này để dùng chung xác thực:
    // Route::middleware(['auth:api', 'api.active'])->group(function () { ... });
});
