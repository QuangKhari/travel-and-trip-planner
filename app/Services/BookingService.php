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

            // 4. Mã giảm giá (nếu có): kiểm ở server, trừ lượt nguyên tử
            $discount = 0;
            $couponCode = strtoupper(trim((string) ($in['couponCode'] ?? '')));
            if ($couponCode !== '') {
                $promo = DB::table('tbl_promotion')->where('code', $couponCode)->lockForUpdate()->first();

                if (!$promo || $promo->status !== 'y') {
                    throw new BookingException('Mã giảm giá không hợp lệ.');
                }
                if ($promo->startDate > $today || $promo->endDate < $today) {   // endDate tính trọn ngày cuối
                    throw new BookingException('Mã giảm giá chưa đến hạn hoặc đã hết hạn.');
                }

                $used = DB::table('tbl_promotion')
                    ->where('promotionId', $promo->promotionId)
                    ->where('quantity', '>', 0)
                    ->decrement('quantity', 1);

                if ($used !== 1) {
                    throw new BookingException('Mã giảm giá đã hết lượt sử dụng.');
                }

                $percent  = min(100, max(0, (float) $promo->discount));
                $discount = (int) round($subtotal * $percent / 100);
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
            ]);

            DB::table('tbl_checkout')->insert([
                'bookingId'     => $bookingId,
                'paymentMethod' => 'office-payment',
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

    private function newBookingCode(): string
    {
        do {
            $code = 'TOUR' . now()->format('YmdHis') . random_int(1000, 9999);
        } while (DB::table('tbl_booking')->where('bookingCode', $code)->exists());

        return $code;
    }
}
