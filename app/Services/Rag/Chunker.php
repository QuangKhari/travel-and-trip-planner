<?php

namespace App\Services\Rag;

/**
 * G2: cắt một bài Markdown (hoặc văn bản thường) thành các đoạn 200-400 từ, cắt theo tiêu đề "## ".
 *  - mục dài hơn max_words: tách theo đoạn văn, rồi theo câu;
 *  - mục ngắn: gộp với mục kế tiếp cho đến khi đủ min_words (không cắt rời một mục chỉ vài dòng);
 *  - tiêu đề đoạn = "Tên bài — tiêu đề mục đầu tiên trong đoạn".
 * Hàm thuần (không đụng DB) nên kiểm thử dễ.
 */
final class Chunker
{
    public function __construct(private int $minWords = 120, private int $maxWords = 380) {}

    public static function words(string $s): int
    {
        return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Bỏ ký hiệu Markdown nhưng giữ nội dung: "> " hộp lưu ý, ** **, liên kết, danh sách. */
    public static function plain(string $line): string
    {
        $t = preg_replace('/^\s{0,3}(>|[-*+]|\d+\.)\s+/u', '', $line) ?? $line;
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $t) ?? $t;
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t) ?? $t;
        $t = preg_replace('/[*_`]+/u', '', $t) ?? $t;

        return trim($t);
    }

    /**
     * @return list<array{title:string,text:string}>
     */
    public function split(string $title, string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $sections = [];
        $cur = ['heading' => null, 'lines' => []];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,3}\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                if ($cur['heading'] !== null || $cur['lines']) {
                    $sections[] = $cur;
                }
                $cur = ['heading' => self::plain($m[1]), 'lines' => []];
            } else {
                $cur['lines'][] = $line;
            }
        }
        if ($cur['heading'] !== null || $cur['lines']) {
            $sections[] = $cur;
        }

        // Mỗi mục -> các "khối" (đoạn văn), mỗi khối không quá max_words
        $blocks = [];
        foreach ($sections as $s) {
            $first = true;
            foreach ($this->paragraphs($s['lines']) as $p) {
                foreach ($this->capWords($p) as $piece) {
                    $blocks[] = ['heading' => $s['heading'], 'text' => $piece, 'start' => $first];
                    $first = false;
                }
            }
            if ($first && $s['heading'] !== null) {   // mục chỉ có tiêu đề
                $blocks[] = ['heading' => $s['heading'], 'text' => '', 'start' => true];
            }
        }

        $chunks = [];
        $cur = [];
        $words = 0;
        $flush = function () use (&$cur, &$words, &$chunks, $title) {
            if ($cur) {
                $chunks[] = $this->make($title, $cur);
            }
            $cur = [];
            $words = 0;
        };
        foreach ($blocks as $b) {
            $w = self::words($b['text']) + ($b['start'] && $b['heading'] ? self::words($b['heading']) : 0);
            if ($cur && ($words + $w > $this->maxWords || ($b['start'] && $words >= $this->minWords))) {
                $flush();
            }
            $cur[] = $b;
            $words += $w;
        }
        $flush();

        return array_values(array_filter($chunks, fn($c) => trim($c['text']) !== ''));
    }

    /** @param list<string> $lines @return list<string> */
    private function paragraphs(array $lines): array
    {
        $out = [];
        $buf = [];
        foreach ($lines as $l) {
            if (trim($l) === '') {
                if ($buf) {
                    $out[] = implode(' ', $buf);
                    $buf = [];
                }
                continue;
            }
            $plain = self::plain($l);
            if ($plain === '') {
                continue;
            }
            // Mỗi mục danh sách là một dòng riêng để không dính vào nhau
            if (preg_match('/^\s{0,3}([-*+]|\d+\.)\s+/u', $l)) {
                if ($buf) {
                    $out[] = implode(' ', $buf);
                    $buf = [];
                }
                $out[] = '- ' . $plain;
                continue;
            }
            $buf[] = $plain;
        }
        if ($buf) {
            $out[] = implode(' ', $buf);
        }

        return $out;
    }

    /** Một đoạn quá dài thì tách theo câu thành các phần không vượt max_words. @return list<string> */
    private function capWords(string $p): array
    {
        if (self::words($p) <= $this->maxWords) {
            return [$p];
        }
        $out = [];
        $buf = '';
        foreach (preg_split('/(?<=[.!?…])\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY) ?: [$p] as $sentence) {
            if ($buf !== '' && self::words($buf . ' ' . $sentence) > $this->maxWords) {
                $out[] = $buf;
                $buf = '';
            }
            // câu đơn lẻ vẫn quá dài (không có dấu chấm): cắt cứng theo số từ
            while (self::words($sentence) > $this->maxWords) {
                $parts = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
                $out[] = implode(' ', array_slice($parts, 0, $this->maxWords));
                $sentence = implode(' ', array_slice($parts, $this->maxWords));
            }
            $buf = $buf === '' ? $sentence : $buf . ' ' . $sentence;
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /** @param list<array{heading:?string,text:string,start:bool}> $blocks */
    private function make(string $title, array $blocks): array
    {
        $lines = [];
        $firstHeading = null;
        foreach ($blocks as $b) {
            if ($b['start'] && $b['heading']) {
                $lines[] = $b['heading'] . ':';
                $firstHeading ??= $b['heading'];
            }
            if ($b['text'] !== '') {
                $lines[] = $b['text'];
            }
        }

        return [
            'title' => $firstHeading && mb_strtolower($firstHeading) !== mb_strtolower($title) ? $title . ' — ' . $firstHeading : $title,
            'text' => trim(implode("\n", $lines)),
        ];
    }
}
