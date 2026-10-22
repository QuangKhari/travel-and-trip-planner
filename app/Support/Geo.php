<?php

namespace App\Support;

/** Khoảng cách và khung tọa độ (A13: lọc bằng khung trước, tính khoảng cách sau). */
final class Geo
{
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** @return array{0:float,1:float,2:float,3:float} minLat, maxLat, minLng, maxLng */
    public static function bbox(float $lat, float $lng, float $radiusKm): array
    {
        $dLat = $radiusKm / 111.32;
        $dLng = $radiusKm / (111.32 * max(0.01, cos(deg2rad($lat))));

        return [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
    }
}
