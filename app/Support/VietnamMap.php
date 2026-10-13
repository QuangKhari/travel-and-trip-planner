<?php

namespace App\Support;

/**
 * Đổi toạ độ (vĩ độ, kinh độ) sang % trong khung bản đồ SVG ở
 * resources/views/clients/partials/vn-map-svg.blade.php.
 * Phép chiếu Mercator, khung: kinh độ 102–116, vĩ độ 7.6–23.6. Đổi khung thì phải sinh lại SVG.
 */
class VietnamMap
{
    public const LNG_MIN = 102.0;
    public const LNG_MAX = 116.0;
    public const LAT_MIN = 7.6;
    public const LAT_MAX = 23.6;

    /** @return array{0: float, 1: float} [x%, y%] */
    public static function project(float $lat, float $lng): array
    {
        $y = static fn(float $p): float => log(tan(M_PI / 4 + deg2rad($p) / 2));

        $x = ($lng - self::LNG_MIN) / (self::LNG_MAX - self::LNG_MIN);
        $v = ($y(self::LAT_MAX) - $y($lat)) / ($y(self::LAT_MAX) - $y(self::LAT_MIN));

        return [round($x * 100, 2), round($v * 100, 2)];
    }
}
