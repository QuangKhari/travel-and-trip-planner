<?php

namespace App\Support;

/** Tên tiếng Việt của `tbl_external_places.kind` (OSM). Kind lạ trả lại nguyên văn đã đổi "_" thành khoảng trắng. */
final class PlaceKind
{
    public const LABELS = [
        'restaurant' => 'Nhà hàng',
        'cafe' => 'Quán cà phê',
        'attraction' => 'Điểm tham quan',
        'peak' => 'Đỉnh núi',
        'hot_spring' => 'Suối nước nóng',
        'cave_entrance' => 'Hang động',
        'historic' => 'Di tích lịch sử',
        'viewpoint' => 'Điểm ngắm cảnh',
        'place_of_worship' => 'Nơi thờ tự',
        'museum' => 'Bảo tàng',
        'beach' => 'Bãi biển',
        'zoo' => 'Vườn thú',
        'theme_park' => 'Công viên giải trí',
        'gallery' => 'Phòng tranh',
        'aquarium' => 'Thủy cung',
    ];

    public static function label(?string $kind): ?string
    {
        if ($kind === null || trim($kind) === '') {
            return null;
        }

        return self::LABELS[$kind] ?? str_replace('_', ' ', $kind);
    }

    /** Nhóm kind theo config/planner.php (eat|nature|culture|fun|see); kind lạ rơi vào 'see'. */
    public static function group(?string $kind): string
    {
        foreach ((array) config('planner.kind_groups', []) as $group => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return (string) $group;
            }
        }

        return 'see';
    }
}
