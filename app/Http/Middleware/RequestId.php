<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PF-08: mỗi request một mã X-Request-Id (nhận từ nginx nếu có, không thì tự sinh).
 * Mã được gắn vào mọi dòng log của request (Context) và trả lại trong header để đối chiếu log nginx / Laravel / báo lỗi của khách.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->headers->get('X-Request-Id', '');
        if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $id)) {
            $id = (string) Str::uuid();
        }
        Context::add('requestId', $id);

        // J1: đếm số truy vấn SQL mỗi request (header X-Db-Queries) để báo cáo đo tải. Chỉ bật ngoài production.
        $count = config('app.query_count_header') && !app()->isProduction();
        $n = 0;
        if ($count) {
            \Illuminate\Support\Facades\DB::listen(function () use (&$n) {
                $n++;
            });
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        if ($count) {
            $response->headers->set('X-Db-Queries', (string) $n);
        }

        return $response;
    }
}
