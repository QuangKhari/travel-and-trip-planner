<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Support\BookingPii;
use App\Support\CancelPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Điều chỉnh số người (người lớn / trẻ em) của một đơn đang giữ chỗ hoặc đã xác nhận.
 *  - Giá tính theo GIÁ KHÁCH ĐÃ ĐỒNG Ý lúc đặt (phiên bản tour của đơn), không theo giá hiện tại; mã giảm giá tính lại cùng tỷ lệ / mức tối đa.
 *  - Chỗ: tăng người phải còn chỗ (cập nhật có điều kiện), giảm người trả chỗ và mời người trong danh sách chờ.
 *  - Đơn chưa thanh toán (tại văn phòng): đổi số khách và số tiền phải trả. Đơn thanh toán trực tuyến chưa trả: không đổi được (link thanh toán đã chốt số tiền).
 *  - Đơn đã thanh toán: tăng người -> khoản thu thêm chờ thu (pending_collect); giảm người -> hoàn phần dư theo chính sách hủy (pending_refund).
 *    Khoản thu / hoàn ghi ở tbl_booking_adjustments, KHÔNG dùng tbl_refunds (UNIQUE bookingId).
 */
class BookingAdjustmentService
{
    public static function ready(): bool
    {
        return Schema::hasTable('tbl_booking_adjustments');
    }

    public static function minDays(): int
    {
        return max(0, (int) config('booking.adjust_min_days', 7));
    }

    /** Khách có được điều chỉnh đơn này không (để hiện form). @return array{can:bool, why:string} */
    public function eligibility(object $booking, bool $admin = false): array
    {
        $no = fn (string $m) => ['can' => false, 'why' => $m];
        if (!self::ready()) {
            return $no('Tính năng chưa được kích hoạt.');
        }
        if (!in_array($booking->bookingStatus, ['n', 'y'], true) || empty($booking->availabilityId)) {
            return $no('Chỉ điều chỉnh được đơn đang giữ chỗ hoặc đã xác nhận.');
        }
        $days = CancelPolicy::daysUntil($booking->departureDate ?? null);
        if ($admin ? $days < 1 : $days < self::minDays()) {
            return $no($admin ? 'Tour khởi hành trong ngày hoặc đã khởi hành.' : 'Chỉ tự điều chỉnh được khi còn ít nhất ' . self::minDays() . ' ngày trước khởi hành. Vui lòng liên hệ Travela.');
        }
        $co = DB::table('tbl_checkout')->where('bookingId', $booking->bookingId)->first();
        if ($co && $co->paymentStatus === 'n' && !empty($co->provider)) {
            return $no('Đơn thanh toán trực tuyến chưa hoàn tất không đổi được số khách. Hãy hủy và đặt lại, hoặc thanh toán xong rồi điều chỉnh.');
        }
        if (DB::table('tbl_booking_adjustments')->where('bookingId', $booking->bookingId)->whereIn('moneyStatus', ['pending_collect', 'pending_refund'])->exists()) {
            return $no('Đơn còn một khoản thu thêm / hoàn tiền đang chờ xử lý; hoàn tất rồi mới điều chỉnh tiếp.');
        }

        return ['can' => true, 'why' => ''];
    }

