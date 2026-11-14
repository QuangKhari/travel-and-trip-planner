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
        // PF-08: mã request gắn vào log và header phản hồi
        $middleware->prepend(\App\Http\Middleware\RequestId::class);
        // tham số URL bị sửa thành mảng ở khóa vốn là chữ / số: bỏ đi thay vì để trang lỗi 500
        $middleware->append(\App\Http\Middleware\ScalarQuery::class);
        // chạy sau nginx / load balancer trong Docker: tin IP và giao thức do proxy gửi (để url() ra https và throttle đúng IP khách)
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        // cổng thanh toán gọi máy chủ (POST JSON, không có phiên / token CSRF); an toàn nhờ kiểm chữ ký từng thông báo
        $middleware->validateCsrfTokens(except: ['thanh-toan/thong-bao/*']);
        $middleware->alias([
            'checkLoginClient' => \App\Http\Middleware\CheckLoggedInClients::class,
            'checkUserBlocked' => \App\Http\Middleware\CheckUserBlocked::class,
            'checkAdminRole'   => \App\Http\Middleware\CheckAdminRole::class,
            'api.active'       => \App\Http\Middleware\EnsureApiAccountActive::class,
            'throttle'         => \App\Http\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mọi route /api/* luôn trả JSON, kể cả khi client quên gửi header Accept
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
