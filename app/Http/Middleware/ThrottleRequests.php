<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as Base;

/**
 * Giới hạn lượt gọi như mặc định của Laravel; riêng khi đo tải (J1) có thể tắt bằng LOADTEST_NO_THROTTLE=true.
 * Công tắc KHÔNG có hiệu lực ở APP_ENV=production, nên không thể vô tình mở trên máy thật.
 * Lý do: k6 chạy từ một máy (một IP) nên mọi giới hạn "N lượt / phút / IP" sẽ trả 429 và làm sai số đo.
 *
 * Đợt F: đếm theo NGƯỜI DÙNG đã đăng nhập (userId / tài khoản quản trị trong phiên) thay vì theo IP.
 * Travela đăng nhập bằng phiên riêng (không qua Auth của Laravel) nên trước đây mọi người cùng một mạng (phòng thử, Wi-Fi
 * trường, NAT nhà mạng di động) dùng CHUNG một hạn mức: 50 người trong một phòng chỉ có 60 lượt "thông báo chuyến" / phút.
 * Chưa đăng nhập (đăng nhập, quên mật khẩu, trang công khai) vẫn đếm theo IP như cũ.
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

    protected function resolveRequestSignature($request)
    {
        if ($request->hasSession()) {
            $s = $request->session();
            if ($uid = (int) $s->get('userId')) {
                return sha1('user|' . $uid);
            }
            if ($admin = (string) $s->get('admin')) {
                return sha1('admin|' . $admin);
            }
        }

        return parent::resolveRequestSignature($request);
    }
}
