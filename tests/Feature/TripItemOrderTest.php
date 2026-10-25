<?php

namespace Tests\Feature;

use App\Services\Trip\TripItemOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 1: unique(tripId, dayNo, orderNo) không bị vi phạm khi thêm, xóa, đảo chỗ, chuyển ngày. */
class TripItemOrderTest extends TestCase
{
    use RefreshDatabase;

    private int $trip;
    private TripItemOrder $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->trip = DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'kind' => 'trip', 'title' => 'Chuyến thử', 'status' => 'draft', 'groupSize' => 1]);
        $this->svc = new TripItemOrder();
    }

    /** @return list<int> itemId theo thứ tự trong một ngày */
    private function day(int $day): array
    {
        return DB::table('tbl_trip_items')->where('tripId', $this->trip)->where('dayNo', $day)->orderBy('orderNo')->pluck('itemId')->map(fn ($v) => (int) $v)->all();
    }

    private function orders(int $day): array
    {
        return DB::table('tbl_trip_items')->where('tripId', $this->trip)->where('dayNo', $day)->orderBy('orderNo')->pluck('orderNo')->map(fn ($v) => (int) $v)->all();
    }

    private function three(): array
    {
        return [$this->svc->add($this->trip, 1, 'place', 11), $this->svc->add($this->trip, 1, 'place', 12), $this->svc->add($this->trip, 1, 'place', 13)];
    }

    public function test_same_entity_can_appear_on_two_days(): void
    {
        $this->svc->add($this->trip, 1, 'place', 11);
        $this->svc->add($this->trip, 2, 'place', 11);
        $this->assertCount(2, DB::table('tbl_trip_items')->where('tripId', $this->trip)->get());
    }

    public function test_transport_and_note_need_no_ref_id(): void
    {
        $id = $this->svc->add($this->trip, 1, 'transport', null, 'Taxi sân bay → phố cổ');
        $this->assertNull(DB::table('tbl_trip_items')->where('itemId', $id)->value('refId'));
    }

    public function test_place_without_ref_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->add($this->trip, 1, 'place', null);
    }

    public function test_add_appends_in_order(): void
    {
        $ids = $this->three();
        $this->assertSame($ids, $this->day(1));
        $this->assertSame([0, 1, 2], $this->orders(1));
    }

    public function test_remove_closes_the_gap(): void
    {
        [$a, $b, $c] = $this->three();
        $this->svc->remove($a);
        $this->assertSame([$b, $c], $this->day(1));
        $this->assertSame([0, 1], $this->orders(1));
    }

    public function test_swap_within_a_day(): void
    {
        [$a, $b, $c] = $this->three();
        $this->svc->move($a, 1, 2);
        $this->assertSame([$b, $c, $a], $this->day(1));
        $this->assertSame([0, 1, 2], $this->orders(1));

        $this->svc->move($a, 1, 0);
        $this->assertSame([$a, $b, $c], $this->day(1));
    }

    public function test_move_to_another_day(): void
    {
        [$a, $b, $c] = $this->three();
        $d = $this->svc->add($this->trip, 2, 'food', 21);
        $this->svc->move($b, 2, 0);

        $this->assertSame([$a, $c], $this->day(1));
        $this->assertSame([0, 1], $this->orders(1));
        $this->assertSame([$b, $d], $this->day(2));
        $this->assertSame([0, 1], $this->orders(2));
    }

    public function test_move_position_is_clamped(): void
    {
        [$a, $b, $c] = $this->three();
        $this->svc->move($a, 2, 99);
        $this->assertSame([$a], $this->day(2));
        $this->assertSame([0], $this->orders(2));
    }
}
