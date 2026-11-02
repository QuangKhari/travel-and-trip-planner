<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as Base;

/**
 * Giới hạn lượt gọi như mặc định của Laravel; riêng khi đo tải (J1) có thể tắt bằng LOADTEST_NO_THROTTLE=true.
 * Công tắc KHÔNG có hiệu lực ở APP_ENV=production, nên không thể vô tình mở trên máy thật.
 * Lý do: k6 chạy từ một máy (một IP) nên mọi giới hạn "N lượt / phút / IP" sẽ trả 429 và làm sai số đo.
 */
class ThrottleRequests extends Base
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (config('app.loadtest_no_throttle') && !app()->isProduction()) {
            return $next($request);
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
