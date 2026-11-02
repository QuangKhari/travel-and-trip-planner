<?php

namespace Tests\Feature;

use App\Services\BookingException;
use App\Services\BookingService;
use App\Services\CouponService;
use App\Services\DepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * J3: test riêng cho BookingService và CouponService.
 *   hết chỗ, coupon hết hạn đúng ngày cuối (múi giờ Việt Nam), vượt lượt / người, nhả coupon khi hủy và khi hết hạn giữ chỗ,
 *   idempotency requestToken, mất cache (Redis khởi động lại) không ảnh hưởng việc tự nhả chỗ (N-12).
 */
class BookingCouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->newUser('khach');
    }

    private function newUser(string $n): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => "$n@example.com", 'password' => 'x', 'fullName' => 'Khách ' . $n, 'isActive' => 'y']);
    }

    private function tour(int $qty = 10, int $daysAhead = 20): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour J3', 'time' => '3 ngày', 'description' => '<p>x</p>', 'quantity' => $qty,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Huế', 'domain' => 'b', 'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(), 'endDate' => now()->addDays($daysAhead + 2)->toDateString(),
        ]);
    }

    private function promo(array $o = []): int
    {
        return DB::table('tbl_promotion')->insertGetId(array_merge([
            'code' => 'GIAM10', 'description' => 'Giảm 10%', 'discount' => 10, 'quantity' => 5, 'status' => 'y',
            'startDate' => now()->subDays(5)->toDateString(), 'endDate' => now()->addDays(5)->toDateString(),
        ], $o));
    }

    private function book(int $tour, array $o = [], ?int $user = null): array
    {
        return app(BookingService::class)->create($user ?? $this->user, array_merge([
            'tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '',
        ], $o));
    }

    private function remaining(int $tour): int
    {
        $a = DB::table('tbl_tour_availability')->where('tourId', $tour)->first();

        return (int) $a->totalSlots - (int) $a->bookedSlots - (int) $a->heldSlots;
    }

    // ---- BookingService --------------------------------------------------------------------------------

    public function test_price_is_computed_on_server_and_seats_are_held(): void
    {
        $t = $this->tour(10);
        $r = $this->book($t, ['numAdults' => 2, 'numChildren' => 1]);
        $this->assertSame(2500000, $r['totalPrice']);
        $this->assertSame(7, $this->remaining($t));
        $this->assertSame('n', DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('bookingStatus'));
    }

    public function test_sold_out_and_not_enough_seats_are_rejected_without_side_effects(): void
    {
        $t = $this->tour(3);
        $this->book($t, ['numAdults' => 2]);
        try {
            $this->book($t, ['numAdults' => 2]);
            $this->fail('Phải báo không đủ chỗ');
        } catch (BookingException $e) {
            $this->assertStringContainsString('chỉ còn 1 chỗ', $e->getMessage());
        }
        $this->book($t, ['numAdults' => 1]);
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('hết chỗ');
        try {
            $this->book($t);
        } finally {
            $this->assertSame(2, DB::table('tbl_booking')->count());
            $this->assertSame(0, $this->remaining($t));
        }
    }

    public function test_departed_or_closed_tour_cannot_be_booked(): void
    {
        $t = $this->tour(10, 0);
        $this->expectException(BookingException::class);
        $this->book($t);
    }

    public function test_request_token_makes_create_idempotent(): void
    {
        $t = $this->tour(10);
        $a = $this->book($t, ['numAdults' => 2, 'requestToken' => 'tok-1']);
        $b = $this->book($t, ['numAdults' => 2, 'requestToken' => 'tok-1']);
        $this->assertSame($a['bookingId'], $b['bookingId']);
        $this->assertTrue($b['duplicate']);
        $this->assertSame(1, DB::table('tbl_booking')->count());
        $this->assertSame(8, $this->remaining($t));

        // token của phiên đã hủy không được dùng lại
        DB::table('tbl_booking')->where('bookingId', $a['bookingId'])->update(['bookingStatus' => 'c']);
        $this->expectException(BookingException::class);
        $this->book($t, ['requestToken' => 'tok-1']);
    }

    public function test_confirm_moves_held_to_booked_and_cancel_returns_booked_seats(): void
    {
        $t = $this->tour(10);
        $id = $this->book($t, ['numAdults' => 3])['bookingId'];
        $svc = app(BookingService::class);
        DB::transaction(fn() => $svc->confirmLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first()));
        $a = DB::table('tbl_tour_availability')->where('tourId', $t)->first();
        $this->assertSame([0, 3], [(int) $a->heldSlots, (int) $a->bookedSlots]);

        DB::transaction(fn() => $svc->cancelLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first(), 'admin'));
        $this->assertSame(10, $this->remaining($t));
        $this->assertSame('admin', DB::table('tbl_booking')->where('bookingId', $id)->value('cancelReason'));
    }

    public function test_expire_holds_releases_seats_and_coupon_even_after_cache_loss(): void
    {
        $t = $this->tour(10);
        $p = $this->promo(['quantity' => 1]);
        $id = $this->book($t, ['numAdults' => 2, 'couponCode' => 'giam10'])['bookingId'];
        $this->assertSame(0, (int) DB::table('tbl_promotion')->where('promotionId', $p)->value('quantity'));

        Cache::flush();   // N-12: Redis khởi động lại, mất hết khóa / cache -> MySQL vẫn là nguồn sự thật
        $this->travel(49)->hours();
        $this->assertSame(1, app(BookingService::class)->expireHolds());
        $this->assertSame(0, app(BookingService::class)->expireHolds());   // chạy lại không trả chỗ hai lần

        $this->assertSame(10, $this->remaining($t));
        $this->assertSame(1, (int) DB::table('tbl_promotion')->where('promotionId', $p)->value('quantity'));
        $this->assertSame(0, DB::table('tbl_promotion_usage')->count());
        $this->assertSame('expired', DB::table('tbl_booking')->where('bookingId', $id)->value('cancelReason'));
    }

    public function test_seat_cache_is_invalidated_when_seats_change(): void
    {
        $t = $this->tour(10);
        app(DepartureService::class)->ensureForTour($t);
        $first = $this->getJson("/tour/$t/cho-trong")->assertOk()->json('departures.0.remaining');
        $this->assertSame(10, $first);
        $this->book($t, ['numAdults' => 4]);
        $this->assertSame(6, $this->getJson("/tour/$t/cho-trong")->json('departures.0.remaining'));
        $this->assertNull(Cache::get(DepartureService::seatsKey(999999)));
    }

    // ---- CouponService ---------------------------------------------------------------------------------

    public function test_coupon_valid_through_whole_last_day_in_vietnam_time(): void
    {
        $this->promo(['endDate' => '2026-12-31']);
        $c = app(CouponService::class);
        // 23:59 ngày cuối theo giờ Việt Nam (16:59 UTC) vẫn dùng được
        $this->travelTo(\Carbon\Carbon::parse('2026-12-31 23:59:00', 'Asia/Ho_Chi_Minh'));
        $this->assertSame(100000, $c->quote('GIAM10', 1000000, $this->user)['discount']);
        // 00:00 ngày hôm sau thì hết hạn
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01 00:00:01', 'Asia/Ho_Chi_Minh'));
        $this->expectExceptionMessage('hết hạn');
        $c->quote('GIAM10', 1000000, $this->user);
    }

    public function test_coupon_not_started_disabled_unknown(): void
    {
        $this->promo(['code' => 'SAP', 'startDate' => now()->addDay()->toDateString()]);
        $this->promo(['code' => 'TAT', 'status' => 'n']);
        $c = app(CouponService::class);
        foreach (['SAP' => 'chưa đến hạn', 'TAT' => 'vô hiệu hóa', 'KHONGCO' => 'không tồn tại'] as $code => $msg) {
            try {
                $c->quote($code, 1000000, $this->user);
                $this->fail("$code phải bị từ chối");
            } catch (BookingException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
    }

    public function test_min_order_and_max_discount(): void
    {
        $this->promo(['code' => 'LON', 'discount' => 50, 'minOrder' => 2000000, 'maxDiscount' => 300000]);
        $c = app(CouponService::class);
        $this->assertSame(300000, $c->quote('LON', 4000000, $this->user)['discount']);   // 50% = 2tr, bị chặn 300k
        $this->expectExceptionMessage('2.000.000');
        $c->quote('LON', 1500000, $this->user);
    }

    public function test_per_user_limit_and_release_on_cancel(): void
    {
        $t = $this->tour(20);
        $p = $this->promo(['perUserLimit' => 1]);
        $id = $this->book($t, ['couponCode' => 'GIAM10'])['bookingId'];
        $this->assertSame(1, DB::table('tbl_promotion_usage')->where('promotionId', $p)->count());
        try {
            $this->book($t, ['couponCode' => 'GIAM10']);
            $this->fail('Vượt lượt / người phải bị từ chối');
        } catch (BookingException $e) {
            $this->assertStringContainsString('tối đa 1 lần', $e->getMessage());
        }
        // người khác vẫn dùng được
        $this->book($t, ['couponCode' => 'GIAM10'], $this->newUser('khac'));
        $this->assertSame(3, (int) DB::table('tbl_promotion')->where('promotionId', $p)->value('quantity'));

        // hủy đơn -> trả lượt, dùng lại được
        $svc = app(BookingService::class);
        DB::transaction(fn() => $svc->cancelLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first()));
        $this->assertSame(4, (int) DB::table('tbl_promotion')->where('promotionId', $p)->value('quantity'));
        $this->book($t, ['couponCode' => 'GIAM10']);
    }

    public function test_coupon_quantity_runs_out(): void
    {
        $t = $this->tour(20);
        $this->promo(['quantity' => 1, 'perUserLimit' => 0]);
        $this->book($t, ['couponCode' => 'GIAM10']);
        $this->expectExceptionMessage('hết lượt');
        $this->book($t, ['couponCode' => 'GIAM10'], $this->newUser('b'));
    }

    public function test_failed_coupon_rolls_back_seat_hold(): void
    {
        $t = $this->tour(10);
        app(DepartureService::class)->ensureForTour($t);
        $this->promo(['minOrder' => 50000000]);
        try {
            $this->book($t, ['numAdults' => 2, 'couponCode' => 'GIAM10']);
        } catch (BookingException) {
        }
        $this->assertSame(10, $this->remaining($t));
        $this->assertSame(0, DB::table('tbl_booking')->count());
    }
}
