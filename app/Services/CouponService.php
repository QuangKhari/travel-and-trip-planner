<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Toàn bộ luật mã giảm giá nằm ở đây:
 *  - quote():   xem trước, không trừ lượt (dùng cho nút "Áp dụng")
 *  - redeem():  dùng thật trong transaction đặt tour: khóa dòng mã, kiểm lại mọi luật, trừ lượt
 *  - attach():  ghi lượt dùng gắn với đơn (gọi sau khi có bookingId)
 *  - release(): nhả mã khi đơn bị hủy hoặc hết hạn giữ chỗ (gọi lại nhiều lần vẫn an toàn)
 * Số tiền giảm luôn do server tính; trình duyệt chỉ gửi mã.
 */
class CouponService
{
    /**
     * @return array{promotionId:int, percent:float, discount:int}
     * @throws BookingException
     */
    public function quote(string $code, int $subtotal, int $userId): array
    {
        $promo = DB::table('tbl_promotion')->where('code', $this->normalize($code))->first();

        return $this->evaluate($promo, $subtotal, $userId);
    }

    /**
     * Gọi TRONG transaction của đặt tour. Khóa dòng mã nên hai đơn cùng dùng một mã sẽ xếp hàng,
     * giới hạn lượt/người và số lượt còn lại luôn đúng.
     *
     * @return array{promotionId:int, percent:float, discount:int}
     * @throws BookingException
     */
    public function redeem(string $code, int $subtotal, int $userId): array
    {
        $promo = DB::table('tbl_promotion')
            ->where('code', $this->normalize($code))
            ->lockForUpdate()
            ->first();

        $quote = $this->evaluate($promo, $subtotal, $userId);

        $affected = DB::table('tbl_promotion')
            ->where('promotionId', $promo->promotionId)
            ->where('quantity', '>', 0)
            ->decrement('quantity', 1);

        if ($affected !== 1) {
            throw new BookingException('Mã giảm giá đã hết lượt sử dụng.');
        }

        return $quote;
    }

    public function attach(int $promotionId, int $userId, int $bookingId, int $discount): void
    {
        DB::table('tbl_promotion_usage')->insert([
            'promotionId'    => $promotionId,
            'userId'         => $userId,
            'bookingId'      => $bookingId,
            'discountAmount' => max(0, $discount),
        ]);
    }

    /** Nhả mã của một đơn: xóa lượt dùng và cộng lại 1 lượt. Không có lượt dùng thì không làm gì. */
    public function release(int $bookingId): void
    {
        $usage = DB::table('tbl_promotion_usage')->where('bookingId', $bookingId)->lockForUpdate()->first();

        if (!$usage) {
            return;
        }

        DB::table('tbl_promotion_usage')->where('usageId', $usage->usageId)->delete();
        DB::table('tbl_promotion')->where('promotionId', $usage->promotionId)->increment('quantity', 1);
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** @throws BookingException */
    private function evaluate(?object $promo, int $subtotal, int $userId): array
    {
        if (!$promo) {
            throw new BookingException('Mã giảm giá không tồn tại.');
        }
        if ($promo->status !== 'y') {
            throw new BookingException('Mã giảm giá đã bị vô hiệu hóa.');
        }

        // startDate/endDate là kiểu DATE: so theo ngày, ngày cuối còn dùng được trọn ngày
        $today = now()->toDateString();
        if ($promo->startDate > $today || $promo->endDate < $today) {
            throw new BookingException('Mã giảm giá chưa đến hạn hoặc đã hết hạn.');
        }

        if ((int) $promo->quantity <= 0) {
            throw new BookingException('Mã giảm giá đã hết lượt sử dụng.');
        }

        $max = (int) config('travela.coupon_max_uses_per_user', 1);
        if ($max > 0) {
            $used = DB::table('tbl_promotion_usage')
                ->where('promotionId', $promo->promotionId)
                ->where('userId', $userId)
                ->count();

            if ($used >= $max) {
                throw new BookingException("Bạn đã dùng mã này tối đa {$max} lần.");
            }
        }

        $percent = min(100, max(0, (float) $promo->discount));

        return [
            'promotionId' => (int) $promo->promotionId,
            'percent'     => $percent,
            'discount'    => (int) round($subtotal * $percent / 100),
        ];
    }
}
