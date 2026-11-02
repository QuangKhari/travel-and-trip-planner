<?php

namespace App\Http\Controllers;

use App\Support\SystemHealth;

/**
 * GET /health (PF-08): 200 khi mọi thành phần bắt buộc ổn, 503 khi CSDL / cache hỏng.
 * Không lộ thông tin cấu hình; chỉ trạng thái và độ trễ. Dùng cho healthcheck Docker và giám sát.
 */
class HealthController extends Controller
{
    public function __invoke()
    {
        $r = SystemHealth::check();

        return response()->json([
            'status' => $r['ok'] ? 'ok' : 'fail',
            'time' => now()->toIso8601String(),
            'checks' => collect($r['checks'])->map(fn($c) => ['ok' => $c['ok'], 'ms' => $c['ms']])->all(),
        ], $r['ok'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
