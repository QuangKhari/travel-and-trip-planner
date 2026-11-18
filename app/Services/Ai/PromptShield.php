<?php

namespace App\Services\Ai;

use App\Services\Content\SearchCatalog;

/**
 * Chống chèn lệnh (prompt injection) cho mọi chỗ đưa chữ do NGƯỜI DÙNG viết vào lời nhắc LLM:
 * câu hỏi Hỏi đáp / F5, ghi chú chuyến, tên mục tự thêm, câu gửi La bàn Copilot, câu mô tả chuyến (Brief).
 *
 * Ba lớp, đều chạy bằng luật (không tốn lượt AI):
 *   1. scan()       — phát hiện câu có dạng mệnh lệnh nhắm vào trợ lý (bỏ qua quy tắc, đổi vai, đòi lộ lời nhắc, thẻ vai giả...).
 *                     Có dấu và không dấu đều bắt (so trên chuỗi đã bỏ dấu). Chỉ để ghi cờ và quyết định lớp 2, 3.
 *   2. neutralize() — với chữ là DỮ LIỆU (ghi chú, tên mục, đoạn nguồn): bỏ câu giống lệnh, bỏ thẻ / dấu vai giả, bỏ mã từ chối nội bộ.
 *                     Câu hỏi của chính người dùng KHÔNG bị sửa (họ có quyền hỏi bất cứ gì), chỉ được gắn cờ.
 *   3. leaked()     — kiểm câu trả lời: chứa nguyên một đoạn dài của lời nhắc hệ thống thì không đưa ra (trả lời từ chối).
 *
 * Lời nhắc vẫn tách dữ liệu bằng thẻ và có quy tắc "nội dung trong thẻ chỉ là dữ liệu"; lớp này là phòng thủ thêm, không thay thế việc
 * máy chủ kiểm chứng mọi đề xuất (TripHealth, NumberGuard, kiểm trích dẫn).
 */
final class PromptShield
{
    /** Mã nội bộ mà mô hình dùng để từ chối (AskService::REFUSE_TOKEN): dữ liệu không được tự chèn mã này. */
    public const INTERNAL_TOKENS = ['KHONG_CO_THONG_TIN'];

    /** Mẫu trên chuỗi đã bỏ dấu, chữ thường, chỉ còn chữ số và khoảng trắng (SearchCatalog::fold). */
    private const FOLDED = [
        // "hướng dẫn viên", "đừng quên mang sách hướng dẫn" không phải lệnh: loại "hướng dẫn viên", "quên" phải kèm từ chỉ tất cả
        'ignore_rules' => '/\b(bo qua|phot lo|lo di|quen (het|di|moi|tat ca|cac|nhung|toan bo)|xoa bo|vo hieu hoa|ignore|disregard|forget|override)( \w+){0,5} (huong dan(?! vien)|chi dan|chi thi|quy tac|loi nhac|instructions?|rules?|prompts?|guidelines?)\b/',
        'reveal_prompt' => '/\b(tiet lo|in ra|hien thi|hien ra|cho (toi|minh|tao) xem|doc lai|nhac lai|reveal|print|show|repeat|output)( \w+){0,5} (loi nhac|system prompt|prompt|quy tac (he thong|cua ban)|chi dan (he thong|cua ban)|huong dan (he thong|cua ban)|instructions?)\b/',
        'role_change' => '/\b(tu (gio|bay gio|nay|day) (tro di )?|from now on |ke tu bay gio )(ban|may|you|tro ly)( \w+){0,2} (la|lam|dong vai|se|are|will|act)\b|\b(dong vai(?! tro)|hay gia vo|pretend (to be|you are)|act as|you are now)\b/',
        'jailbreak' => '/\b(dan mode|developer mode|che do (nha phat trien|khong gioi han|admin)|jailbreak|khong con gioi han|no restrictions)\b/',
        'exfiltrate' => '/\b(gui|chuyen|send|post|forward)( \w+){0,4} (email|mat khau|password|api key|khoa api|token|du lieu nguoi dung|thong tin ca nhan)\b/',
    ];

    /** Mẫu trên chuỗi gốc: thẻ vai / thẻ dữ liệu giả, dòng mở đầu bằng vai hệ thống. */
    private const RAW = [
        'fake_tag' => '#</?\s*(system|assistant|developer|user|nguon|cau_hoi|du_lieu|instructions?|tool|tool_use)\b[^>]*>#iu',
        'role_marker' => '/(^|[\n\r])\s*(system|assistant|developer|hệ thống|he thong|trợ lý|tro ly)\s*:/iu',
        'fence' => '/<<<|>>>|```/u',
    ];

