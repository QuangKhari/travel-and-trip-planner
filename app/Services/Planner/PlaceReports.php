<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Người dùng báo thông tin địa điểm sai (giờ, giá, đã đóng cửa hẳn, sai vị trí). Admin duyệt:
 *   hours  + đề xuất dạng OSM ("Mo-Su 08:00-17:00")  -> ghi đè giờ mở cửa (nguồn "report")
 *   price  + đề xuất là số                          -> cập nhật priceMin (địa điểm có cột giá / trải nghiệm)
 *   closed                                          -> quy tắc "đóng cửa" từ hôm nay (bộ xếp lịch và chuông báo cho các chuyến bị ảnh hưởng)
 *   location / other                                -> chỉ đánh dấu đã xử lý (sửa tay ở trang nội dung)
 */
final class PlaceReports
{
    public const KINDS = ['hours' => 'Giờ mở cửa sai', 'price' => 'Giá sai', 'closed' => 'Đã đóng cửa / ngừng hoạt động', 'location' => 'Sai vị trí', 'other' => 'Khác'];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_place_reports');
    }

    public static function name(string $type, int $id): ?string
    {
        return match ($type) {
            'place' => DB::table('tbl_external_places')->where('placeId', $id)->value('name'),
            'experience' => DB::table('tbl_experiences')->where('experienceId', $id)->value('name'),
            default => null,
        };
    }

    /** @return array{ok:bool,message:string} */
    public function accept(object $r, int $adminId, ?string $note = null): array
    {
        $msg = 'Đã ghi nhận.';
        if ($r->kind === 'hours') {
            if (!$r->suggested || !OpeningHours::parseOsm($r->suggested)) {
                return ['ok' => false, 'message' => 'Đề xuất giờ chưa đúng dạng (vd. "Mo-Su 08:00-17:00; Mo off"). Sửa ô đề xuất rồi duyệt lại.'];
            }
            app(OpeningHours::class)->store($r->refType, (int) $r->refId, $r->suggested, 'report');
            $msg = 'Đã cập nhật giờ mở cửa.';
        } elseif ($r->kind === 'price') {
            $v = (int) preg_replace('/\D/', '', (string) $r->suggested);
            if ($v <= 0 && trim((string) $r->suggested) !== '0') {
                return ['ok' => false, 'message' => 'Đề xuất giá chưa phải số (đồng).'];
            }
            $tbl = $r->refType === 'place' ? 'tbl_external_places' : 'tbl_experiences';
            $key = $r->refType === 'place' ? 'placeId' : 'experienceId';
            if (Schema::hasColumn($tbl, 'priceMin')) {
                DB::table($tbl)->where($key, $r->refId)->update(['priceMin' => $v, 'priceMax' => DB::raw('GREATEST(COALESCE(priceMax, 0), ' . $v . ')')]);
            }
            $msg = 'Đã cập nhật giá.';
        } elseif ($r->kind === 'closed') {
            DB::table('tbl_hours_rules')->insert(['refType' => $r->refType, 'refId' => $r->refId, 'kind' => 'closed', 'dateFrom' => now()->toDateString(),
                'dateTo' => now()->addYears(5)->toDateString(), 'note' => 'người dùng báo, đã xác minh', 'source' => 'report', 'createdBy' => $adminId]);
            $msg = 'Đã đánh dấu đóng cửa: các chuyến có nơi này sẽ thấy lỗi "đóng cửa" và nhận thông báo ở lần theo dõi kế tiếp.';
        }
        DB::table('tbl_place_reports')->where('reportId', $r->reportId)->update(['status' => 'accepted', 'reviewedBy' => $adminId, 'reviewedAt' => now(), 'reviewNote' => $note]);

        return ['ok' => true, 'message' => $msg];
    }
}
