<?php

namespace Tests\Unit;

use App\Services\Planner\TripPlanner;
use PHPUnit\Framework\TestCase;

/** Luật tự chia mục vào các ngày của Trip Planner (hàm thuần, không cần DB). */
class PlannerDistributeTest extends TestCase
{
    private function it(string $key, string $type, ?int $cluster): array
    {
        return ['key' => $key, 'type' => $type, 'cluster' => $cluster];
    }

    public function test_chia_deu_so_muc_giua_cac_ngay(): void
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = $this->it("food:$i", 'food', 1);
        }
        $pos = TripPlanner::distribute($items, 2);
        $perDay = array_count_values(array_column($pos, 'day'));
        $this->assertSame([1 => 3, 2 => 2], $perDay);
    }

    public function test_gom_theo_diem_den_va_thu_tu_trong_cum(): void
    {
        $items = [
            $this->it('food:f1', 'food', 1),
            $this->it('destination:1', 'destination', 1),
            $this->it('food:f2', 'food', 2),
            $this->it('destination:2', 'destination', 2),
            $this->it('event:e', 'event', null),   // không có điểm đến: xếp cuối
        ];
        $pos = TripPlanner::distribute($items, 2);

        // cụm 1: điểm đến trước món; cụm 2 tiếp theo; mục tự do cuối cùng. 5 mục / 2 ngày = 3 + 2
        $this->assertSame(['day' => 1, 'order' => 0], $pos['destination:1']);
        $this->assertSame(['day' => 1, 'order' => 1], $pos['food:f1']);
        $this->assertSame(['day' => 1, 'order' => 2], $pos['destination:2']);
        $this->assertSame(['day' => 2, 'order' => 0], $pos['food:f2']);
        $this->assertSame(['day' => 2, 'order' => 1], $pos['event:e']);
    }

    public function test_it_muc_hon_so_ngay_thi_moi_muc_mot_ngay_va_ngay_du_de_trong(): void
    {
        $pos = TripPlanner::distribute([$this->it('a', 'destination', 1), $this->it('b', 'destination', 2)], 5);
        $this->assertSame(1, $pos['a']['day']);
        $this->assertSame(2, $pos['b']['day']);
        $this->assertCount(2, $pos);
    }

    public function test_so_ngay_bi_chan_o_muc_toi_da_va_danh_sach_rong_khong_loi(): void
    {
        $this->assertSame([], TripPlanner::distribute([], 3));
        $items = [];
        for ($i = 0; $i < 40; $i++) {
            $items[] = $this->it("food:$i", 'food', null);
        }
        $pos = TripPlanner::distribute($items, 99);
        $this->assertLessThanOrEqual(TripPlanner::MAX_DAYS, max(array_column($pos, 'day')));
        $this->assertCount(40, $pos);
    }
}
