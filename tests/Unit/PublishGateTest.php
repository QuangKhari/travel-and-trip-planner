<?php

namespace Tests\Unit;

use App\Services\Content\PublishGate;
use PHPUnit\Framework\TestCase;

class PublishGateTest extends TestCase
{
    private function food(array $o = []): object
    {
        return (object) ($o + [
            'description' => 'Món bún chả Hà Nội ăn kèm nước chấm chua ngọt, thịt nướng than hoa.',
            'license' => 'CC BY-SA 4.0', 'sourceUrl' => 'https://vi.wikipedia.org/wiki/B%C3%BAn_ch%E1%BA%A3',
            'coverImage' => null, 'imageCredit' => null, 'destinationId' => 1,
        ]);
    }

    public function test_mon_du_dieu_kien(): void
    {
        $this->assertSame([], PublishGate::problems('foods', $this->food()));
    }

    public function test_thieu_giay_phep_nguon_anh_diem_den(): void
    {
        $this->assertContains('Thiếu giấy phép nội dung', PublishGate::problems('foods', $this->food(['license' => null])));
        $this->assertContains('Thiếu liên kết nguồn (sourceUrl)', PublishGate::problems('foods', $this->food(['sourceUrl' => null])));
        $this->assertSame([], PublishGate::problems('foods', $this->food(['license' => 'Tự viết', 'sourceUrl' => null]))); // tự viết không cần nguồn
        $this->assertContains('Có ảnh nhưng thiếu ghi công ảnh', PublishGate::problems('foods', $this->food(['coverImage' => 'discover/foods/x.webp'])));
        $this->assertContains('Món ăn phải gắn với một điểm đến', PublishGate::problems('foods', $this->food(['destinationId' => null])));
        $this->assertContains('Nội dung/mô tả quá ngắn (dưới 30 ký tự)', PublishGate::problems('foods', $this->food(['description' => 'ngắn'])));
    }

    public function test_le_hoi_can_quy_tac_ngay_tinh_duoc(): void
    {
        $base = ['description' => str_repeat('Lễ hội truyền thống. ', 3), 'license' => 'Tự viết', 'sourceUrl' => null, 'coverImage' => null, 'durationDays' => 1];
        $ok = (object) ($base + ['isLunar' => 1, 'lunarMonth' => 3, 'lunarDay' => 10]);
        $bad = (object) ($base + ['isLunar' => 1, 'lunarMonth' => null, 'lunarDay' => null, 'solarMonth' => null]);
        $this->assertSame([], PublishGate::problems('events', $ok));
        $this->assertNotSame([], PublishGate::problems('events', $bad));
    }

    public function test_dia_diem_chi_can_ten(): void
    {
        $this->assertSame([], PublishGate::problems('places', (object) ['name' => 'Chợ Đồng Xuân']));
        $this->assertSame(['Thiếu tên địa điểm'], PublishGate::problems('places', (object) ['name' => ' ']));
    }
}