    /**
     * Tính / thực hiện điều chỉnh.
     * @return array{ok:bool, message:string, adjustId?:int, kind?:string, oldTotal?:int, newTotal?:int, delta?:int, moneyStatus?:string, settleAmount?:int, refundPercent?:?int, people?:array}
     */
    public function adjust(int $bookingId, int $adults, int $children, string $by, ?int $byId, ?int $userId = null, ?string $note = null, bool $dryRun = false): array
    {
        $fail = fn (string $m) => ['ok' => false, 'message' => $m];
        if (!self::ready()) {
            return $fail('Tính năng chưa được kích hoạt.');
        }
        if ($adults < 1 || $children < 0 || $adults + $children > 50) {
            return $fail('Số khách không hợp lệ (ít nhất 1 người lớn, tối đa 50 người).');
        }
        $tourId = (int) DB::table('tbl_booking')->where('bookingId', $bookingId)->value('tourId');
        if (!$tourId) {
            return $fail('Không tìm thấy đơn.');
        }
        $res = DB::transaction(function () use ($bookingId, $tourId, $adults, $children, $by, $byId, $userId, $note, $dryRun, $fail) {
            // thứ tự khóa thống nhất với đặt tour / rescue: tour -> lịch khởi hành -> đơn
            $tour = DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (!$b || ($userId !== null && (int) $b->userId !== $userId)) {
                return $fail('Không tìm thấy đơn.');
            }
            if (empty($b->availabilityId)) {
                return $fail('Đơn chưa gắn ngày khởi hành nên không điều chỉnh được.');
            }
            $a = DB::table('tbl_tour_availability')->where('availabilityId', $b->availabilityId)->lockForUpdate()->first();
            $el = $this->eligibility($b, $by === 'admin');
            if (!$el['can'] || !$a) {
                return $fail($el['why'] ?: 'Không điều chỉnh được đơn này.');
            }
            if (DB::table('tbl_departure_rescue_plans')->where('availabilityId', $a->availabilityId)->whereIn('status', ['pending', 'ready'])->exists()) {
                return $fail('Lịch khởi hành đang có phương án điều chỉnh xin ý kiến khách; thử lại sau khi hoàn tất.');
            }
            $oldA = (int) $b->numAdults;
            $oldC = (int) $b->numChildren;
            $oldP = $oldA + $oldC;
            $newP = $adults + $children;
            if ($oldA === $adults && $oldC === $children) {
                return $fail('Số khách không thay đổi.');
            }
            $d = $newP - $oldP;
            if ($d > 0 && ($a->status !== 'open' || DepartureService::remaining($a) < $d)) {
                return $fail('Không đủ chỗ trống để thêm ' . $d . ' người (còn ' . max(0, DepartureService::remaining($a)) . ' chỗ).');
            }

            // giá theo phiên bản khách đã đồng ý lúc đặt
            $pa = (float) $tour->priceAdult;
            $pc = (float) $tour->priceChild;
            if (!empty($b->tourVersionId) && Schema::hasTable('tbl_tour_versions')) {
                $snap = (array) json_decode((string) DB::table('tbl_tour_versions')->where('versionId', $b->tourVersionId)->value('snapshot'), true);
                $pa = (float) ($snap['priceAdult'] ?? $pa);
                $pc = (float) ($snap['priceChild'] ?? $pc);
            }
            $subtotal = (int) round($pa * $adults + $pc * $children);
            $discount = 0;
            $usage = Schema::hasTable('tbl_promotion_usage') ? DB::table('tbl_promotion_usage')->where('bookingId', $bookingId)->first() : null;
            if ($usage) {
                $promo = DB::table('tbl_promotion')->where('promotionId', $usage->promotionId)->first();
                if ($promo) {
                    $minOrder = (int) ($promo->minOrder ?? 0);
                    if ($minOrder > 0 && $subtotal < $minOrder) {
                        return $fail('Mã giảm giá của đơn yêu cầu giá trị tối thiểu ' . number_format($minOrder, 0, ',', '.') . ' đ; số khách mới thấp hơn mức này.');
                    }
                    $discount = (int) round($subtotal * min(100, max(0, (float) $promo->discount)) / 100);
                    $cap = (int) ($promo->maxDiscount ?? 0);
                    $discount = min($subtotal, $cap > 0 ? min($discount, $cap) : $discount);
                }
            }
            $oldTotal = (int) round((float) $b->totalPrice);
            $newTotal = max(0, $subtotal - $discount);
            $co = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $paid = $co && $co->paymentStatus === 'y';
            // tiền khách còn thực sự "đang ở Travela" = đã nhận − phần dư đã / đang hoàn ở các lần điều chỉnh trước
            $paidAmount = $paid ? app(\App\Services\Payments\PaymentLedger::class)->forBooking($bookingId)['held'] : 0;   // cùng một sổ với RefundService
            $kind = $newTotal >= $oldTotal ? 'increase' : 'decrease';

            $money = 'none';
            $settle = 0;
            $percent = null;
            if ($paid) {
                if ($newTotal > $paidAmount) {
                    $money = 'pending_collect';
                    $settle = $newTotal - $paidAmount;
                } elseif ($paidAmount > $newTotal) {
                    $percent = CancelPolicy::refundPercent(CancelPolicy::daysUntil($b->departureDate));
                    $settle = (int) floor(($paidAmount - $newTotal) * $percent / 100);
                    $money = $settle > 0 ? 'pending_refund' : 'none';
                }
            }
            $result = ['ok' => true, 'message' => 'OK', 'kind' => $kind, 'oldTotal' => $oldTotal, 'newTotal' => $newTotal, 'delta' => $newTotal - $oldTotal,
                'moneyStatus' => $money, 'settleAmount' => $settle, 'refundPercent' => $percent,
                'people' => ['old' => [$oldA, $oldC], 'new' => [$adults, $children]], 'paid' => $paid, 'discount' => $discount];
            if ($dryRun) {
                return $result;
            }

            if ($d !== 0) {
                $col = $b->bookingStatus === 'n' ? 'heldSlots' : 'bookedSlots';
                if ($d > 0) {
                    $ok = DB::table('tbl_tour_availability')->where('availabilityId', $a->availabilityId)->where('status', 'open')
                        ->whereRaw('totalSlots - bookedSlots - heldSlots >= ?', [$d])
                        ->update([$col => DB::raw($col . ' + ' . $d), 'updatedAt' => now()]);
                    if ($ok !== 1) {
                        return $fail('Không đủ chỗ trống để thêm ' . $d . ' người.');
                    }
                } else {
                    DB::table('tbl_tour_availability')->where('availabilityId', $a->availabilityId)->update([$col => DB::raw('GREATEST(' . $col . ' - ' . abs($d) . ', 0)'), 'updatedAt' => now()]);
                }
                app(DepartureService::class)->syncTour($tourId);
                Cache::forget(DepartureService::seatsKey($tourId));
            }
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['numAdults' => $adults, 'numChildren' => $children, 'totalPrice' => $newTotal]);
            if (!$paid) {
                DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['amount' => $newTotal]);
            }
            if ($usage) {
                DB::table('tbl_promotion_usage')->where('bookingId', $bookingId)->update(['discountAmount' => $discount]);
            }
            $id = DB::table('tbl_booking_adjustments')->insertGetId([
                'bookingId' => $bookingId, 'kind' => $kind, 'oldAdults' => $oldA, 'oldChildren' => $oldC, 'newAdults' => $adults, 'newChildren' => $children,
                'oldTotal' => $oldTotal, 'newTotal' => $newTotal, 'moneyStatus' => $money, 'refundPercent' => $percent, 'settleAmount' => $settle,
                'by' => $by, 'byId' => $byId, 'note' => $note ? mb_substr($note, 0, 255) : null, 'createdAt' => now(),
            ]);
            if ($d < 0 && WaitlistService::ready()) {   // trả chỗ: mời người đang chờ
                $av = (int) $a->availabilityId;
                DB::afterCommit(fn () => app(WaitlistService::class)->offerNext($av));
            }

            return $result + ['adjustId' => $id, 'bookingCode' => $b->bookingCode];
        });
        if ($res['ok'] && !$dryRun) {
            $this->mail($bookingId, 'adjusted', $res);
        }

        return $res;
    }

    /** Ghi nhận đã thu khoản thu thêm. @return array{ok:bool,message:string} */
    public function markCollected(int $adjustId, int $adminId, string $method, ?string $reference): array
    {
        return $this->settle($adjustId, $adminId, $method, $reference, 'pending_collect', 'collected');
    }

    /** Ghi nhận đã hoàn phần dư. @return array{ok:bool,message:string} */
    public function markRefunded(int $adjustId, int $adminId, string $method, ?string $reference): array
    {
        return $this->settle($adjustId, $adminId, $method, $reference, 'pending_refund', 'refunded');
    }

    private function settle(int $adjustId, int $adminId, string $method, ?string $reference, string $from, string $to): array
    {
        $res = DB::transaction(function () use ($adjustId, $adminId, $method, $reference, $from, $to) {
            $a = DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->lockForUpdate()->first();
            if (!$a || $a->moneyStatus !== $from) {
                return ['ok' => false, 'message' => 'Khoản này không còn ở trạng thái chờ xử lý.'];
            }
            // lệnh hoàn qua cổng còn đang xử lý: không ghi nhận tay (tránh hoàn hai lần); chỉ chính GatewayRefundService được ghi
            if (($a->gatewayStatus ?? null) === 'processing' && !str_starts_with($method, 'Hoàn qua ')) {
                return ['ok' => false, 'message' => 'Lệnh hoàn qua cổng đang xử lý: bấm “Kiểm tra trạng thái”, không ghi nhận tay.'];
            }
            if ($reference !== null && $reference !== '' && (
                DB::table('tbl_booking_adjustments')->where('adjustId', '!=', $adjustId)->where('method', mb_substr($method, 0, 40))->where('reference', mb_substr($reference, 0, 80))->exists()
                || (Schema::hasTable('tbl_refunds') && DB::table('tbl_refunds')->where('method', mb_substr($method, 0, 40))->where('reference', mb_substr($reference, 0, 80))->exists())
            )) {
                return ['ok' => false, 'message' => 'Mã giao dịch này đã được dùng cho một khoản khác: kiểm tra lại để tránh ghi nhận trùng.'];
            }
            DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->update(['moneyStatus' => $to, 'method' => mb_substr($method, 0, 40),
                'reference' => $reference ? mb_substr($reference, 0, 80) : null, 'settledAt' => now(), 'settledBy' => $adminId]);
            if ($to === 'collected') {
                DB::table('tbl_checkout')->where('bookingId', $a->bookingId)->increment('amount', (int) $a->settleAmount);
            }

            return ['ok' => true, 'message' => $to === 'collected' ? 'Đã ghi nhận thu thêm.' : 'Đã ghi nhận hoàn tiền.', 'bookingId' => (int) $a->bookingId, 'amount' => (int) $a->settleAmount];
        });
        if ($res['ok']) {
            $this->mail((int) $res['bookingId'], $to, ['settleAmount' => $res['amount']]);
        }

        return $res;
    }

    private function mail(int $bookingId, string $event, array $data): void
    {
        try {
            $b = BookingPii::decrypt(DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')->where('b.bookingId', $bookingId)->select('b.*', 't.title')->first());
            if ($b && filter_var($b->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($b->email)->queue(new TemplateMail('admin.emails.booking-adjusted', ['b' => $b, 'event' => $event, 'r' => $data], 'Travela — thay đổi số khách đơn ' . $b->bookingCode));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
