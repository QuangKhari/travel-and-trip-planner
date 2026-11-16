<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Đợt F (F7, OWASP A05): header bảo mật ngay trong ứng dụng, để đúng cả khi chạy sau nginx (docker), Apache (.htaccess)
 * hay `artisan serve`. nginx trong docker cũng đặt ba header đầu; trùng giá trị nên không sao.
 * Chưa đặt Content-Security-Policy đầy đủ: trang còn script nội tuyến và bản đồ / ảnh từ nguồn ngoài; chỉ chặn nhúng trang
 * vào khung của site khác (frame-ancestors). HSTS chỉ gửi khi yêu cầu đi qua HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $res = $next($request);
        $h = $res->headers;
        $set = fn(string $k, string $v) => $h->has($k) ? null : $h->set($k, $v);
        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()');
        $set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        if ($request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $res;
    }
}
