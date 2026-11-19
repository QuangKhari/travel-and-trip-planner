<?php

namespace App\Modules\Content;

use Illuminate\Support\Facades\DB;

/**
 * Giao diện GHI của Content trên tin tour (tbl_tours) cho Tour Ops và Commerce: chỉ các cột "ngày khởi hành đang hiển thị" và số chỗ cũ.
 * tbl_tours.startDate / endDate / quantity là bản tóm tắt chuyến gần nhất còn bán (nguồn thật là tbl_tour_availability của Tour Ops),
 * giữ lại để các trang danh sách, lọc, trang chủ dùng tiếp. Phần nội dung tour (tên, mô tả, giá niêm yết, lịch trình) chỉ trang quản trị nội dung sửa.
 */
final class TourListing
{
    /** Tour Ops ghi lại ngày khởi hành đang hiển thị và số chỗ còn của nó. */
    public function showDeparture(int $tourId, string $startDate, string $endDate, int $remaining): void
    {
        DB::table('tbl_tours')->where('tourId', $tourId)->update(['startDate' => $startDate, 'endDate' => $endDate, 'quantity' => max(0, $remaining)]);
    }

    /** Tour chưa có ngày khởi hành riêng: trừ chỗ trên tin tour (một câu UPDATE có điều kiện). @return bool đủ chỗ và đã trừ */
    public function takeLegacySeats(int $tourId, int $people): bool
    {
        return DB::table('tbl_tours')->where('tourId', $tourId)->where('quantity', '>=', $people)->decrement('quantity', $people) === 1;
    }

    /** Trả lại chỗ trên tin tour (đơn cũ chưa gắn ngày khởi hành bị hủy / hết hạn). */
    public function returnLegacySeats(int $tourId, int $people): void
    {
        if ($people > 0) {
            DB::table('tbl_tours')->where('tourId', $tourId)->increment('quantity', $people);
        }
    }
}
