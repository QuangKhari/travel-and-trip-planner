<?php

namespace App\Services\Ai\Copilot;

use Carbon\Carbon;

/**
 * Lệnh cấp NGÀY / CHUYẾN mà La bàn hiểu bằng luật (không gọi LLM, chạy cả khi AI tắt):
 *   clear_day {day}            "bỏ ngày 2", "xóa hết lịch ngày 3"         -> mọi mục của ngày đó về Đã lưu
 *   swap      {a, b}           "đổi ngày 1 với ngày 3", "hoán đổi ngày 2 và 4"
 *   shift     {to:Y-m-d}       "dời chuyến sang 20/12", "lùi chuyến 2 ngày", "đi sớm hơn 1 ngày"
 *   days      {n}              "rút còn 3 ngày", "thêm 1 ngày", "bớt 2 ngày", "đổi thành 5 ngày"
 *   budget    {amount}         "giảm xuống 3 triệu", "ngân sách chỉ còn 2,5 triệu", "tiết kiệm còn 800k"
 * Hàm thuần (dễ kiểm thử). Câu không khớp mẫu nào -> null, để LLM xử lý như cũ.
 */
final class TripCommands
{
    /** @return ?array{cmd:string,day?:int,a?:int,b?:int,to?:string,n?:int} */
    public static function parse(string $msg, int $days, ?string $startDate, ?Carbon $today = null): ?array
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $msg) ?? ''));
        if ($s === '' || mb_strlen($s) > 120) {
            return null;
        }
        // ngân sách: số tiền đi kèm "triệu / tr / k / nghìn / đ" và động từ giảm / còn / dưới
        if ($amt = self::money($s)) {
            if (preg_match('/(?:giảm|hạ|cắt|bớt|tiết kiệm|đưa|kéo)\s+(?:chi phí|ngân sách|tiền|tổng|chi tiêu)?\s*(?:chuyến\s+)?(?:đi\s+)?(?:xuống|còn|dưới|về)(?![\p{L}\d])/u', $s)
                || preg_match('/(?:ngân sách|chi phí|tổng)\s+(?:chỉ\s+)?(?:còn|có|là|tối đa|dưới|không quá)(?![\p{L}\d])/u', $s)
                || preg_match('/^(?:chỉ\s+)?(?:có|còn)\s+[\d.,]+\s*(?:triệu|tr|k|nghìn|ngàn)/u', $s)) {
                return ['cmd' => 'budget', 'amount' => $amt];
            }
        }
        $num = '(\d{1,2}|một|hai|ba|bốn|năm|sáu|bảy|tám|chín|mười)';
        $n = fn(string $x) => is_numeric($x) ? (int) $x : (['một' => 1, 'hai' => 2, 'ba' => 3, 'bốn' => 4, 'năm' => 5, 'sáu' => 6, 'bảy' => 7, 'tám' => 8, 'chín' => 9, 'mười' => 10][$x] ?? 0);

        // đổi chỗ hai ngày
        if (preg_match('/(?:đổi|hoán đổi|tráo|đảo)\s+(?:lịch\s+)?(?:của\s+)?ngày\s*' . $num . '\s*(?:với|và|cho|sang|thành)\s*(?:lịch\s+)?(?:ngày\s*)?' . $num . '\b/u', $s, $m)) {
            $a = $n($m[1]);
            $b = $n($m[2]);
            if ($a >= 1 && $b >= 1 && $a <= $days && $b <= $days && $a !== $b) {
                return ['cmd' => 'swap', 'a' => min($a, $b), 'b' => max($a, $b)];
            }
        }
        // bỏ cả một ngày (không phải "bỏ <mục> ở ngày 2")
        if (preg_match('/^(?:la bàn\s*,?\s*)?(?:bỏ|xóa|xoá|hủy|huỷ|dọn)\s+(?:hết\s+|toàn bộ\s+|cả\s+)?(?:lịch\s+|các mục\s+)?(?:của\s+)?ngày\s*' . $num . '(?:\s+(?:đi|giúp|giùm|hộ|cho|mình|tôi|em|nhé|nha|với|luôn|ạ))*\s*[.!]?$/u', $s, $m)) {
            $d = $n($m[1]);
            if ($d >= 1 && $d <= $days) {
                return ['cmd' => 'clear_day', 'day' => $d];
            }
        }
        // số ngày của chuyến
        if (preg_match('/(?:rút|giảm|đổi|tăng|kéo dài|chỉnh|sửa)\s+(?:chuyến\s+)?(?:đi\s+)?(?:xuống\s+|lên\s+|còn\s+|thành\s+|ra\s+)?' . $num . '\s*ngày/u', $s, $m)
            && preg_match('/còn|thành|xuống|lên|ra/u', $s)) {
            $v = $n($m[1]);
            if ($v >= 1 && $v !== $days) {
                return ['cmd' => 'days', 'n' => $v];
            }
        }
        if (preg_match('/(thêm|bớt|cắt|giảm)\s+' . $num . '\s*ngày/u', $s, $m) && !preg_match('/vào ngày|ngày\s*\d+\s*(?:thêm|bớt)/u', $s)) {
            $v = $days + (in_array($m[1], ['thêm'], true) ? 1 : -1) * $n($m[2]);
            if ($v >= 1 && $v !== $days) {
                return ['cmd' => 'days', 'n' => $v];
            }
        }
        // dời cả chuyến
        if ($startDate && preg_match('/(?:dời|lùi|chuyển|đổi|hoãn)\s+(?:cả\s+|toàn bộ\s+)?(?:chuyến|ngày đi|lịch)/u', $s)) {
            $start = Carbon::parse($startDate)->startOfDay();
            $today ??= now()->startOfDay();
            if (preg_match('/(\d{1,2})\s*[\/\-.]\s*(\d{1,2})(?:\s*[\/\-.]\s*(\d{2,4}))?/u', $s, $m)) {
                $y = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) $today->year;
                if (!checkdate((int) $m[2], (int) $m[1], $y)) {
                    return null;
                }
                $to = Carbon::create($y, (int) $m[2], (int) $m[1])->startOfDay();
                if (!isset($m[3]) && $to->lt($today)) {
                    $to->addYear();
                }

                return $to->eq($start) ? null : ['cmd' => 'shift', 'to' => $to->toDateString()];
            }
            if (preg_match('/' . $num . '\s*(ngày|tuần)\s*(sau|tới|nữa|muộn hơn)?/u', $s, $m)) {
                $k = $n($m[1]) * ($m[2] === 'tuần' ? 7 : 1);
                $back = (bool) preg_match('/sớm hơn|lên trước|về trước|trước\s+' . $num . '/u', $s);

                return $k ? ['cmd' => 'shift', 'to' => $start->copy()->addDays($back ? -$k : $k)->toDateString()] : null;
            }
        }
        if ($startDate && preg_match('/đi\s+(sớm|muộn|trễ)\s+hơn\s+' . $num . '\s*ngày/u', $s, $m)) {
            $k = $n($m[2]);

            return ['cmd' => 'shift', 'to' => Carbon::parse($startDate)->addDays($m[1] === 'sớm' ? -$k : $k)->toDateString()];
        }

        return null;
    }

    /** "3 triệu" / "2,5tr" / "800k" / "1.500.000đ" -> số đồng; không có đơn vị tiền -> null. */
    public static function money(string $s): ?int
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(triệu|tr|trieu|củ)(?![\p{L}\d])/u', $s, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]) * 1_000_000);
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngàn)(?![\p{L}\d])/u', $s, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]) * 1000);
        }
        if (preg_match('/(\d{1,3}(?:[.,]\d{3})+|\d{5,})\s*(?:đ|đồng|vnd)(?![\p{L}\d])/u', $s, $m)) {
            return (int) preg_replace('/\D/', '', $m[1]);
        }

        return null;
    }
}
