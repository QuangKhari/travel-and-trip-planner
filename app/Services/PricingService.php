<?php

namespace App\Services;

/**
 * Tính giá một đơn (đặc tả §7.3, A5). Dùng chung cho xem trước giá, đặt tour và test.
 * Mọi số tiền là số nguyên VNĐ, không âm. Giá trình duyệt gửi lên luôn bị bỏ qua.
 */
class PricingService
{
    public function __construct(private CouponService $coupons) {}

    public function subtotal(object $tour, int $adults, int $children): int
    {
        return max(0, (int) round((float) $tour->priceAdult * max(0, $adults) + (float) $tour->priceChild * max(0, $children)));
    }

    /**
     * Xem trước: không trừ lượt mã. Mã không hợp lệ thì trả lỗi trong 'couponError' (không ném).
     * @return array{subtotal:int, discount:int, total:int, promotionId:?int, percent:float, couponError:?string}
     */
    public function quote(object $tour, int $adults, int $children, ?string $coupon, int $userId): array
    {
        $subtotal = $this->subtotal($tour, $adults, $children);
        $out = ['subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal, 'promotionId' => null, 'percent' => 0.0, 'couponError' => null];
        $coupon = trim((string) $coupon);
        if ($coupon !== '') {
            try {
                $q = $this->coupons->quote($coupon, $subtotal, $userId);
                $out['discount'] = min($subtotal, $q['discount']);
                $out['promotionId'] = $q['promotionId'];
                $out['percent'] = $q['percent'];
            } catch (BookingException $e) {
                $out['couponError'] = $e->getMessage();
            }
        }
        $out['total'] = max(0, $subtotal - $out['discount']);

        return $out;
    }
}
