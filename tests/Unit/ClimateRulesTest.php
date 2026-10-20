<?php

namespace Tests\Unit;

use App\Services\Climate\ClimateRules;
use App\Services\Climate\OpenMeteoArchive;
use PHPUnit\Framework\TestCase;

class ClimateRulesTest extends TestCase
{
    private function rules(): array
    {
        return require __DIR__ . '/../../config/climate.php';
    }

    private function level(array $m, int $month): string
    {
        $r = $this->rules()['rules'];
        $s = ClimateRules::score($m, $month, $r)['score'];

        return $s >= $r['best_min_score'] ? 'best' : ($s <= $r['avoid_max_score'] ? 'avoid' : 'ok');
    }

    public function test_diem_so_cac_truong_hop_dien_hinh(): void
    {
        $r = $this->rules()['rules'];
        // Số liệu minh họa gần với khí hậu thật: Hà Nội tháng 10 (mát, ít mưa), tháng 7 (nóng ẩm), Huế tháng 10 (mưa lũ)
        $this->assertEqualsWithDelta(82.58, ClimateRules::score(['tMax' => 29.5, 'tMin' => 22.3, 'rainMm' => 100, 'rainyDays' => 9], 10, $r)['score'], 0.01);
        $this->assertEqualsWithDelta(66.03, ClimateRules::score(['tMax' => 33.5, 'tMin' => 26, 'rainMm' => 300, 'rainyDays' => 16], 7, $r)['score'], 0.01);
        $this->assertEqualsWithDelta(31.29, ClimateRules::score(['tMax' => 28, 'tMin' => 23, 'rainMm' => 650, 'rainyDays' => 20], 10, $r)['score'], 0.01);

        $this->assertSame('best', $this->level(['tMax' => 29.5, 'tMin' => 22.3, 'rainMm' => 100, 'rainyDays' => 9], 10));
        $this->assertSame('ok', $this->level(['tMax' => 33.5, 'tMin' => 26, 'rainMm' => 300, 'rainyDays' => 16], 7));
        $this->assertSame('avoid', $this->level(['tMax' => 28, 'tMin' => 23, 'rainMm' => 650, 'rainyDays' => 20], 10));
        $this->assertSame('best', $this->level(['tMax' => 24, 'tMin' => 13, 'rainMm' => 20, 'rainyDays' => 3], 1)); // Đà Lạt tháng 1
        $this->assertSame('avoid', $this->level(['tMax' => 12, 'tMin' => 5, 'rainMm' => 30, 'rainyDays' => 6], 12)); // lạnh sâu
    }

    public function test_evaluate_gioi_han_so_thang_best_va_luon_co_it_nhat_mot(): void
    {
        $r = $this->rules()['rules'];
        // 12 tháng giống hệt nhau và rất tốt -> chỉ giữ tối đa max_best tháng
        $good = ['tMax' => 27, 'tMin' => 20, 'rainMm' => 20, 'rainyDays' => 2];
        $res = ClimateRules::evaluate(array_fill(1, 12, $good), $r);
        $this->assertCount($r['max_best'], array_filter($res, fn ($x) => $x['level'] === 'best'));

        // Không tháng nào đạt ngưỡng -> vẫn có ít nhất min_best tháng tốt nhất tương đối
        $meh = array_fill(1, 12, ['tMax' => 30, 'tMin' => 23, 'rainMm' => 250, 'rainyDays' => 18]);
        $meh[4] = ['tMax' => 30, 'tMin' => 23, 'rainMm' => 150, 'rainyDays' => 14];
        $res = ClimateRules::evaluate($meh, $r);
        $this->assertSame('best', $res[4]['level']);
        $this->assertStringContainsString('°C', $res[4]['note']);
    }

    public function test_gop_du_lieu_ngay_thanh_12_thang(): void
    {
        // 2 năm, chỉ tháng 1: năm 1 có 3 ngày (30, 20, 10 mm mưa), năm 2 có 2 ngày (0, 0 mm)
        $daily = [
            'time' => ['2024-01-01', '2024-01-02', '2024-01-03', '2025-01-01', '2025-01-02'],
            'temperature_2m_max' => [20, 22, 24, 18, 20],
            'temperature_2m_min' => [10, 12, 14, 8, 10],
            'precipitation_sum' => [30, 20, 0.4, 0, 0],
        ];
        $m = OpenMeteoArchive::aggregate($daily, 1.0)[1];
        $this->assertSame(2, $m['years']);
        $this->assertEqualsWithDelta(20.5, $m['tMax'], 0.01);     // trung bình năm: (22 + 19) / 2
        $this->assertEqualsWithDelta(10.5, $m['tMin'], 0.01);
        $this->assertEqualsWithDelta(25.2, $m['rainMm'], 0.01);   // (50,4 + 0) / 2
        $this->assertEqualsWithDelta(1.0, $m['rainyDays'], 0.01); // (2 ngày + 0 ngày) / 2; 0,4 mm chưa là ngày mưa
        $this->assertNull(OpenMeteoArchive::aggregate($daily, 1.0)[2]['tMax']); // tháng không có dữ liệu
    }
}
