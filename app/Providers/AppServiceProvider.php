<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Nguồn tính tuyến của Planner: haversine (mặc định) hoặc osrm, chọn trong config/planner.php
        $this->app->singleton(\App\Services\Planner\Route\RouteService::class, fn() => \App\Services\Planner\Route\RouteService::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
