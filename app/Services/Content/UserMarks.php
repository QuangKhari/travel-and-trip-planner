<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * B6: đánh dấu của người dùng (lưu, đã đến, đã thử, đã dự) trong tbl_user_marks.
 * Huy hiệu và bộ đếm ở Sổ tay du hành (E1/E2) tính bằng truy vấn đếm từ bảng này, không lưu riêng.
 */
class UserMarks
{
    /** loại đánh dấu => các refType được phép */
    public const RULES = [
        'saved' => ['destination', 'food', 'event'],
        'visited' => ['destination'],
        'tried' => ['food'],
        'attended' => ['event'],
    ];

    private const TABLE = ['destination' => ['tbl_destinations', 'destinationId'], 'food' => ['tbl_foods', 'foodId'], 'event' => ['tbl_events', 'eventId']];

    public static function allowed(string $type, string $refType): bool
    {
        return in_array($refType, self::RULES[$type] ?? [], true);
    }

    /** Giải slug thành khóa chính; chỉ nội dung đang hiển thị công khai mới đánh dấu được. */
    public function resolve(string $refType, string $slug): ?int
    {
        if (!isset(self::TABLE[$refType]) || !preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
            return null;
        }
        [$table, $key] = self::TABLE[$refType];
        $q = DB::table($table)->where('slug', $slug);
        $refType === 'destination' ? $q->where('isActive', 1) : $q->where('reviewStatus', 'published');
        $id = $q->value($key);

        return $id ? (int) $id : null;
    }

    /** Bật/tắt. Trả về trạng thái sau khi đổi (true = đang đánh dấu). */
    public function toggle(int $userId, string $type, string $refType, int $refId): bool
    {
        $w = ['userId' => $userId, 'type' => $type, 'refType' => $refType, 'refId' => $refId];
        if (DB::table('tbl_user_marks')->where($w)->exists()) {
            DB::table('tbl_user_marks')->where($w)->delete();

            return false;
        }
        DB::table('tbl_user_marks')->insertOrIgnore($w + ['markedAt' => now()]);

        return true;
    }

    /** @return list<string> các loại đang đánh dấu của một mục, ví dụ ['saved','visited'] */
    public function typesFor(?int $userId, string $refType, int $refId): array
    {
        if (!$userId) {
            return [];
        }

        return DB::table('tbl_user_marks')->where(['userId' => $userId, 'refType' => $refType, 'refId' => $refId])->pluck('type')->all();
    }

    /** Bộ đếm cho Sổ tay: ['visited' => n, 'tried' => n, 'attended' => n, 'saved' => n]. */
    public function counts(int $userId): array
    {
        $c = DB::table('tbl_user_marks')->where('userId', $userId)->selectRaw('type, COUNT(*) as n')->groupBy('type')->pluck('n', 'type');

        return array_map(fn($t) => (int) ($c[$t] ?? 0), array_combine(array_keys(self::RULES), array_keys(self::RULES)));
    }
}
