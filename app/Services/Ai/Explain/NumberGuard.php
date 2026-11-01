<?php

namespace App\Services\Ai\Explain;

/**
 * NumberGuard (tài liệu v2.2, mục 5.2): mọi con số trong lời AI viết phải có mặt trong dữ kiện đã đưa vào lời nhắc.
 * Chuẩn hóa trước khi so:
 *   - giờ:   8:30 = 08:30 = 8h30 = 8 giờ 30        -> t:8:30
 *   - ngày:  23/11 = 23/11/2026                    -> d:23/11
 *   - tiền:  1.200.000 = 1,2 triệu = 1200k          -> n:1200000   ("tr", "nghìn", "ngàn", "k" đều hiểu)
 *   - số thập phân kiểu Việt: 2,1 km               -> n:2.1
 *   - số viết bằng chữ một..mười khi đứng trước đơn vị ("ba ngày") -> n:3
 * Hàm thuần, không phụ thuộc Laravel.
 */
final class NumberGuard
{
    private const WORDS = ['một' => 1, 'hai' => 2, 'ba' => 3, 'bốn' => 4, 'tư' => 4, 'năm' => 5, 'sáu' => 6, 'bảy' => 7, 'tám' => 8, 'chín' => 9, 'mười' => 10];
    private const UNITS = 'ngày|đêm|người|giờ|tiếng|km|phút|điểm|nơi|bữa|món|lần|buổi|mục|hoạt động';

    /** @var array<string,true> */
    private array $allowed = [];

    /**
     * @param list<string> $sources mọi chuỗi dữ kiện đã đưa cho LLM
     * @param list<int|float> $always số luôn hợp lệ (thứ tự ngày, số người, số ngày)
     */
    public function __construct(array $sources = [], array $always = [])
    {
        foreach ($sources as $s) {
            foreach (self::extract((string) $s) as $tok) {
                $this->allow($tok);
            }
        }
        foreach ($always as $n) {
            $this->allow('n:' . self::num((float) $n));
        }
    }

    /** Thêm một số (vd thời lượng 90 phút -> cũng cho phép "1,5 giờ", "1 giờ 30"). */
    public function allowMinutes(int $min): void
    {
        $this->allow('n:' . $min);
        if ($min >= 60) {
            $this->allow('n:' . self::num($min / 60));
            $this->allow('t:' . intdiv($min, 60) . ':' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT));
            $this->allow('n:' . intdiv($min, 60));
            if ($min % 60) {
                $this->allow('n:' . ($min % 60));
            }
        }
    }

    private function allow(string $tok): void
    {
        $this->allowed[$tok] = true;
        // số thập phân: cho phép làm tròn tới số nguyên và 1 chữ số thập phân
        if (str_starts_with($tok, 'n:') && str_contains($tok, '.')) {
            $v = (float) substr($tok, 2);
            $this->allowed['n:' . self::num(round($v, 1))] = true;
            $this->allowed['n:' . self::num(round($v))] = true;
        }
    }

    /** @return list<string> các số trong $text KHÔNG có căn cứ */
    public function unknown(string $text): array
    {
        return array_values(array_filter(self::extract($text), fn($t) => !isset($this->allowed[$t])));
    }

    public function passes(string $text): bool
    {
        return $this->unknown($text) === [];
    }

    /** @return list<string> */
    public static function extract(string $text): array
    {
        $s = ' ' . mb_strtolower($text) . ' ';
        $out = [];
        $take = function (string $re, callable $f) use (&$s, &$out): void {
            $s = preg_replace_callback($re, function ($m) use ($f, &$out) {
                $out[] = $f($m);

                return ' ';
            }, $s) ?? $s;
        };
        // ngày dd/mm(/yyyy)
        $take('/(?<![\d.,])(\d{1,2})\/(\d{1,2})(?:\/\d{4})?(?![\d\/])/u', fn($m) => 'd:' . (int) $m[1] . '/' . (int) $m[2]);
        // giờ 8:30, 8h30, 8 giờ 30
        $take('/(?<![\d.,])(\d{1,2})\s*(?::|h|g|giờ)\s*(\d{2})(?!\d)/u', fn($m) => 't:' . (int) $m[1] . ':' . $m[2]);
        // tiền: 1,2 triệu / 5 tr / 500 nghìn / 500k
        $take('/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(triệu|tr)(?!\p{L})/u', fn($m) => 'n:' . self::num((float) str_replace(',', '.', $m[1]) * 1_000_000));
        $take('/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(nghìn|ngàn|k)(?!\p{L})/u', fn($m) => 'n:' . self::num((float) str_replace(',', '.', $m[1]) * 1_000));
        // 1.200.000 (dấu chấm ngăn nghìn)
        $take('/(?<![\d.,])(\d{1,3}(?:\.\d{3})+)(?![\d,])/u', fn($m) => 'n:' . (int) str_replace('.', '', $m[1]));
        // số thập phân / số nguyên (dấu phẩy thập phân kiểu Việt)
        $take('/(?<![\d.,])(\d+(?:[.,]\d+)?)(?![\d])/u', fn($m) => 'n:' . self::num((float) str_replace(',', '.', $m[1])));
        // số viết bằng chữ trước đơn vị
        $take('/(?<!\p{L})(' . implode('|', array_keys(self::WORDS)) . ')\s+(?=(?:' . self::UNITS . ')(?!\p{L}))/u', fn($m) => 'n:' . self::WORDS[$m[1]]);

        return $out;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
