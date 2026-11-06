<?php

namespace App\Services;

use App\Services\Payments\OnlinePaymentService;

use Illuminate\Support\Facades\DB;
use App\Support\BookingPii;

/**
 * Tạo đơn đặt tour: server tự tính giá, kiểm tra tour, trừ chỗ nguyên tử,
 * kiểm tra + trừ lượt mã giảm giá, tất cả trong MỘT transaction.
 * Mọi giá trị về tiền do trình duyệt gửi lên đều bị bỏ qua.
 */
class BookingService
{

    public function __construct(
        private CouponService $coupons,
        private ?DepartureService $departures = null,
        private ?PricingService $pricing = null,
    ) {
        $this->departures ??= new DepartureService();
        $this->pricing ??= new PricingService($coupons);
    }

    /**
     * @param array $in fullName, email, tel, address, numAdults, numChildren, tourId, couponCode, availabilityId? (đã validate)
     * @return array{bookingId:int, bookingCode:string, totalPrice:int, discount:int}
     * @throws BookingException
     */
    public function create(int $userId, array $in): array
    {
        return DB::transaction(function () use ($userId, $in) {
            $tourId   = (int) $in['tourId'];
            $adults   = (int) $in['numAdults'];
            $children = (int) $in['numChildren'];
            $people   = $adults + $children;
            $today    = now()->toDateString();
            $token = !empty($in['requestToken']) ? (string) $in['requestToken'] : null;

            // 1. Khóa dòng tour trong lúc đặt (hai người đặt cùng lúc sẽ xếp hàng)
            $tour = DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();

            if ($token !== null) {
                $existing = DB::table('tbl_booking')
                    ->where('userId', $userId)
                    ->where('requestToken', $token)
                    ->first();

                if ($existing) {
                    if ($existing->bookingStatus === 'c') {
                        throw new BookingException('Phiên đặt tour này đã hết hiệu lực, vui lòng tải lại trang và đặt lại.');
                    }
                    return [
                        'bookingId'   => (int) $existing->bookingId,
                        'bookingCode' => $existing->bookingCode,
                        'totalPrice'  => (int) $existing->totalPrice,
                        'discount'    => 0,
                        'duplicate'   => true,
                    ];
                }
            }

            if (!$tour || (int) $tour->availability !== 1) {
                throw new BookingException('Tour không tồn tại hoặc đã ngừng nhận đặt.');
            }

            // 2. Chọn ngày khởi hành và giữ chỗ bằng MỘT câu UPDATE có điều kiện (chỉ trừ khi còn đủ chỗ)
            $availabilityId = null;
            $departureDate = $tour->startDate;
            if (DepartureService::enabled()) {
                $dep = $this->departures->lockForBooking($tour, !empty($in['availabilityId']) ? (int) $in['availabilityId'] : null);
                if (!$this->departures->hold((int) $dep->availabilityId, $people)) {
                    $left = DepartureService::remaining($dep);
                    throw new BookingException($left > 0
                        ? "Ngày khởi hành này chỉ còn {$left} chỗ, không đủ cho {$people} khách."
                        : 'Ngày khởi hành này đã hết chỗ.');
                }
                $availabilityId = (int) $dep->availabilityId;
                $departureDate = $dep->departureDate;
            } else {
                // Đổi '<=' thành '<' nếu muốn cho đặt đến hết ngày khởi hành
                if ($tour->startDate <= $today) {
                    throw new BookingException('Tour đã khởi hành, không thể đặt.');
                }
                $affected = DB::table('tbl_tours')
                    ->where('tourId', $tourId)
                    ->where('quantity', '>=', $people)
                    ->decrement('quantity', $people);

                if ($affected !== 1) {
                    $left = max(0, (int) $tour->quantity);
                    throw new BookingException($left > 0
                        ? "Tour chỉ còn {$left} chỗ, không đủ cho {$people} khách."
                        : 'Tour đã hết chỗ.');
                }
            }

            // 3. Server tự tính giá (PricingService)
            $subtotal = $this->pricing->subtotal($tour, $adults, $children);

            // 4. Mã giảm giá (nếu có): mọi luật nằm ở CouponService (L-B-03)
            $discount    = 0;
            $promotionId = null;
            $couponCode  = trim((string) ($in['couponCode'] ?? ''));

            if ($couponCode !== '') {
                $quote       = $this->coupons->redeem($couponCode, $subtotal, $userId);
                $discount    = $quote['discount'];
                $promotionId = $quote['promotionId'];
            }

            $total = max(0, $subtotal - $discount);

            // 5. Tạo đơn + thanh toán
            $bookingCode = $this->newBookingCode();
            // Thanh toán trực tuyến (MoMo / ZaloPay / QR): giữ chỗ ngắn (A2, C-06), hết hạn mà chưa nhận được tiền thì tự hủy và nhả chỗ
            $method = (string) ($in['payment'] ?? 'office-payment');
            $online = OnlinePaymentService::isOnline($method);

            $bookingId = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
                'tourId'       => $tourId,
                'userId'       => $userId,
                'availabilityId' => $availabilityId,
                'departureDate'  => $availabilityId ? $departureDate : null,
                'fullName'     => $in['fullName'],
                'email'        => $in['email'],
                'phoneNumber'  => $in['tel'],
                'address'      => $in['address'],
                'numAdults'    => $adults,
                'numChildren'  => $children,
                'totalPrice'   => $total,
                'bookingCode'  => $bookingCode,
                'requestToken' => $token,
                // giữ chỗ 48 giờ nhưng không quá 0h ngày khởi hành: đặt sát ngày đi thì đơn chưa xác nhận không được "treo" qua ngày đi
                'holdExpiresAt' => min($online ? now()->addMinutes(OnlinePaymentService::holdMinutes($method)) : now()->addHours((int) config('travela.hold_hours', 48)), \Carbon\Carbon::parse($departureDate)->startOfDay()),
            ]));

