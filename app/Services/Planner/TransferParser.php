<?php

namespace App\Services\Planner;

/**
 * Đọc giờ tới nơi / giờ về và phương tiện trong câu tự nhiên (luật, không cần LLM, chạy được khi AI tắt):
 *   "bay tới 14h, về chuyến 17h30 ngày cuối"   -> flight, 14:00, 17:30
 *   "đi tàu, tới nơi lúc 6 giờ sáng"            -> train, 06:00
 *   "xe khách về lúc 9 giờ tối"                 -> bus, leave 21:00
 * Chỉ nhận số đi kèm đơn vị giờ (14h, 14:30, 2 giờ chiều) nên "Đà Lạt 3 ngày" không bị hiểu nhầm.
 */
final class TransferParser
{
    private const TIME = '(\d{1,2})\s*(?:h|giờ|:)\s*(\d{2})?\s*(sáng|trưa|chiều|tối|đêm)?';

    /** @return array{moveKind?:string,arriveAt?:string,leaveAt?:string,said?:list<string>} */
    public static function parse(string $text): array
    {
        $t = mb_strtolower($text);
        $out = [];
        $kind = match (true) {
            (bool) preg_match('/máy bay|chuyến bay|\bbay\b|hạ cánh|cất cánh/u', $t) => 'flight',
            (bool) preg_match('/\btàu\b|tàu hỏa|tàu hoả|đường sắt/u', $t) => 'train',
            (bool) preg_match('/xe khách|xe giường nằm|limousine|xe buýt liên tỉnh/u', $t) => 'bus',
            (bool) preg_match('/tự lái|xe máy|ô tô riêng|xe riêng/u', $t) => 'self',
            default => null,
        };
        if ($kind) {
            $out['moveKind'] = $kind;
        }
        // tới nơi: "tới / đến / hạ cánh (nơi) (lúc) 14h" — không nhận "về tới"
        if (preg_match('/(?<!về )(?:tới|đến|hạ cánh|có mặt)(?:\s+nơi)?(?:\s+(?:lúc|khoảng|vào))?\s+' . self::TIME . '/u', $t, $m)) {
            $out['arriveAt'] = self::hm((int) $m[1], (int) ($m[2] ?? 0), $m[3] ?? '');
        }
        // về: "về (lúc / chuyến) 17h", "bay về 17h", "khởi hành về 17h", "chuyến về 17h"
        if (preg_match('/(?:khởi hành về|bay về|tàu về|xe về|chuyến về|\bvề)(?:\s+(?:lúc|chuyến|khoảng|vào))*\s+' . self::TIME . '/u', $t, $m)) {
            $out['leaveAt'] = self::hm((int) $m[1], (int) ($m[2] ?? 0), $m[3] ?? '');
        }
        return array_filter($out, fn($v) => $v !== null);
    }

    private static function hm(int $h, int $m, string $part): ?string
    {
        if (in_array($part, ['chiều', 'tối'], true) && $h < 12) {
            $h += 12;
        } elseif ($part === 'đêm' && $h < 5) {
            // 2 giờ đêm = 02:00
        } elseif ($part === 'đêm' && $h < 12) {
            $h += 12;
        } elseif ($part === 'trưa' && $h < 5) {
            $h += 12;
        }
        if ($h > 23 || $m > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /** Giờ tới / giờ về -> phút chơi được (cộng thời gian ra ga / sân bay, trừ thời gian có mặt sớm). */
    public static function minutes(array $data): array
    {
        $tr = PlannerRules::fromConfig()->transfer();
        $kind = $data['moveKind'] ?? 'self';
        $min = fn(string $hm) => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        if (!empty($data['arriveAt'])) {
            $data['arriveMin'] = min(1439, $min($data['arriveAt']) + ($tr['exit'][$kind] ?? 0));
        }
        if (!empty($data['leaveAt'])) {
            $data['leaveMin'] = max(0, $min($data['leaveAt']) - ($tr['before'][$kind] ?? 0));
        }

        return $data;
    }
}
