<?php

namespace Tests\Feature;

use App\Services\DepartureGroupingService;
use App\Services\DepartureRescueService;
use App\Services\DepartureService;
use App\Services\RefundService;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Kiểm thử các sửa đổi: hủy thiếu khách không cần đồng ý, từ chối từng khách, cờ thiếu khách, chế độ ghép theo tiêu đề, hoàn tiền. */
class DepartureShortfallFixesTest extends TestCase
{
    use RefreshDatabase;

    private function departure(int $offset, string $suffix, string $desc = 'Lộ trình giống nhau'): array
    {
        $date = now()->addDays($offset)->toDateString();
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour sửa lỗi ' . $suffix, 'time' => '3 ngày', 'description' => 'Thử nghiệm',
            'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1,
            'startDate' => $date, 'endDate' => now()->addDays($offset + 2)->toDateString(), 'durationDays' => 3,
        ]);
        foreach (range(1, 3) as $d) {
            DB::table('tbl_timeline')->insert(['tourId' => $tourId, 'title' => 'Ngày ' . $d, 'description' => $desc . ' ' . $d]);
        }
        app(DepartureService::class)->ensureForTour($tourId);

        return [$tourId, (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId')];
    }

    private function booking(int $tourId, int $availabilityId, bool $paid = true, string $status = 'y'): array
    {
        $userId = DB::table('tbl_users')->insertGetId(['username' => 'sf' . uniqid(), 'email' => 'sf' . uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $userId, 'availabilityId' => $availabilityId,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'Hà Nội',
            'numAdults' => 2, 'numChildren' => 0, 'totalPrice' => 2000000, 'bookingStatus' => $status, 'bookingCode' => 'SF' . uniqid(),
        ]));
        DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->increment($status === 'y' ? 'bookedSlots' : 'heldSlots', 2);
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => 'office', 'amount' => 2000000, 'paymentStatus' => $paid ? 'y' : 'n']);

        return [$userId, $id];
    }

    private function proposal(int $source, string $type, ?int $target = null, string $policy = 'reject_all'): array
    {
        return [
            'availabilityId' => $source, 'targetAvailabilityId' => $target, 'type' => $type, 'declinePolicy' => $policy,
            'description' => 'Đề xuất thay đổi để đảm bảo đủ số lượng khách cho chuyến đi.',
            'serviceGuarantee' => 'Giữ nguyên chất lượng dịch vụ, mức giá, quyền lợi và loại phòng.',
            'changeSummary' => 'Chuyển sang lịch khác cùng tour, giá và quyền lợi không đổi.',
            'responseDeadline' => now()->addDays(4)->toDateTimeString(),
        ];
    }

    public function test_cancel_departure_ignores_declines_and_cancels_held_and_unpaid_bookings(): void
    {
        [$tour, $from] = $this->departure(20, 'huy');
        [$u1, $paid] = $this->booking($tour, $from);
        [$u2, $unpaid] = $this->booking($tour, $from, false);
        [, $held] = $this->booking($tour, $from, false, 'n');
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($from, 'cancel_review'));
        $svc->respond($plan, $paid, $u1, 'declined'); // từ chối KHÔNG được chặn việc Travela hủy
        $this->assertNotSame('rejected', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));

        $res = $svc->cancelDeparture($plan, 1);

        $this->assertSame(3, $res['cancelled']);
        $this->assertSame(1, $res['refunds']);
        foreach ([$paid, $unpaid, $held] as $id) {
            $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
            $this->assertSame('shortfall', DB::table('tbl_booking')->where('bookingId', $id)->value('cancelReason'));
        }
        $refund = DB::table('tbl_refunds')->where('bookingId', $paid)->first();
        $this->assertSame(100, (int) $refund->percent);
        $this->assertSame('pending', $refund->status);
        $this->assertSame(0, DB::table('tbl_refunds')->where('bookingId', $unpaid)->count());
        $row = DB::table('tbl_tour_availability')->where('availabilityId', $from)->first();
        $this->assertSame('closed', $row->status);
        $this->assertSame(0, (int) $row->bookedSlots + (int) $row->heldSlots);
        $this->assertSame('refund_pending', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));
    }

    public function test_plan_completes_when_every_refund_is_marked_refunded(): void
    {
        [$tour, $from] = $this->departure(20, 'hoan');
        [, $b] = $this->booking($tour, $from);
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($from, 'cancel_review'));
        $svc->cancelDeparture($plan, 1);
        $refundId = (int) DB::table('tbl_refunds')->where('bookingId', $b)->value('refundId');
        $this->assertSame('refund_pending', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));
        app(RefundService::class)->markRefunded($refundId, 1, 'Chuyển khoản', 'FT123', null);
        $this->assertSame('completed', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));
    }

    public function test_cancel_departure_dissolves_group_of_the_cancelled_date(): void
    {
        [$t1, $a1] = $this->departure(25, 'gA');
        [$t2, $a2] = $this->departure(25, 'gB');
        $groups = app(DepartureGroupingService::class);
        $m = $groups->merge($a1, $a2);
        $this->assertTrue($m['ok']);
        $this->booking($t1, $a1);
        $svc = app(DepartureRescueService::class);
        // đoàn V1 chặn lập phương án đổi ngày/ghép chặng, nhưng cancel_review thì được
        $plan = $svc->create($this->proposal($a1, 'cancel_review'));
        $res = $svc->cancelDeparture($plan, 1);
        $this->assertNotNull($res['groupReleased']);
        $this->assertSame(0, DB::table('tbl_departure_group_members')->count());
        $this->assertSame('disbanded', DB::table('tbl_departure_groups')->value('status'));
    }

    public function test_decline_policy_refund_decliners_moves_accepted_and_refunds_declined(): void
    {
        [$tour, $from] = $this->departure(20, 'chon');
        $to = DB::table('tbl_tour_availability')->insertGetId([
            'tourId' => $tour, 'departureDate' => now()->addDays(40)->toDateString(), 'totalSlots' => 10, 'bookedSlots' => 0, 'heldSlots' => 0,
            'minParticipants' => 1, 'status' => 'open', 'createdAt' => now(),
        ]);
        [$u1, $ok] = $this->booking($tour, $from);
        [$u2, $no] = $this->booking($tour, $from);
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($from, 'date_change', $to, 'refund_decliners'));
        $svc->respond($plan, $ok, $u1, 'accepted');
        $svc->respond($plan, $no, $u2, 'declined'); // KHÔNG làm hỏng cả phương án
        $this->assertSame('ready', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));

        $res = $svc->apply($plan, 1);

        $this->assertSame([$no], $res['declinedCancelled']);
        $this->assertSame($to, (int) DB::table('tbl_booking')->where('bookingId', $ok)->value('availabilityId'));
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $no)->value('bookingStatus'));
        $this->assertSame(100, (int) DB::table('tbl_refunds')->where('bookingId', $no)->value('percent'));
        $this->assertSame(2, (int) DB::table('tbl_tour_availability')->where('availabilityId', $to)->value('bookedSlots'));
    }

    public function test_default_policy_still_rejects_whole_plan_on_decline(): void
    {
        [$tour, $from] = $this->departure(20, 'macdinh');
        $to = DB::table('tbl_tour_availability')->insertGetId([
            'tourId' => $tour, 'departureDate' => now()->addDays(40)->toDateString(), 'totalSlots' => 10, 'bookedSlots' => 0, 'heldSlots' => 0,
            'minParticipants' => 1, 'status' => 'open', 'createdAt' => now(),
        ]);
        [$u1, $b1] = $this->booking($tour, $from);
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($from, 'date_change', $to));
        $svc->respond($plan, $b1, $u1, 'declined');
        $this->assertSame('rejected', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));
    }

    public function test_expire_turns_silent_customers_into_timeout_when_policy_refunds_decliners(): void
    {
        [$tour, $from] = $this->departure(20, 'timeout');
        $to = DB::table('tbl_tour_availability')->insertGetId([
            'tourId' => $tour, 'departureDate' => now()->addDays(40)->toDateString(), 'totalSlots' => 10, 'bookedSlots' => 0, 'heldSlots' => 0,
            'minParticipants' => 1, 'status' => 'open', 'createdAt' => now(),
        ]);
        [$u1, $ok] = $this->booking($tour, $from);
        [, $silent] = $this->booking($tour, $from);
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($from, 'date_change', $to, 'refund_decliners'));
        $svc->respond($plan, $ok, $u1, 'accepted');
        DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->update(['responseDeadline' => now()->subMinute()]);
        $svc->expire();
        $this->assertSame('timeout', DB::table('tbl_departure_rescue_responses')->where('bookingId', $silent)->value('decision'));
        $this->assertSame('ready', DB::table('tbl_departure_rescue_plans')->where('planId', $plan)->value('status'));
        $svc->apply($plan, 1);
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $silent)->value('bookingStatus'));
    }

    public function test_tour_change_accepts_coupon_booking_when_list_price_is_unchanged(): void
    {
        [$t1, $a1] = $this->departure(20, 'cu');
        [$t2, $a2] = $this->departure(20, 'moi');
        [$u, $b] = $this->booking($t1, $a1);
        // đơn đã giảm 100.000: tiền trả 1.900.000 + mã 100.000 = giá niêm yết 2.000.000
        DB::table('tbl_booking')->where('bookingId', $b)->update(['totalPrice' => 1900000]);
        $promoId = DB::table('tbl_promotion')->insertGetId(['code' => 'GIAM100', 'description' => 'Giảm', 'discount' => 5, 'quantity' => 5, 'status' => 'y', 'startDate' => now()->subDays(5)->toDateString(), 'endDate' => now()->addDays(5)->toDateString()]);
        DB::table('tbl_promotion_usage')->insert(['promotionId' => $promoId, 'userId' => $u, 'bookingId' => $b, 'discountAmount' => 100000]);
        DB::table('tbl_checkout')->where('bookingId', $b)->update(['amount' => 1900000]);
        $svc = app(DepartureRescueService::class);
        $plan = $svc->create($this->proposal($a1, 'tour_change', $a2));
        $svc->respond($plan, $b, $u, 'accepted');
        $svc->apply($plan, 1);
        $this->assertSame($t2, (int) DB::table('tbl_booking')->where('bookingId', $b)->value('tourId'));
        $this->assertSame(1900000, (int) DB::table('tbl_booking')->where('bookingId', $b)->value('totalPrice'));
    }

    public function test_shortfall_command_flags_and_clears(): void
    {
        [$tour, $from] = $this->departure(5, 'co');
        DB::table('tbl_tour_availability')->where('availabilityId', $from)->update(['minParticipants' => 5]);
        $this->booking($tour, $from);
        $this->artisan('departures:check-shortfall')->assertSuccessful();
        $this->assertNotNull(DB::table('tbl_tour_availability')->where('availabilityId', $from)->value('shortfallFlaggedAt'));
        DB::table('tbl_tour_availability')->where('availabilityId', $from)->update(['minParticipants' => 1]);
        $this->artisan('departures:check-shortfall')->assertSuccessful();
        $this->assertNull(DB::table('tbl_tour_availability')->where('availabilityId', $from)->value('shortfallFlaggedAt'));
    }

    public function test_titles_mode_merges_tours_with_same_titles_but_different_descriptions(): void
    {
        [, $a1] = $this->departure(30, 'tA', 'Mô tả phiên bản một');
        [, $a2] = $this->departure(30, 'tB', 'Mô tả phiên bản hai');
        $groups = app(DepartureGroupingService::class);
        $this->assertSame([], $groups->candidates($a1, 'strict'));
        $this->assertCount(1, $groups->candidates($a1, 'titles'));
        $this->assertFalse($groups->merge($a1, $a2)['ok']);
        $r = $groups->merge($a1, $a2, null, 'titles');
        $this->assertTrue($r['ok']);
        $this->assertSame('titles', DB::table('tbl_departure_groups')->value('fingerprintMode'));
    }

    public function test_cannot_close_a_grouped_departure_via_toggle(): void
    {
        [, $a1] = $this->departure(30, 'kA');
        [, $a2] = $this->departure(30, 'kB');
        app(DepartureGroupingService::class)->merge($a1, $a2);
        $r = app(DepartureService::class)->toggle($a1);
        $this->assertFalse($r['ok']);
        $this->assertSame('open', DB::table('tbl_tour_availability')->where('availabilityId', $a1)->value('status'));
    }
}
