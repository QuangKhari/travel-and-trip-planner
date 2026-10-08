<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tạo đơn đặt tour: server tự tính giá, kiểm tra tour, trừ chỗ nguyên tử,
 * kiểm tra + trừ lượt mã giảm giá, tất cả trong MỘT transaction.
 * Mọi giá trị về tiền do trình duyệt gửi lên đều bị bỏ qua.
 */
class BookingService
{

    public function __construct(private CouponService $coupons) {}

    /**
     * @param array $in fullName, email, tel, address, numAdults, numChildren, tourId, couponCode (đã validate)
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

            // 1. Khóa dòng tour trong lúc đặt (hai người đặt cùng lúc sẽ xếp hàng)
            $tour = DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();

            if (!$tour || (int) $tour->availability !== 1) {
                throw new BookingException('Tour không tồn tại hoặc đã ngừng nhận đặt.');
            }
            // Đổi '<=' thành '<' nếu muốn cho đặt đến hết ngày khởi hành
            if ($tour->startDate <= $today) {
                throw new BookingException('Tour đã khởi hành, không thể đặt.');
            }

            // 2. Trừ chỗ nguyên tử: chỉ trừ khi còn đủ chỗ
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

            // 3. Server tự tính giá
            $subtotal = (int) round($tour->priceAdult * $adults + $tour->priceChild * $children);

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

            $bookingId = DB::table('tbl_booking')->insertGetId([
                'tourId'      => $tourId,
                'userId'      => $userId,
                'fullName'    => $in['fullName'],
                'email'       => $in['email'],
                'phoneNumber' => $in['tel'],
                'address'     => $in['address'],
                'numAdults'   => $adults,
                'numChildren' => $children,
                'totalPrice'  => $total,
                'bookingCode' => $bookingCode,
                // Giữ chỗ có hạn: quá hạn mà chưa xác nhận thì lệnh bookings:expire-holds tự hủy
                'holdExpiresAt' => now()->addHours((int) config('travela.hold_hours', 48)),
            ]);

            if ($promotionId !== null) {
                $this->coupons->attach($promotionId, $userId, $bookingId, $discount);
            }

            DB::table('tbl_checkout')->insert([
                'bookingId'     => $bookingId,
                'paymentMethod' => 'office-payment',
                'paymentDate'   => null,
                'amount'        => $total,
                'paymentStatus' => 'n',
            ]);

            return [
                'bookingId'   => $bookingId,
                'bookingCode' => $bookingCode,
                'totalPrice'  => $total,
                'discount'    => $discount,
            ];
        });
    }

    /**
     * Hủy một đơn: đổi trạng thái, trả chỗ, nhả mã giảm giá.
     * Phải gọi TRONG transaction, sau khi đã khóa dòng đơn (lockForUpdate) và kiểm tra trạng thái.
     */
    public function cancelLocked(object $booking): void
    {
        DB::table('tbl_booking')
            ->where('bookingId', $booking->bookingId)
            ->update(['bookingStatus' => 'c', 'holdExpiresAt' => null]);

        DB::table('tbl_tours')
            ->where('tourId', $booking->tourId)
            ->increment('quantity', (int) $booking->numAdults + (int) $booking->numChildren);

        $this->coupons->release((int) $booking->bookingId);
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
                    $this->cancelLocked($booking);
                    $cancelled++;
                }
            });
        }

        return $cancelled;
    }

    private function newBookingCode(): string
    {
        do {
            $code = 'TOUR' . now()->format('YmdHis') . random_int(1000, 9999);
        } while (DB::table('tbl_booking')->where('bookingCode', $code)->exists());

        return $code;
    }
}