            if ($promotionId !== null) {
                $this->coupons->attach($promotionId, $userId, $bookingId, $discount);
            }

            if ($availabilityId) {
                $this->departures->syncTour($tourId);
            }

            DB::table('tbl_checkout')->insert([
                'bookingId'     => $bookingId,
                'paymentMethod' => $online ? $method : 'office-payment',
                'provider'      => $online ? $method : null,
                'paymentDate'   => null,
                'amount'        => $total,
                'paymentStatus' => 'n',
            ]);

            return [
                'bookingId'   => $bookingId,
                'bookingCode' => $bookingCode,
                'totalPrice'  => $total,
                'discount'    => $discount,
                'duplicate'   => false,
                'availabilityId' => $availabilityId,
                'departureDate'  => $departureDate,
            ];
        }, 3);
    }

    /**
     * Hủy một đơn: đổi trạng thái, trả chỗ, nhả mã giảm giá.
     * Phải gọi TRONG transaction, sau khi đã khóa dòng đơn (lockForUpdate) và kiểm tra trạng thái.
     * $reason: customer (khách tự hủy) | admin | expired (hết hạn giữ chỗ) | shortfall (Travela hủy vì thiếu khách). Thống kê phân biệt được các loại hủy (L-B-24).
     */
    public function cancelLocked(object $booking, string $reason = 'customer', bool $releaseCoupon = true): void
    {
        DB::table('tbl_booking')
            ->where('bookingId', $booking->bookingId)
            ->update(['bookingStatus' => 'c', 'holdExpiresAt' => null] + (self::hasReason() ? ['cancelReason' => $reason] : []));

        // Trả chỗ về đúng ngày khởi hành (chỗ đang giữ nếu đơn chờ, chỗ đã bán nếu đơn đã xác nhận)
        $this->departures->release($booking);

        // Đơn đã thanh toán: RefundService::requestLocked quyết định nhả mã theo % hoàn (config booking.coupon_release_min_percent).
        if ($releaseCoupon) {
            $this->coupons->release((int) $booking->bookingId);
        }
    }

    /**
     * Xác nhận đơn (n -> y): chỗ đang giữ thành chỗ đã bán, bỏ hạn giữ chỗ.
     * Gọi TRONG transaction, sau khi đã khóa dòng đơn và kiểm tra trạng thái là n.
     */
    public function confirmLocked(object $booking): void
    {
        DB::table('tbl_booking')->where('bookingId', $booking->bookingId)
            ->update(['bookingStatus' => 'y', 'holdExpiresAt' => null]);
        $this->departures->confirm($booking);
    }

    /**
     * Hủy các đơn chờ xác nhận đã quá hạn giữ chỗ. Mỗi đơn một transaction riêng,
     * kiểm tra lại sau khi khóa nên chạy chồng hai lần cũng không trả chỗ hai lần.
     *
     * @return int số đơn đã hủy
     */
    public function expireHolds(): int
    {
        $ids = DB::table('tbl_booking')
            ->where('bookingStatus', 'n')
            ->whereNotNull('holdExpiresAt')
            ->where('holdExpiresAt', '<', now())
            ->pluck('bookingId');

        $cancelled = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$cancelled) {
                $booking = DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first();

                if (
                    $booking
                    && $booking->bookingStatus === 'n'
                    && $booking->holdExpiresAt !== null
                    && \Carbon\Carbon::parse($booking->holdExpiresAt)->isPast()
                ) {
                    $this->cancelLocked($booking, 'expired');
                    $cancelled++;
                }
            });
        }

        return $cancelled;
    }

    /**
     * Hoàn tất (f) các đơn đã xác nhận + đã thanh toán của tour đã kết thúc (L-F-11). Trước đây chỉ admin bấm tay từng đơn,
     * mà khách chỉ đánh giá được khi đơn là f. Đơn chưa thanh toán vẫn để admin xử lý (không đoán).
     * @return int số đơn đã hoàn tất
     */
    public function completeFinished(): int
    {
        $today = now()->toDateString();
        $rows = DB::table('tbl_booking as b')
            ->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->where('b.bookingStatus', 'y')->where('c.paymentStatus', 'y')
            ->get(['b.bookingId', 'b.departureDate', 't.startDate', 't.endDate', 't.durationDays']);
        $done = 0;
        foreach ($rows as $r) {
            if (self::tripEndDate($r) >= $today) {
                continue;
            }
            $done += DB::table('tbl_booking')->where('bookingId', $r->bookingId)->where('bookingStatus', 'y')->update(['bookingStatus' => 'f']);
        }

        return $done;
    }

    /** Ngày về của chuyến mà đơn đã đặt: theo ngày khởi hành của đơn, đơn cũ chưa có thì theo tour. */
    public static function tripEndDate(object $row): string
    {
        if (!empty($row->departureDate)) {
            return DepartureService::endDate($row->departureDate, DepartureService::durationDays($row));
        }

        return (string) $row->endDate;
    }

    public static function tripStartDate(object $row): string
    {
        return (string) ($row->departureDate ?: $row->startDate);
    }

    private static ?bool $hasReason = null;

    private static function hasReason(): bool
    {
        return self::$hasReason ??= \Illuminate\Support\Facades\Schema::hasColumn('tbl_booking', 'cancelReason');
    }

    private function newBookingCode(): string
    {
        do {
            $code = 'TOUR' . now()->format('YmdHis') . random_int(1000, 9999);
        } while (DB::table('tbl_booking')->where('bookingCode', $code)->exists());

        return $code;
    }
}
