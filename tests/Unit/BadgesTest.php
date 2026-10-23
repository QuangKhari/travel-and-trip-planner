<?php

namespace Tests\Unit;

use App\Services\Content\Badges;
use PHPUnit\Framework\TestCase;

/** E2: Badges::evaluate là hàm thuần, không cần database. */
class BadgesTest extends TestCase
{
    public function test_co_tu_8_den_10_huy_hieu_va_khoa_khong_trung(): void
    {
        $n = count(Badges::DEFS);
        $this->assertGreaterThanOrEqual(8, $n);
        $this->assertLessThanOrEqual(10, $n);
        $keys = array_column(Badges::DEFS, 'key');
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_moi_huy_hieu_dung_chi_so_co_that(): void
    {
        $valid = ['visited', 'tried', 'attended', 'saved', 'visited_domains', 'tried_domains', 'booked_done'];
        foreach (Badges::DEFS as $b) {
            $this->assertContains($b['metric'], $valid, $b['key']);
            $this->assertGreaterThan(0, $b['need'], $b['key']);   // need = 0 sẽ chia cho 0 ở evaluate
        }
    }

    public function test_tien_do_va_nguong(): void
    {
        $by = fn(array $m) => array_column(Badges::evaluate($m), null, 'key');

        $r = $by([])['nguoi-di-nhieu'];
        $this->assertFalse($r['earned']);
        $this->assertSame(0, $r['percent']);

        $r = $by(['visited' => 2])['nguoi-di-nhieu'];                // 2/5
        $this->assertFalse($r['earned']);
        $this->assertSame(2, $r['have']);
        $this->assertSame(40, $r['percent']);

        $r = $by(['visited' => 5])['nguoi-di-nhieu'];                // đúng ngưỡng
        $this->assertTrue($r['earned']);
        $this->assertSame(100, $r['percent']);

        $r = $by(['visited' => 99])['nguoi-di-nhieu'];               // vượt ngưỡng: have bị chặn ở need
        $this->assertSame(5, $r['have']);
        $this->assertSame(100, $r['percent']);
    }

    public function test_chi_so_thieu_coi_nhu_khong(): void
    {
        foreach (Badges::evaluate(['visited' => 1]) as $b) {
            if ($b['metric'] !== 'visited') {
                $this->assertFalse($b['earned'], $b['key']);
            }
        }
    }
}
