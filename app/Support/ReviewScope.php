<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Đánh giá bị admin ẩn (tbl_reviews.isHidden, L-F-10) không hiện, không tính vào điểm trung bình / số lượt ở MỌI trang khách.
 * Dùng: ReviewScope::visible($q) với bảng tbl_reviews, hoặc ReviewScope::joinCond('r') trong leftJoin.
 * Chưa chạy migration 2026_11_21_000001 thì không lọc gì (giữ hành vi cũ).
 */
final class ReviewScope
{
    private static ?bool $has = null;

    public static function enabled(): bool
    {
        return self::$has ??= Schema::hasColumn('tbl_reviews', 'isHidden');
    }

    /** @template T of \Illuminate\Database\Query\Builder @param T $q @return T */
    public static function visible($q, string $table = 'tbl_reviews')
    {
        return self::enabled() ? $q->where($table . '.isHidden', 0) : $q;
    }

    /** Điều kiện cho leftJoin: ->leftJoin('tbl_reviews', fn($j) => ReviewScope::join($j, 'tbl_tours.tourId')) */
    public static function join($join, string $tourCol, string $table = 'tbl_reviews'): void
    {
        $join->on($table . '.tourId', '=', $tourCol);
        if (self::enabled()) {
            $join->where($table . '.isHidden', '=', 0);
        }
    }

    /** Cho test: đặt lại bộ nhớ đệm sau khi đổi schema. */
    public static function reset(): void
    {
        self::$has = null;
    }
}
