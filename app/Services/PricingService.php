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
     * Hoạt động thêm tùy chọn của tour (tbl_tour_addons): giá luôn lấy từ CSDL; "theo người" nhân với số khách, "theo nhóm" tính một lần.
     * Mã lạ / đã tắt / của tour khác bị bỏ qua (không tin danh sách trình duyệt gửi lên).
     * @param list<int> $ids
     * @return array{lines:list<array{addonId:int,name:string,qty:int,unitPrice:int,amount:int}>,sum:int}
     */
    public function addons(int $tourId, array $ids, int $adults, int $children): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !\Illuminate\Support\Facades\Schema::hasTable('tbl_tour_addons')) {
            return ['lines' => [], 'sum' => 0];
        }
        $people = max(1, $adults + $children);
        $lines = [];
        foreach (\Illuminate\Support\Facades\DB::table('tbl_tour_addons')->where('tourId', $tourId)->where('isActive', 1)->whereIn('addonId', $ids)->orderBy('addonId')->get() as $a) {
            $qty = $a->unit === 'group' ? 1 : $people;
            $lines[] = ['addonId' => (int) $a->addonId, 'name' => (string) $a->name, 'qty' => $qty, 'unitPrice' => (int) $a->price, 'amount' => (int) $a->price * $qty];
        }

        return ['lines' => $lines, 'sum' => array_sum(array_column($lines, 'amount'))];
    }

    /**
     * Xem trước: không trừ lượt mã. Mã không hợp lệ thì trả lỗi trong 'couponError' (không ném).
     * @return array{subtotal:int, discount:int, total:int, promotionId:?int, percent:float, couponError:?string}
     */
    public function quote(object $tour, int $adults, int $children, ?string $coupon, int $userId, array $addonIds = []): array
    {
        $ad = $this->addons((int) $tour->tourId, $addonIds, $adults, $children);
        $subtotal = $this->subtotal($tour, $adults, $children) + $ad['sum'];
        $out = ['subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal, 'promotionId' => null, 'percent' => 0.0, 'couponError' => null, 'addons' => $ad['lines']];
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
