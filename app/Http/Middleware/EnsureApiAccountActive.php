<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        /** @var Account|null $account */
        $account = $guard->user();

        // Token còn hạn nhưng tài khoản đã bị chặn/xóa sau khi cấp: thu hồi ngay
        if (!$account || !$account->isUsable()) {
            try {
                $guard->invalidate();
            } catch (\Throwable $e) {
                // token có thể đã bị vô hiệu; không cần làm gì thêm
            }

            return response()->json(['message' => 'Tài khoản không còn quyền truy cập.'], 403);
        }

        return $next($request);
    }
}
