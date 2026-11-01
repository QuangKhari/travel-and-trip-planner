<?php

namespace App\Services\Planner\Route;

use App\Services\Planner\ExternalGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Đường bộ thật qua máy chủ OSRM (tự dựng hoặc router.project-osrm.org), dịch vụ /table.
 * Lỗi mạng / quá giới hạn thì trả về ước tính Haversine, Planner không bao giờ hỏng vì dịch vụ ngoài.
 */
class OsrmProvider implements RouteProvider
{
    public function __construct(private string $baseUrl, private HaversineProvider $fallback, private int $timeout = 6, private string $profile = 'driving') {}

    public function speedKmh(): float
    {
        return $this->fallback->speedKmh();
    }

    public function name(): string
    {
        return 'osrm';
    }

    /** Đã phải dùng ước tính thay cho OSRM trong yêu cầu này (lỗi mạng, quá giới hạn, quá nhiều điểm). */
    private bool $fellBack = false;
    private ?string $lastError = null;

    public function fellBack(): bool
    {
        return $this->fellBack;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function matrix(array $pts): array
    {
        if (count($pts) < 2) {
            return $this->fallback->matrix($pts);   // không có đoạn nào: không tính là "dự phòng"
        }
        if (count($pts) > 50) {
            return $this->useFallback($pts, 'quá 50 điểm');
        }
        $coords = implode(';', array_map(fn($p) => round($p[1], 6) . ',' . round($p[0], 6), $pts));
        $key = 'osrm:table:' . $this->profile . ':' . md5($coords);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }
        if (!ExternalGuard::allowed('osrm')) {
            return $this->useFallback($pts, 'OSRM vừa lỗi, tạm dùng ước tính (' . (ExternalGuard::reason('osrm') ?? 'tắt') . ')');
        }
        try {
            $r = Http::connectTimeout(2)->timeout(min($this->timeout, 4))->get(rtrim($this->baseUrl, '/') . '/table/v1/' . $this->profile . '/' . $coords, ['annotations' => 'distance,duration']);
            $j = $r->json();
            if (!$r->ok() || ($j['code'] ?? '') !== 'Ok') {
                if ($r->status() === 429 || $r->serverError()) {
                    ExternalGuard::trip('osrm', 'HTTP ' . $r->status());
                }

                return $this->useFallback($pts, 'OSRM trả về ' . ($j['code'] ?? $r->status()));
            }
            $m = [];
            foreach ($j['distances'] as $i => $row) {
                foreach ($row as $k => $dist) {
                    $m[$i][$k] = ['km' => $dist === null ? 0.0 : $dist / 1000, 'min' => (int) round(($j['durations'][$i][$k] ?? 0) / 60)];
                }
            }
            Cache::put($key, $m, now()->addDay());   // chỉ lưu kết quả THẬT; lỗi không bị lưu nên lần sau thử lại

            return $m;
        } catch (\Throwable $e) {
            ExternalGuard::trip('osrm', 'không kết nối được');   // không thử lại ở mỗi ngày / mỗi lần tải sức khỏe

            return $this->useFallback($pts, 'không kết nối được OSRM');
        }
    }

    private function useFallback(array $pts, string $why): array
    {
        $this->fellBack = true;
        $this->lastError = $why;

        return $this->fallback->matrix($pts);
    }
}
