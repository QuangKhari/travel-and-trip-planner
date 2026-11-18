<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giá theo mùa: tăng / giảm % theo ngày khởi hành. Quy tắc nằm trong CSDL (admin sửa được, có test), KHÔNG viết cứng trong code.
 * Chọn quy tắc: quy tắc riêng của tour thắng quy tắc chung; cùng phạm vi thì quy tắc tạo sau thắng. Chỉ MỘT quy tắc được áp (không cộng dồn).
 * Mức % giới hạn trong [-50, 100] để một lỗi nhập liệu không biến giá thành 0 hay gấp nhiều lần.
 */
final class PriceRules
{
    public const MIN = -50;
    public const MAX = 100;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('tbl_price_rules');
    }

    /** @return ?array{ruleId:int,pct:int,label:string} */
    public static function for(int $tourId, ?string $date): ?array
    {
        if (!$date || !self::ready()) {
            return null;
        }
        $r = DB::table('tbl_price_rules')->where('isActive', 1)->where('dateFrom', '<=', $date)->where('dateTo', '>=', $date)
            ->where(fn($q) => $q->where('tourId', $tourId)->orWhereNull('tourId'))
            ->orderByRaw('tourId IS NULL')->orderByDesc('ruleId')->first();

        return $r ? ['ruleId' => (int) $r->ruleId, 'pct' => max(self::MIN, min(self::MAX, (int) $r->pct)), 'label' => (string) $r->label] : null;
    }

    /** Giá một suất sau khi áp % (làm tròn tới 1.000đ, không âm). */
    public static function apply(float $price, int $pct): int
    {
        return $pct === 0 ? (int) round($price) : max(0, (int) (round($price * (100 + $pct) / 100 / 1000) * 1000));
    }
}
