<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'checkLoginClient' => \App\Http\Middleware\CheckLoggedInClients::class,
            'checkUserBlocked' => \App\Http\Middleware\CheckUserBlocked::class,
            'checkAdminRole'   => \App\Http\Middleware\CheckAdminRole::class,
            'api.active'       => \App\Http\Middleware\EnsureApiAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mọi route /api/* luôn trả JSON, kể cả khi client quên gửi header Accept
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
