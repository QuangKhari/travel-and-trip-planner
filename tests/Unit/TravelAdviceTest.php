<?php

namespace Tests\Unit;

use App\Services\Content\TravelAdvice;
use PHPUnit\Framework\TestCase;

/** "Thời điểm đi": luật dựng lời khuyên từ số liệu khí hậu (hàm thuần, ngưỡng lấy từ config/travel_advice.php). */
class TravelAdviceTest extends TestCase
{
    private function cfg(): array
    {
        return require __DIR__ . '/../../config/travel_advice.php';
    }

    private function build(?array $m, string $level, int $month = 10): array
    {
        return TravelAdvice::build($m, $level, null, $month, $this->cfg());
    }

    public function test_thang_de_chiu_it_mua_duoc_5_sao_va_khong_canh_bao(): void
    {
        $a = $this->build(['tMax' => 29, 'tMin' => 22, 'rainMm' => 150, 'rainyDays' => 9], 'best');
        $this->assertSame(5, $a['stars']);
        $this->assertSame('Đẹp nhất', $a['label']);
        $this->assertSame([], $a['caution']);
        $this->assertContains('Quần áo nhẹ và một áo khoác mỏng cho buổi tối', $a['packing']);
        $this->assertNotEmpty($a['suitable']);
        $this->assertTrue($a['hasData']);
    }

    public function test_thang_mua_nhieu_co_canh_bao_va_do_mua(): void
    {
        $a = $this->build(['tMax' => 31, 'tMin' => 25, 'rainMm' => 400, 'rainyDays' => 18], 'avoid', 7);
        $this->assertSame(1, $a['stars']);
        $this->assertStringContainsString('Mưa nhiều', implode(' ', $a['caution']));
        $this->assertStringContainsString('mm', implode(' ', $a['caution']));
        $this->assertContains('Áo mưa mỏng hoặc ô gấp', $a['packing']);
    }

    public function test_thang_nong_va_thang_lanh(): void
    {
        $hot = $this->build(['tMax' => 36, 'tMin' => 27, 'rainMm' => 80, 'rainyDays' => 5], 'ok', 5);
        $this->assertStringContainsString('Nắng nóng', implode(' ', $hot['caution']));
        $this->assertContains('Bình nước cá nhân', $hot['packing']);

        $cold = $this->build(['tMax' => 15, 'tMin' => 6, 'rainMm' => 40, 'rainyDays' => 8], 'ok', 1);
        $this->assertStringContainsString('Trời lạnh', implode(' ', $cold['caution']));
        $this->assertContains('Áo ấm nhiều lớp', $cold['packing']);
    }

    public function test_khong_co_du_lieu_khi_hau_khong_bia_loi_khuyen(): void
    {
        $a = $this->build(null, 'unknown', 5);
        $this->assertFalse($a['hasData']);
        $this->assertSame(0, $a['stars']);
        $this->assertSame([], $a['suitable']);
        $this->assertSame([], $a['caution']);
        $this->assertSame([], $a['packing']);
        $this->assertSame('Chưa đủ dữ liệu khí hậu cho tháng này.', $a['summary']);
    }

    public function test_muc_la_khong_hop_le_duoc_dua_ve_unknown(): void
    {
        $this->assertSame('unknown', $this->build(null, 'xyz')['level']);
    }

    public function test_gom_thang_lien_tiep_ke_ca_qua_nam(): void
    {
        $this->assertSame('', TravelAdvice::ranges([]));
        $this->assertSame('T3, T7', TravelAdvice::ranges([7, 3]));
        $this->assertSame('T10–T12', TravelAdvice::ranges([10, 11, 12]));
        $this->assertSame('T12–T2', TravelAdvice::ranges([12, 1, 2]));
        $this->assertSame('T10–T1', TravelAdvice::ranges([10, 11, 12, 1]));
    }
}
