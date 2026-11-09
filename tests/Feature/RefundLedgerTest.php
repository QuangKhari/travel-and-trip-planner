<?php

namespace Tests\Feature;

use App\Services\BookingAdjustmentService;
use App\Services\DepartureService;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\RefundService;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bất biến tiền: tổng hoàn của một đơn (hoàn khi hủy ở tbl_refunds + hoàn phần dư điều chỉnh ở tbl_booking_adjustments,
 * tính cả khoản đang chờ) không bao giờ vượt số đã thu.
 * Lỗi cũ: giảm 1 khách (hoàn 1tr) rồi hủy đơn -> RefundService tính 100% trên 2tr ban đầu -> tổng hoàn 3tr > đã thu 2tr.
 */
class RefundLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function paidBooking(int $adults = 2): int
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour sổ hoàn', 'time' => '3 ngày', 'description' => 'Mô tả', 'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays(40)->toDateString(),
            'endDate' => now()->addDays(42)->toDateString(), 'durationDays' => 3,
        ]);
        foreach (range(1, 3) as $d) {
            DB::table('tbl_timeline')->insert(['tourId' => $tourId, 'title' => 'Ngày ' . $d, 'description' => 'Hoạt động trong ngày ' . $d . ' của chuyến đi']);
        }
        app(DepartureService::class)->ensureForTour($tourId);
        $av = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId');
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['totalSlots' => 10, 'bookedSlots' => $adults, 'heldSlots' => 0, 'minParticipants' => 1]);
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'led' . uniqid(), 'email' => uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $total = 1000000 * $adults;
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $uid, 'availabilityId' => $av,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN',
            'numAdults' => $adults, 'numChildren' => 0, 'totalPrice' => $total, 'bookingStatus' => 'y', 'bookingCode' => 'LG' . uniqid(),
        ]));
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => 'momo', 'provider' => 'momo', 'providerTxnNo' => 'TX' . $id, 'amount' => $total, 'paymentStatus' => 'y']);

        return $id;
    }

    private function cancelWithRefund(int $bookingId, ?int $percent = null): object
    {
        return DB::transaction(function () use ($bookingId, $percent) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['bookingStatus' => 'c']);

            return app(RefundService::class)->requestLocked($b, 'admin', 1, $percent, 'test');
        });
    }

    public function test_cancel_after_partial_adjustment_refund_never_exceeds_captured(): void
    {
        $b = $this->paidBooking(2);   // đã thu 2tr
        $adj = app(BookingAdjustmentService::class)->adjust($b, 1, 0, 'admin', 1, null);   // giảm 1 khách -> hoàn 1tr
        $this->assertSame(1000000, $adj['settleAmount']);
        app(BookingAdjustmentService::class)->markRefunded($adj['adjustId'], 1, 'Chuyển khoản', 'FT-1');

        $r = $this->cancelWithRefund($b, 100);   // hủy, hoàn 100%
        $this->assertSame(1000000, (int) $r->amount, 'chỉ hoàn phần tiền còn ở Travela (2tr − 1tr đã hoàn)');

        $l = app(PaymentLedger::class)->forBooking($b);
        $this->assertSame(2000000, $l['captured']);
        $this->assertSame(0, $l['refundable']);
        $this->assertLessThanOrEqual($l['captured'], $l['adjustOut'] + $l['refundOut']);
        $this->assertSame([], app(PaymentLedger::class)->violations());
    }

    public function test_pending_adjustment_refund_is_also_reserved(): void
    {
        $b = $this->paidBooking(2);
        app(BookingAdjustmentService::class)->adjust($b, 1, 0, 'admin', 1, null);   // pending_refund 1tr, CHƯA chi
        $r = $this->cancelWithRefund($b, 100);
        $this->assertSame(1000000, (int) $r->amount, 'khoản điều chỉnh đang chờ hoàn cũng phải được trừ');
    }

    public function test_policy_percent_applies_to_money_still_held(): void
    {
        $b = $this->paidBooking(4);   // 4tr
        $adj = app(BookingAdjustmentService::class)->adjust($b, 2, 0, 'admin', 1, null);   // hoàn 2tr
        app(BookingAdjustmentService::class)->markRefunded($adj['adjustId'], 1, 'Chuyển khoản', 'FT-2');
        $r = $this->cancelWithRefund($b, 50);
        $this->assertSame(1000000, (int) $r->amount);   // 50% của 2tr còn giữ
        $this->assertStringContainsString('còn giữ', (string) $r->note);
    }

    public function test_request_is_idempotent_per_booking(): void
    {
        $b = $this->paidBooking(2);
        $r1 = $this->cancelWithRefund($b, 100);
        $r2 = $this->cancelWithRefund($b, 100);
        $this->assertSame($r1->refundId, $r2->refundId);
        $this->assertSame(1, DB::table('tbl_refunds')->where('bookingId', $b)->count());
    }

    public function test_reconciliation_flags_combined_over_refund(): void
    {
        $b = $this->paidBooking(2);
        // dữ liệu hỏng từ trước khi vá: hai sổ cộng lại 3tr > đã thu 2tr
        DB::table('tbl_booking_adjustments')->insert(['bookingId' => $b, 'kind' => 'decrease', 'oldAdults' => 2, 'oldChildren' => 0, 'newAdults' => 1, 'newChildren' => 0,
            'oldTotal' => 2000000, 'newTotal' => 1000000, 'moneyStatus' => 'refunded', 'settleAmount' => 1000000, 'by' => 'admin', 'createdAt' => now()]);
        DB::table('tbl_refunds')->insert(['bookingId' => $b, 'paidAmount' => 2000000, 'percent' => 100, 'amount' => 2000000, 'daysBefore' => 40,
            'requestedBy' => 'admin', 'status' => 'pending', 'createdAt' => now()]);
        DB::table('tbl_checkout')->where('bookingId', $b)->update(['paymentStatus' => 'refund_pending']);

        $this->assertCount(1, app(PaymentLedger::class)->violations());
        app(PaymentReconciliationService::class)->sync();
        $this->assertSame(1, DB::table('tbl_reconciliation_findings')->where('type', 'total_refund_exceeds_captured')->where('bookingId', $b)->count());
    }
}
