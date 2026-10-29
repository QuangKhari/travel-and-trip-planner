<?php

namespace Tests\Feature;

use App\Services\Planner\MapStyle;
use Tests\TestCase;

/** Chọn style bản đồ vector theo .env: tùy chỉnh > MapTiler > Goong > OpenFreeMap. */
class MapStyleTest extends TestCase
{
    private function setMap(array $over = []): void
    {
        config(['services.map' => array_merge([
            'style_url' => null, 'maptiler_key' => null, 'maptiler_style' => 'streets-v2',
            'goong_maptiles_key' => null, 'free_style_url' => 'https://tiles.openfreemap.org/styles/liberty',
        ], $over)]);
    }

    public function test_khong_co_khoa_thi_dung_nen_mien_phi(): void
    {
        $this->setMap();
        $this->assertSame('https://tiles.openfreemap.org/styles/liberty', MapStyle::url());
    }

    public function test_maptiler_va_goong_theo_khoa(): void
    {
        $this->setMap(['maptiler_key' => 'k 1']);
        $this->assertSame('https://api.maptiler.com/maps/streets-v2/style.json?key=k%201', MapStyle::url());

        $this->setMap(['goong_maptiles_key' => 'g2']);
        $this->assertSame('https://tiles.goong.io/assets/goong_map_web.json?api_key=g2', MapStyle::url());
    }

    public function test_thu_tu_uu_tien_va_lam_sach_ten_style(): void
    {
        $this->setMap(['maptiler_key' => 'm', 'goong_maptiles_key' => 'g']);
        $this->assertStringContainsString('maptiler.com', MapStyle::url());

        $this->setMap(['maptiler_key' => 'm', 'maptiler_style' => '../x?y']);
        $this->assertStringContainsString('/maps/xy/style.json', MapStyle::url());

        $this->setMap(['maptiler_key' => 'm', 'style_url' => 'https://example.test/s.json']);
        $this->assertSame('https://example.test/s.json', MapStyle::url());
    }

    public function test_tat_han_khi_khong_cau_hinh_gi(): void
    {
        $this->setMap(['free_style_url' => '']);
        $this->assertNull(MapStyle::url());
    }
}
