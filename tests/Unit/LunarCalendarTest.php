<?php

namespace Tests\Unit;

use App\Services\EventDateService;
use App\Support\LunarCalendar;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LunarCalendarTest extends TestCase
{
    private function iso(?array $d): ?string
    {
        return $d ? sprintf('%04d-%02d-%02d', $d['y'], $d['m'], $d['d']) : null;
    }

    /** Mốc đã công bố: Tết Nguyên đán, Trung thu, Giỗ Tổ Hùng Vương (10/3 âm). Đối chiếu thêm với lịch chính thức khi báo cáo. */
    public static function anchors(): array
    {
        return [
            'Tết 2025'        => [1, 1, 2025, '2025-01-29'],
            'Tết 2026'        => [1, 1, 2026, '2026-02-17'],
            'Tết 2027'        => [1, 1, 2027, '2027-02-06'],
            'Tết 2028'        => [1, 1, 2028, '2028-01-26'],
            'Trung thu 2025'  => [15, 8, 2025, '2025-10-06'],
            'Trung thu 2026'  => [15, 8, 2026, '2026-09-25'],
            'Trung thu 2027'  => [15, 8, 2027, '2027-09-15'],
            'Trung thu 2028'  => [15, 8, 2028, '2028-10-03'],
            'Giỗ Tổ 2025'     => [10, 3, 2025, '2025-04-07'],
            'Giỗ Tổ 2026'     => [10, 3, 2026, '2026-04-26'],
            'Giỗ Tổ 2027'          => [10, 3, 2027, '2027-04-16'],
            'Giỗ Tổ 2028'          => [10, 3, 2028, '2028-04-04'],
            'Rằm tháng Giêng 2026' => [15, 1, 2026, '2026-03-03'],
            'Rằm tháng Giêng 2027' => [15, 1, 2027, '2027-02-20'],
            'Rằm tháng Giêng 2028' => [15, 1, 2028, '2028-02-09'],
        ];
    }

    #[DataProvider('anchors')]
    public function test_am_sang_duong_dung_moc(int $d, int $m, int $y, string $expected): void
    {
        $this->assertSame($expected, $this->iso(LunarCalendar::toSolar($d, $m, $y)));
    }

    public function test_thang_nhuan_2025(): void
    {
        // 2025 có tháng 6 nhuận (bắt đầu 25/7/2025)
        $this->assertSame('2025-07-25', $this->iso(LunarCalendar::toSolar(1, 6, 2025, true)));
        $this->assertSame('2025-06-25', $this->iso(LunarCalendar::toSolar(1, 6, 2025, false)));
        $this->assertNull(LunarCalendar::toSolar(1, 6, 2026, true));   // 2026 không nhuận
        $this->assertNull(LunarCalendar::toSolar(1, 7, 2025, true));   // nhuận 6 chứ không phải 7
    }

    public function test_ngay_khong_ton_tai_tra_null(): void
    {
        $this->assertNull(LunarCalendar::toSolar(31, 1, 2026));
        $this->assertNull(LunarCalendar::toSolar(1, 13, 2026));
        // tháng 12 âm 2026 chỉ có 29 ngày (Tết 2027 là 6/2/2027)
        $this->assertNull(LunarCalendar::toSolar(30, 12, 2026));
        $this->assertSame('2027-02-05', $this->iso(LunarCalendar::toSolar(29, 12, 2026)));
    }

    public function test_duong_sang_am_mot_so_ngay(): void
    {
        $this->assertSame(['d' => 1, 'm' => 1, 'y' => 2026, 'leap' => false], LunarCalendar::toLunar(17, 2, 2026));
        $this->assertSame(['d' => 25, 'm' => 6, 'y' => 2025, 'leap' => true], LunarCalendar::toLunar(18, 8, 2025)); // trong tháng 6 nhuận
        $this->assertSame(['d' => 1, 'm' => 6, 'y' => 2025, 'leap' => true], LunarCalendar::toLunar(25, 7, 2025));
    }

    /** Đổi qua lại mọi ngày của 3 năm 2026-2028: toLunar rồi toSolar phải ra đúng ngày ban đầu. */
    public function test_khu_hoi_moi_ngay_2026_den_2028(): void
    {
        $d = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2028-12-31');
        $bad = [];
        while ($d <= $end) {
            $l = LunarCalendar::toLunar((int) $d->format('j'), (int) $d->format('n'), (int) $d->format('Y'));
            $back = LunarCalendar::toSolar($l['d'], $l['m'], $l['y'], $l['leap']);
            if ($this->iso($back) !== $d->format('Y-m-d')) {
                $bad[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
        }
        $this->assertSame([], $bad, 'Ngày không khứ hồi: ' . implode(', ', array_slice($bad, 0, 5)));
    }

    public function test_su_kien_am_lich_3_nam(): void
    {
        $svc = new EventDateService();
        $tet = ['isLunar' => 1, 'lunarMonth' => 1, 'lunarDay' => 1, 'durationDays' => 7];
        $r = $svc->upcoming($tet, new \DateTimeImmutable('2026-01-01'), 3);
        $this->assertSame(['2026-02-17', '2027-02-06', '2028-01-26'], array_column($r, 'start'));
        $this->assertSame('2026-02-23', $r[0]['end']);
        $this->assertFalse($r[0]['approx']);
    }

    public function test_su_kien_lap_hang_thang_va_duong_lich_khong_ro_ngay(): void
    {
        $svc = new EventDateService();
        $ram = ['isLunar' => 1, 'lunarMonth' => null, 'lunarDay' => 14, 'durationDays' => 1];
        $r = $svc->upcoming($ram, new \DateTimeImmutable('2026-10-19'), 3);
        $this->assertCount(3, $r);
        $this->assertGreaterThanOrEqual('2026-10-19', $r[0]['start']);

        $hoa = ['isLunar' => 0, 'solarMonth' => 12, 'solarDay' => null, 'durationDays' => 5];
        $r = $svc->upcoming($hoa, new \DateTimeImmutable('2026-10-19'), 1);
        $this->assertSame('2026-12-01', $r[0]['start']);
        $this->assertTrue($r[0]['approx']);
    }
}
