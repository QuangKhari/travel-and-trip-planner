<?php

namespace App\Services\Ai;

use App\Services\Ai\Copilot\CopilotService;
use App\Support\DomainEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phân hệ AI phản ứng với sự kiện TripUpdated của Planner (thay cho việc Planner gọi thẳng vào La bàn khi lưu chuyến):
 *  1. lịch vừa lưu trùng dấu vân tay lịch trước một đề xuất đã áp dụng -> đề xuất đó bị hoàn tác (undone);
 *  2. lần LƯU chuyến (change = save) sau khi bấm Áp dụng một đề xuất -> thay đổi đã thật sự vào CSDL (applied);
 *     khôi phục phiên bản / nhập đặt chỗ ngoài không phải bằng chứng áp dụng nên không tính;
 *  3. bộ đệm giải thích của các phiên bản cũ hơn không còn dùng được -> xóa.
 * Idempotent: chạy lại cùng sự kiện không đổi kết quả (outbox có thể giao lại khi lỗi).
 */
final class TripChangeListener
{
    public function handle(string $name, array $p): void
    {
        if ($name !== DomainEvents::TRIP_UPDATED || empty($p['tripId'])) {
            return;
        }
        $tripId = (int) $p['tripId'];
        $rev = (int) ($p['rev'] ?? 0);
        if (!empty($p['fingerprint'])) {
            CopilotService::detectUndo($tripId, (string) $p['fingerprint'], $rev);
        }
        if (($p['change'] ?? 'save') === 'save') {
            CopilotService::confirmApplied($tripId, $rev);
        }
        if (Schema::hasTable('tbl_trip_ai_cache')) {
            DB::table('tbl_trip_ai_cache')->where('tripId', $tripId)->where('rev', '<', $rev)->delete();
        }
    }
}
