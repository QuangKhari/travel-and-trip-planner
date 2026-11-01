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
                    $m[$i][$j] = ['km' => 0.0, 'min' => 0];
                    continue;
                }
                if ($j < $i) {
                    $m[$i][$j] = $m[$j][$i];
                    continue;
                }
                $km = Geo::distanceKm($pts[$i][0], $pts[$i][1], $pts[$j][0], $pts[$j][1]) * $this->factor;
                $m[$i][$j] = ['km' => $km, 'min' => (int) round($km / $this->speedKmh * 60)];
            }
        }

        return $m;
    }

    /** Khoảng cách đường chim bay (km), dùng cho quy tắc "cùng một khu" (< 0,5 km thì không tính đoạn đi). */
    public static function straightKm(array $a, array $b): float
    {
        return Geo::distanceKm($a[0], $a[1], $b[0], $b[1]);
    }
}
