<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\InspirationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SeasonController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\SearchController as ApiSearchController;
use App\Http\Controllers\Api\V1\PlannerPreviewController;
use App\Http\Controllers\Api\V1\AskController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\AdviceController;


Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1,api-login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1,api-refresh');

        Route::middleware(['auth:api', 'api.active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Dữ liệu công khai "Khám phá theo cảm hứng" (đọc từ DB, đã nạp sẵn bằng lệnh discover:*)
    Route::middleware('throttle:60,1,api-discover')->group(function () {
        Route::prefix('inspirations')->group(function () {
            Route::get('/', [InspirationController::class, 'index']);
            Route::get('/{slug}', [InspirationController::class, 'show'])->where('slug', '[a-z0-9-]+');
            Route::get('/{slug}/destinations', [InspirationController::class, 'destinations'])->where('slug', '[a-z0-9-]+');
        });

        Route::prefix('destinations')->group(function () {
            Route::get('/', [DestinationController::class, 'index']);
            Route::get('/{slug}', [DestinationController::class, 'show'])->where('slug', '[a-z0-9-]+');
            Route::get('/{slug}/weather', [DestinationController::class, 'weather'])->where('slug', '[a-z0-9-]+');
            Route::get('/{slug}/places', [DestinationController::class, 'places'])->where('slug', '[a-z0-9-]+');
        });

        Route::get('seasons', [SeasonController::class, 'index']);
        Route::get('seasons/matrix', [SeasonController::class, 'matrix']);   // C2: ma trận điểm đến x 12 tháng
        Route::get('planner-preview', [PlannerPreviewController::class, 'index']);   // D4: xem trước Planner ở trang chủ

        // A10: nội dung lớp Khám phá (chỉ published)
        Route::get('destinations/{slug}/months', [ContentController::class, 'months'])->where('slug', '[a-z0-9-]+');
        Route::get('destinations/{slug}/advice', [AdviceController::class, 'show'])->where('slug', '[a-z0-9-]+');   // Thời điểm đi
        Route::get('destinations/{slug}/foods', [ContentController::class, 'destinationFoods'])->where('slug', '[a-z0-9-]+');
        Route::get('destinations/{slug}/events', [ContentController::class, 'destinationEvents'])->where('slug', '[a-z0-9-]+');
        Route::get('foods', [ContentController::class, 'foods']);
        Route::get('events', [ContentController::class, 'events']);
        Route::get('guides', [ContentController::class, 'guides']);
        Route::get('guides/{slug}', [ContentController::class, 'guide'])->where('slug', '[a-z0-9-]+');
        Route::get('itineraries', [ContentController::class, 'itineraries']);
        Route::get('itineraries/{slug}', [ContentController::class, 'itinerary'])->where('slug', '[a-z0-9-]+');
    });

    // C3: tìm kiếm toàn lớp Khám phá. Giới hạn riêng vì gợi ý khi gõ gọi nhiều lần.
    Route::middleware('throttle:120,1,api-search')->group(function () {
        Route::get('search', [ApiSearchController::class, 'index']);
        Route::get('search/suggest', [ApiSearchController::class, 'suggest']);
    });

    Route::post('ask', [AskController::class, 'ask'])->middleware(['auth:api', 'api.active', 'throttle:10,1,api-ask']);

    // Trip Planner (tuần 5-7): chỉ chủ chuyến mới đọc/sửa/xóa được
    Route::middleware(['auth:api', 'api.active', 'throttle:60,1,api-trips'])->group(function () {
        Route::get('trips', [TripController::class, 'index']);
        Route::post('trips', [TripController::class, 'store']);
        Route::get('trips/{id}', [TripController::class, 'show'])->whereNumber('id');
        Route::put('trips/{id}', [TripController::class, 'update'])->whereNumber('id');
        Route::delete('trips/{id}', [TripController::class, 'destroy'])->whereNumber('id');
    });
});