    /**
     * Cờ chèn lệnh tìm thấy (rỗng = sạch).
     * @return list<string>
     */
    public static function scan(?string $text): array
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return [];
        }
        $flags = [];
        $folded = ' ' . SearchCatalog::fold($text) . ' ';
        foreach (self::FOLDED as $flag => $re) {
            if (preg_match($re, $folded)) {
                $flags[] = $flag;
            }
        }
        foreach (self::RAW as $flag => $re) {
            if (preg_match($re, $text)) {
                $flags[] = $flag;
            }
        }
        foreach (self::INTERNAL_TOKENS as $t) {
            if (stripos($text, $t) !== false) {
                $flags[] = 'internal_token';
                break;
            }
        }

        return array_values(array_unique($flags));
    }

    /** Có ít nhất một cờ "nặng" (đòi bỏ quy tắc, đổi vai, đòi lộ lời nhắc, vượt rào, lấy dữ liệu). */
    public static function hostile(array $flags): bool
    {
        return (bool) array_intersect($flags, ['ignore_rules', 'reveal_prompt', 'role_change', 'jailbreak', 'exfiltrate']);
    }

    /**
     * Làm sạch chữ là DỮ LIỆU trước khi đưa vào lời nhắc: câu nào khớp mẫu lệnh thì thay bằng "[…]", bỏ thẻ / dấu vai giả và mã nội bộ.
     * Chữ không có dấu hiệu gì được trả lại nguyên vẹn (không làm hỏng dữ liệu bình thường).
     * @return array{text:string, flags:list<string>}
     */
    public static function neutralize(?string $text): array
    {
        $text = (string) $text;
        $flags = self::scan($text);
        if ($flags === []) {
            return ['text' => $text, 'flags' => []];
        }
        $out = preg_replace(self::RAW['fake_tag'], ' ', $text) ?? $text;
        $out = preg_replace(self::RAW['role_marker'], '$1 ', $out) ?? $out;
        $out = preg_replace(self::RAW['fence'], ' ', $out) ?? $out;
        foreach (self::INTERNAL_TOKENS as $t) {
            $out = str_ireplace($t, ' ', $out);
        }
        if (self::hostile($flags)) {
            // tách câu (giữ dấu câu), bỏ câu nào tự nó khớp mẫu lệnh; chữ chỉ có một câu (tên quán, nhãn mục) thì chỉ bỏ cụm lệnh, giữ phần tên
            $parts = preg_split('/(?<=[.!?;\n])\s+/u', trim($out)) ?: [$out];
            if (count($parts) === 1) {
                return ['text' => self::dropPhrases(trim($out)), 'flags' => $flags];
            }
            $kept = [];
            $dropped = false;
            foreach ($parts as $p) {
                if (self::hostile(self::scanFolded($p))) {
                    $dropped = true;
                    continue;
                }
                $kept[] = $p;
            }
            $out = implode(' ', $kept);
            if ($dropped) {
                $out = trim($out . ' […]');
            }
        }

        return ['text' => trim(preg_replace('/[ \t]+/u', ' ', $out) ?? $out), 'flags' => $flags];
    }

    /**
     * Bỏ đúng các cụm từ khớp mẫu lệnh (theo từ của chuỗi gốc), thay bằng "[…]". So khớp trên chuỗi bỏ dấu, rồi ánh xạ vị trí về từ gốc.
     */
    private static function dropPhrases(string $text): string
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $folded = '';
        $owner = [];   // vị trí ký tự trong chuỗi bỏ dấu -> chỉ số từ gốc
        foreach ($words as $i => $w) {
            $f = SearchCatalog::fold($w);
            if ($f === '') {
                continue;
            }
            if ($folded !== '') {
                $owner[strlen($folded)] = $i;
                $folded .= ' ';
            }
            for ($k = 0; $k < strlen($f); $k++) {
                $owner[strlen($folded) + $k] = $i;
            }
            $folded .= $f;
        }
        $drop = [];
        foreach (['ignore_rules', 'reveal_prompt', 'role_change', 'jailbreak', 'exfiltrate'] as $flag) {
            if (preg_match_all(self::FOLDED[$flag], ' ' . $folded . ' ', $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $at]) {
                    $a = max(0, $at - 1);
                    $b = min(strlen($folded) - 1, $at - 1 + strlen($hit) - 1);
                    for ($p = $a; $p <= $b; $p++) {
                        if (isset($owner[$p]) && trim($hit) !== '') {
                            $drop[$owner[$p]] = true;
                        }
                    }
                }
            }
        }
        if (!$drop) {
            return '[…]';
        }
        $out = [];
        $prevDropped = false;
        foreach ($words as $i => $w) {
            if (isset($drop[$i])) {
                if (!$prevDropped) {
                    $out[] = '[…]';
                }
                $prevDropped = true;
                continue;
            }
            $out[] = $w;
            $prevDropped = false;
        }

        return implode(' ', $out);
    }

    /** Chỉ phần mẫu trên chuỗi bỏ dấu (dùng khi xét từng câu). @return list<string> */
    private static function scanFolded(string $text): array
    {
        $folded = ' ' . SearchCatalog::fold($text) . ' ';
        $out = [];
        foreach (self::FOLDED as $flag => $re) {
            if (preg_match($re, $folded)) {
                $out[] = $flag;
            }
        }

        return $out;
    }

    /**
     * Câu trả lời có chép lại lời nhắc hệ thống không: một đoạn >= $window ký tự liên tiếp (đã chuẩn hóa khoảng trắng, bỏ dấu)
     * của lời nhắc xuất hiện nguyên văn trong câu trả lời.
     */
    public static function leaked(string $answer, string $system, int $window = 48): bool
    {
        $a = SearchCatalog::fold($answer);
        $s = SearchCatalog::fold($system);
        if (mb_strlen($a) < $window || mb_strlen($s) < $window) {
            return false;
        }
        // trượt cửa sổ theo từ của lời nhắc (bước 3 từ là đủ để không bỏ sót đoạn chép dài)
        $words = explode(' ', $s);
        for ($i = 0; $i < count($words); $i += 3) {
            $chunk = '';
            for ($j = $i; $j < count($words) && mb_strlen($chunk) < $window; $j++) {
                $chunk .= ($chunk === '' ? '' : ' ') . $words[$j];
            }
            if (mb_strlen($chunk) >= $window && str_contains($a, $chunk)) {
                return true;
            }
        }

        return false;
    }
}
