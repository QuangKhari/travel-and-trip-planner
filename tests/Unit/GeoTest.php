<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_khoang_cach_ha_noi_hue_khoang_540_km(): void
    {
        $km = Geo::distanceKm(21.0285, 105.8542, 16.4637, 107.5909);
        $this->assertEqualsWithDelta(540, $km, 15);
        $this->assertEqualsWithDelta(0.0, Geo::distanceKm(10, 106, 10, 106), 1e-9);
    }

    public function test_khung_toa_do_chua_diem_trong_ban_kinh(): void
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::bbox(21.0, 105.8, 10);
        $this->assertGreaterThan(21.0, $maxLat);
        $this->assertLessThan(21.0, $minLat);
        // điểm cách 9 km về phía đông phải nằm trong khung
        $east = 105.8 + 9 / (111.32 * cos(deg2rad(21.0)));
        $this->assertTrue($east > $minLng && $east < $maxLng);
    }
}
