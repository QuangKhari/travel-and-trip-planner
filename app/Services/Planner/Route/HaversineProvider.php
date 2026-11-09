<?php

namespace App\Services\Planner\Route;

use App\Support\Geo;

/**
 * Ước tính không cần dịch vụ ngoài: đường chim bay × hệ số đường vòng, tốc độ trung bình.
 * Cùng công thức với planner-geo.js; hệ số và tốc độ đến từ PlannerRules::route() (trình duyệt nhận cùng số qua cfg.rules.route).
 */
class HaversineProvider implements RouteProvider
{
    public function __construct(private float $factor = 1.3, private float $speedKmh = 40.0) {}

    public function name(): string
    {
        return 'haversine';
    }

    public function speedKmh(): float
    {
        return $this->speedKmh;
    }

    public function matrix(array $pts): array
    {
        $n = count($pts);
        $m = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    $m[$i][$j] = ['km' => 0.0, 'min' => 0, 'status' => self::ESTIMATED];
                    continue;
                }
                if ($j < $i) {
                    $m[$i][$j] = $m[$j][$i];
                    continue;
                }
                if (!self::validPoint($pts[$i]) || !self::validPoint($pts[$j])) {
                    $m[$i][$j] = ['km' => 0.0, 'min' => 0, 'status' => self::UNKNOWN];   // không tính được, KHÔNG phải "0 phút"
                    continue;
                }
                $km = Geo::distanceKm($pts[$i][0], $pts[$i][1], $pts[$j][0], $pts[$j][1]) * $this->factor;
                $m[$i][$j] = ['km' => $km, 'min' => (int) round($km / $this->speedKmh * 60), 'status' => self::ESTIMATED];
            }
        }

        return $m;
    }

    /** Tọa độ dùng được: số, trong phạm vi, không phải (0,0) (giá trị mặc định khi geocode hỏng). */
    public static function validPoint(?array $p): bool
    {
        if (!$p || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) {
            return false;
        }
        $lat = (float) $p[0];
        $lng = (float) $p[1];

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !(abs($lat) < 1e-6 && abs($lng) < 1e-6);
    }

    /** Khoảng cách đường chim bay (km), dùng cho quy tắc "cùng một khu" (< 0,5 km thì không tính đoạn đi). */
    public static function straightKm(array $a, array $b): float
    {
        return Geo::distanceKm($a[0], $a[1], $b[0], $b[1]);
    }
}
