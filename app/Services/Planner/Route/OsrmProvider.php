<?php

namespace App\Services\Planner\Route;

use App\Services\Planner\ExternalGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Đường bộ thật qua máy chủ OSRM (tự dựng hoặc router.project-osrm.org), dịch vụ /table.
 * Lỗi mạng / quá giới hạn thì trả về ước tính Haversine (status = estimated), Planner không bao giờ hỏng vì dịch vụ ngoài.
 * OSRM khẳng định không có đường (code NoRoute, hoặc ô null trong /table) -> status = unreachable, KHÔNG đổi thành 0 phút
 * và cũng không giả làm "ước tính": km/min của ô đó là số chim bay chỉ để tham khảo.
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
        // Tọa độ hỏng ((0,0), ngoài phạm vi) KHÔNG được gửi sang OSRM: OSRM sẽ "bắt dính" vào một con đường xa và trả số sai.
        // Tính phần hợp lệ, ô nào chạm điểm hỏng là unknown.
        $valid = array_keys(array_filter($pts, fn($p) => HaversineProvider::validPoint($p)));
        if (count($valid) < count($pts)) {
            $sub = count($valid) >= 2 ? $this->matrix(array_values(array_intersect_key($pts, array_flip($valid)))) : [];
            $pos = array_flip($valid);   // chỉ số gốc -> chỉ số trong ma trận con
            $m = [];
            foreach (array_keys($pts) as $i) {
                foreach (array_keys($pts) as $k) {
                    $m[$i][$k] = $i === $k ? ['km' => 0.0, 'min' => 0, 'status' => self::ESTIMATED]
                        : (isset($pos[$i], $pos[$k]) ? $sub[$pos[$i]][$pos[$k]] : ['km' => 0.0, 'min' => 0, 'status' => self::UNKNOWN]);
                }
            }

            return $m;
        }
        if (count($pts) > 50) {
            return $this->useFallback($pts, 'quá 50 điểm');
        }
        $coords = implode(';', array_map(fn($p) => round($p[1], 6) . ',' . round($p[0], 6), $pts));
        $key = 'osrm:table:' . $this->profile . ':' . md5($coords);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return RouteService::withStatus($hit, self::VERIFIED);   // bản lưu cũ chưa có status = kết quả thật của OSRM
        }
        if (!ExternalGuard::allowed('osrm')) {
            return $this->useFallback($pts, 'OSRM vừa lỗi, tạm dùng ước tính (' . (ExternalGuard::reason('osrm') ?? 'tắt') . ')');
        }
        try {
            $r = Http::connectTimeout(2)->timeout(min($this->timeout, 4))->get(rtrim($this->baseUrl, '/') . '/table/v1/' . $this->profile . '/' . $coords, ['annotations' => 'distance,duration']);
            $j = $r->json();
            if (($j['code'] ?? '') === 'NoRoute') {
                $m = $this->markAll($this->fallback->matrix($pts), self::UNREACHABLE);
                Cache::put($key, $m, now()->addDay());   // đây là câu trả lời thật của OSRM, không phải lỗi

                return $m;
            }
            if (!$r->ok() || ($j['code'] ?? '') !== 'Ok') {
                if ($r->status() === 429 || $r->serverError()) {
                    ExternalGuard::trip('osrm', 'HTTP ' . $r->status());
                }

                return $this->useFallback($pts, 'OSRM trả về ' . ($j['code'] ?? $r->status()));
            }
            $m = [];
            $est = null;
            foreach ($j['distances'] as $i => $row) {
                foreach ($row as $k => $dist) {
                    $dur = $j['durations'][$i][$k] ?? null;
                    if ($i !== $k && ($dist === null || $dur === null)) {
                        // OSRM không tìm được đường giữa hai điểm: giữ số ước tính để hiển thị, đánh dấu unreachable
                        $est ??= $this->fallback->matrix($pts);
                        $m[$i][$k] = ['km' => $est[$i][$k]['km'], 'min' => $est[$i][$k]['min'], 'status' => self::UNREACHABLE];
                        continue;
                    }
                    $m[$i][$k] = ['km' => ($dist ?? 0) / 1000, 'min' => (int) round(($dur ?? 0) / 60), 'status' => self::VERIFIED];
                }
            }
            Cache::put($key, $m, now()->addDay());   // chỉ lưu kết quả THẬT; lỗi không bị lưu nên lần sau thử lại

            return $m;
        } catch (\Throwable $e) {
            ExternalGuard::trip('osrm', 'không kết nối được');   // không thử lại ở mỗi ngày / mỗi lần tải sức khỏe

            return $this->useFallback($pts, 'không kết nối được OSRM');
        }
    }

    private function markAll(array $m, string $status): array
    {
        foreach ($m as $i => $row) {
            foreach ($row as $k => $cell) {
                if ($i !== $k && ($cell['status'] ?? null) !== self::UNKNOWN) {
                    $m[$i][$k]['status'] = $status;
                }
            }
        }

        return $m;
    }

    private function useFallback(array $pts, string $why): array
    {
        $this->fellBack = true;
        $this->lastError = $why;

        return $this->fallback->matrix($pts);
    }
}
