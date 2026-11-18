<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trí nhớ theo từng chuyến của La bàn (mục AI "trợ lý ra quyết định"):
 *  - rejected : nơi người dùng đã bỏ qua khi La bàn đề xuất (tự ghi khi bấm Bỏ qua) -> không đề xuất lại trong chuyến này;
 *  - prefer / avoid / note : điều người dùng tự dặn cho chuyến ("ăn chay", "tránh leo dốc", "đi chậm") -> đưa vào bối cảnh Copilot.
 * Người dùng xem và xóa từng dòng, hoặc xóa hết. Khác "Travela nhớ gì về bạn" (sở thích chung theo người, tbl_user_taste):
 * trí nhớ này chỉ thuộc một chuyến và mọi thành viên sửa được chuyến đều thấy.
 * Chữ người dùng gõ đi qua PromptShield trước khi vào lời nhắc.
 */
final class TripMemory
{
    public const KINDS = ['rejected' => 'Đã từ chối', 'prefer' => 'Muốn', 'avoid' => 'Tránh', 'note' => 'Lưu ý'];
    public const MAX = 40;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_trip_memory');
    }

    /** @return list<array{id:int,kind:string,kindLabel:string,label:string,source:string,refType:?string,refId:?int,at:string}> */
    public static function list(int $tripId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('tbl_trip_memory')->where('tripId', $tripId)->orderByDesc('memoryId')->limit(self::MAX)->get()
            ->map(fn($r) => ['id' => (int) $r->memoryId, 'kind' => $r->kind, 'kindLabel' => self::KINDS[$r->kind] ?? $r->kind, 'label' => $r->label,
                'source' => $r->source, 'refType' => $r->refType, 'refId' => $r->refId !== null ? (int) $r->refId : null, 'at' => (string) $r->createdAt])->all();
    }

    /** Khóa "type:refId" của các nơi đã từ chối (để loại khỏi đề xuất). @return array<string,true> */
    public static function rejected(int $tripId): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_trip_memory')->where(['tripId' => $tripId, 'kind' => 'rejected'])->get(['refType', 'refId']) as $r) {
            $out[$r->refType . ':' . $r->refId] = true;
        }

        return $out;
    }

    public static function reject(int $tripId, string $type, int $refId, string $name, string $source = 'copilot'): void
    {
        if (!self::ready() || $refId <= 0) {
            return;
        }
        DB::table('tbl_trip_memory')->insertOrIgnore(['tripId' => $tripId, 'kind' => 'rejected', 'refType' => mb_substr($type, 0, 20), 'refId' => $refId,
            'label' => mb_substr('Không đề xuất lại “' . $name . '”', 0, 160), 'source' => $source, 'createdAt' => now()]);
        self::trim($tripId);
    }

    /** Người dùng tự dặn. @return ?int mã dòng mới (null nếu trống / trùng) */
    public static function add(int $tripId, string $kind, string $text): ?int
    {
        if (!self::ready() || !in_array($kind, ['prefer', 'avoid', 'note'], true)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        $text = mb_substr(PromptShield::neutralize($text)['text'], 0, 160);
        if ($text === '' || $text === '[…]') {
            return null;
        }
        if (DB::table('tbl_trip_memory')->where(['tripId' => $tripId, 'kind' => $kind, 'label' => $text])->exists()) {
            return null;
        }
        $id = (int) DB::table('tbl_trip_memory')->insertGetId(['tripId' => $tripId, 'kind' => $kind, 'refType' => null, 'refId' => null,
            'label' => $text, 'source' => 'user', 'createdAt' => now()], 'memoryId');
        self::trim($tripId);

        return $id;
    }

    public static function forget(int $tripId, ?int $memoryId): int
    {
        if (!self::ready()) {
            return 0;
        }
        $q = DB::table('tbl_trip_memory')->where('tripId', $tripId);

        return $memoryId ? $q->where('memoryId', $memoryId)->delete() : $q->delete();
    }

    /** Dòng đưa vào bảng bối cảnh của Copilot (chỉ điều người dùng dặn + số nơi đã từ chối). */
    public static function promptLines(int $tripId): array
    {
        $L = [];
        $rej = 0;
        foreach (self::list($tripId) as $m) {
            if ($m['kind'] === 'rejected') {
                $rej++;
                continue;
            }
            $L[] = 'Người dùng dặn cho chuyến này (' . mb_strtolower($m['kindLabel']) . '): ' . str_replace(['<', '>', '{', '}'], ' ', $m['label']);
        }
        if ($rej) {
            $L[] = 'Đã có ' . $rej . ' nơi người dùng từ chối trước đó: không đề xuất lại (máy chủ đã loại khỏi kết quả tìm).';
        }

        return $L;
    }

    private static function trim(int $tripId): void
    {
        $keep = DB::table('tbl_trip_memory')->where('tripId', $tripId)->orderByDesc('memoryId')->limit(self::MAX)->pluck('memoryId');
        if ($keep->count() >= self::MAX) {
            DB::table('tbl_trip_memory')->where('tripId', $tripId)->whereNotIn('memoryId', $keep)->delete();
        }
    }
}
