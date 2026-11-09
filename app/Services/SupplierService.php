<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Nhà cung cấp dịch vụ tour và chất lượng: đánh giá, đúng giờ, sự cố, khiếu nại gắn với nhà cung cấp, tổng chi. */
class SupplierService
{
    public const KINDS = ['transport' => 'Vận chuyển', 'hotel' => 'Khách sạn', 'guide' => 'Hướng dẫn viên', 'restaurant' => 'Nhà hàng', 'attraction' => 'Điểm tham quan', 'insurance' => 'Bảo hiểm', 'other' => 'Khác'];
    /** Dưới mức này (khi đã có từ MIN_RATINGS đánh giá) hoặc tỷ lệ sự cố cao thì cần xem xét. */
    public const WARN_AVG = 3.5;
    public const WARN_INCIDENT_RATE = 0.25;
    public const MIN_RATINGS = 3;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_suppliers');
    }

    /** @return array<int,array<string,mixed>> supplierId => thống kê */
    public function stats(?int $onlyId = null): array
    {
        $suppliers = DB::table('tbl_suppliers')->when($onlyId, fn ($q) => $q->where('supplierId', $onlyId))->orderBy('name')->get();
        $out = [];
        foreach ($suppliers as $s) {
            $id = (int) $s->supplierId;
            $trips = (int) DB::table('tbl_departure_costs')->where('supplierId', $id)->distinct()->count('availabilityId');
            $spend = (int) DB::table('tbl_departure_costs')->where('supplierId', $id)->sum('amount');
            $rt = DB::table('tbl_supplier_ratings')->where('supplierId', $id);
            $n = (int) (clone $rt)->count();
            $avg = $n ? round((float) (clone $rt)->avg('rating'), 2) : null;
            $timed = (int) (clone $rt)->whereNotNull('onTime')->count();
            $onTimePct = $timed ? round((int) (clone $rt)->where('onTime', true)->count() / $timed * 100) : null;
            $incidents = Schema::hasColumn('tbl_tour_incidents', 'supplierId') ? (int) DB::table('tbl_tour_incidents')->where('supplierId', $id)->count() : 0;
            $complaints = Schema::hasTable('tbl_complaints') ? (int) DB::table('tbl_complaints')->where('supplierId', $id)->count() : 0;
            $rate = $trips > 0 ? round($incidents / $trips, 2) : null;
            $flags = [];
            if ($avg !== null && $n >= self::MIN_RATINGS && $avg < self::WARN_AVG) {
                $flags[] = 'Điểm đánh giá trung bình thấp (' . $avg . '/5)';
            }
            if ($rate !== null && $trips >= 3 && $rate >= self::WARN_INCIDENT_RATE) {
                $flags[] = 'Tỷ lệ sự cố cao (' . $incidents . '/' . $trips . ' đoàn)';
            }
            if ($complaints >= 3) {
                $flags[] = $complaints . ' khiếu nại gắn với nhà cung cấp này';
            }
            $out[$id] = ['supplier' => $s, 'trips' => $trips, 'spend' => $spend, 'ratings' => $n, 'avg' => $avg, 'onTimePct' => $onTimePct, 'incidents' => $incidents,
                'incidentRate' => $rate, 'complaints' => $complaints, 'flags' => $flags, 'attention' => $flags !== []];
        }

        return $out;
    }

    /** Đánh giá một nhà cung cấp cho một đoàn (mỗi cặp một lần). Chỉ sau ngày khởi hành và chỉ khi nhà cung cấp có chi phí ở đoàn đó. @return array{ok:bool,message:string} */
    public function rate(int $supplierId, int $availabilityId, int $rating, ?bool $onTime, ?string $comment, ?int $adminId): array
    {
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'message' => 'Điểm đánh giá từ 1 đến 5.'];
        }
        $dep = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->first();
        if (!$dep || $dep->departureDate > today()->toDateString()) {
            return ['ok' => false, 'message' => 'Chỉ đánh giá sau khi đoàn đã khởi hành.'];
        }
        if (!DB::table('tbl_departure_costs')->where('availabilityId', $availabilityId)->where('supplierId', $supplierId)->exists()) {
            return ['ok' => false, 'message' => 'Nhà cung cấp này không có chi phí ở đoàn này.'];
        }
        if (DB::table('tbl_supplier_ratings')->where('supplierId', $supplierId)->where('availabilityId', $availabilityId)->exists()) {
            return ['ok' => false, 'message' => 'Đã đánh giá nhà cung cấp này cho đoàn này.'];
        }
        DB::table('tbl_supplier_ratings')->insert(['supplierId' => $supplierId, 'availabilityId' => $availabilityId, 'rating' => $rating, 'onTime' => $onTime,
            'comment' => $comment ? mb_substr($comment, 0, 500) : null, 'ratedBy' => $adminId, 'createdAt' => now()]);

        return ['ok' => true, 'message' => 'Đã ghi nhận đánh giá.'];
    }
}
