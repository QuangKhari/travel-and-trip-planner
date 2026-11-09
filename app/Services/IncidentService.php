<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Nhật ký sự cố tour và liên kết với phương án Departure Rescue. */
class IncidentService
{
    public const TYPES = [
        'vehicle' => 'Phương tiện', 'hotel' => 'Khách sạn / lưu trú', 'guide' => 'Hướng dẫn viên', 'weather' => 'Thời tiết / thiên tai',
        'health' => 'Sức khỏe / an toàn của khách', 'supplier' => 'Nhà cung cấp / điểm tham quan', 'schedule' => 'Lịch trình / giờ giấc', 'other' => 'Khác',
    ];
    public const SEVERITIES = ['low' => 'Thấp', 'medium' => 'Trung bình', 'high' => 'Cao', 'critical' => 'Khẩn cấp'];
    public const STATUSES = ['open' => 'Mới', 'handling' => 'Đang xử lý', 'resolved' => 'Đã xử lý', 'closed' => 'Đã đóng'];
    /** Trạng thái được chuyển tới từ trạng thái hiện tại. */
    public const FLOW = ['open' => ['handling', 'resolved'], 'handling' => ['resolved'], 'resolved' => ['handling', 'closed'], 'closed' => []];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_tour_incidents') && Schema::hasTable('tbl_incident_events');
    }

    public static function log(?int $incidentId, ?int $adminId, string $kind, string $message): void
    {
        if (!$incidentId || !self::ready()) {
            return;
        }
        DB::table('tbl_incident_events')->insert(['incidentId' => $incidentId, 'adminId' => $adminId, 'kind' => mb_substr($kind, 0, 12), 'message' => $message, 'createdAt' => now()]);
        DB::table('tbl_tour_incidents')->where('incidentId', $incidentId)->update(['updatedAt' => now()]);
    }

    /** Gắn phương án Departure Rescue vào sự cố: sự cố chuyển sang "Đang xử lý". */
    public static function linkPlan(int $incidentId, int $planId, ?int $adminId): void
    {
        if (!self::ready()) {
            return;
        }
        DB::table('tbl_departure_rescue_plans')->where('planId', $planId)->update(['incidentId' => $incidentId]);
        DB::table('tbl_tour_incidents')->where('incidentId', $incidentId)->update(['rescuePlanId' => $planId]);
        DB::table('tbl_tour_incidents')->where('incidentId', $incidentId)->where('status', 'open')->update(['status' => 'handling']);
        self::log($incidentId, $adminId, 'plan', 'Đã lập phương án xử lý Departure Rescue #' . $planId . ' cho sự cố này.');
    }
}
