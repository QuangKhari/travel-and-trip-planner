<?php

namespace App\Services\Content;

use App\Services\Rag\QueryAnalyzer;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tuần 6, mục 11 (Search Intent P0): đọc câu tìm kiếm tự nhiên bằng LUẬT (không LLM) và tách ra
 * điểm đến, tháng, số ngày, sở thích, điều không thích, loại nội dung (ăn gì / làm gì / lễ hội / tour).
 *
 * - Sở thích đọc từ config/interests.php (`aliases` + `keywords`), không tự định nghĩa lại (mục 0.1-B).
 * - "Không thích" dùng đúng khóa của config/planner.php 'dislikes' để Search và Planner chung một bảng.
 * - Ngân sách và số người là P1: có nhận ra nhưng chỉ để điền sẵn form Planner, không lọc kết quả tìm kiếm.
 * - Mọi so khớp chạy trên chữ đã bỏ dấu (SearchCatalog::fold).
 */
final class SearchIntent
{
    /** Từ đồng nghĩa cơ bản (đã bỏ dấu): viết liền/biệt danh -> dạng chuẩn. */
    private const SYNONYMS = [
        'sapa' => 'sa pa', 'hanoi' => 'ha noi', 'danang' => 'da nang', 'dalat' => 'da lat',
        'sai gon' => 'ho chi minh', 'saigon' => 'ho chi minh', 'tphcm' => 'ho chi minh', 'hcm' => 'ho chi minh',
    ];

    private const NUM_WORDS = ['mot' => 1, 'hai' => 2, 'ba' => 3, 'bon' => 4, 'bay' => 7];

    /** Từ khóa sở thích dễ nhầm với từ thường ("chùa" bỏ dấu = "chưa"). Không dùng để nhận sở thích. */
    private const KEYWORD_SKIP = ['chua', 'rung', 'thac'];

    private const DISLIKE_PATTERNS = [
        'trekking' => '/\bkhong\s+(?:thich\s+|muon\s+)?(?:trekking|leo nui)\b/',
        'crowds' => '/\b(?:it dong|tranh dong|khong thich dong|khong dong|vang ve)\b/',
        'outdoor' => '/\bkhong\s+(?:thich\s+)?ngoai troi\b/',
        'long_travel' => '/\b(?:khong\s+(?:thich\s+)?di xa|it di chuyen)\b/',
    ];

    private const TYPE_PATTERNS = [
        'food' => '/\b(?:an gi|mon ngon|am thuc|dac san|quan an|an uong)\b/',
        'event' => '/\b(?:le hoi|su kien|festival)\b/',
        'experience' => '/\b(?:trai nghiem|choi gi|lam gi|hoat dong)\b/',
        'tour' => '/\b(?:dat tour|tour)\b/',
    ];

    /**
     * @param list<array{id:int,slug:string,name:string,names:list<string>,provinces:list<string>}>|null $destinations null = đọc DB (cache 5 phút)
     * @return array<string,mixed>
     */
    public function parse(string $question, ?array $destinations = null, ?DateTimeInterface $now = null): array
    {
        $now ??= now();
        $pad = ' ' . SearchCatalog::fold(mb_substr($question, 0, 200)) . ' ';
        foreach (self::SYNONYMS as $from => $to) {
            $pad = str_replace(' ' . $from . ' ', ' ' . $to . ' ', $pad);
        }
        $work = trim($pad);   // mỗi phần nhận ra được cắt khỏi đây; phần còn lại là từ khóa tự do

        $dest = $this->bestDestination(' ' . $work . ' ', $destinations ?? $this->destinations());
        if ($dest) {
            $work = $this->cut($work, $dest['matched']);
        }

        $month = null;
        if (preg_match('/\bthang\s*(\d{1,2})\b/', $work, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            $month = (int) $m[1];
            $work = $this->cut($work, $m[0]);
        } elseif (str_contains(' ' . $work . ' ', ' thang nay ')) {
            $month = (int) $now->format('n');
            $work = $this->cut($work, 'thang nay');
        }

        $days = null;
        $maxDays = (int) config('planner.max_days', 7);
        if (preg_match('/\b(\d{1,2})\s*ngay(?:\s*\d{1,2}\s*dem)?\b/', $work, $m)) {
            $days = (int) $m[1] >= 1 && (int) $m[1] <= $maxDays ? (int) $m[1] : null;
            $work = $this->cut($work, $m[0]);
        } elseif (preg_match('/\b(mot|hai|ba|bon|bay)\s*ngay\b/', $work, $m)) {
            $days = self::NUM_WORDS[$m[1]];
            $work = $this->cut($work, $m[0]);
        } elseif (str_contains(' ' . $work . ' ', ' cuoi tuan ')) {
            $days = 2;
            $work = $this->cut($work, 'cuoi tuan');
        }

        // P1: chỉ để điền sẵn form Planner
        $budget = null;
        if (preg_match('/\b(\d+)\s*trieu\b/', $work, $m)) {
            $budget = (float) $m[1] * 1_000_000;
            $work = $this->cut($work, $m[0]);
        }
        $group = null;
        if (preg_match('/\b(\d{1,2})\s*(?:nguoi|khach)\b/', $work, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 50) {
            $group = (int) $m[1];
            $work = $this->cut($work, $m[0]);
        }

        // "Không thích": cắt trước khi nhận sở thích để "không trekking" không thành sở thích phiêu lưu
        $known = array_keys((array) config('planner.dislikes', []));
        $dislikes = [];
        foreach (self::DISLIKE_PATTERNS as $key => $re) {
            if (in_array($key, $known, true) && preg_match($re, $work, $m)) {
                $dislikes[] = $key;
                $work = $this->cut($work, $m[0]);
            }
        }

        // Loại nội dung: ghi nhận ở đây, cắt khỏi câu SAU khi nhận sở thích ("ẩm thực" vẫn tính là sở thích)
        $types = [];
        foreach (self::TYPE_PATTERNS as $type => $re) {
            if (preg_match($re, ' ' . $work . ' ')) {
                $types[] = $type;
            }
        }

        $interests = [];
        foreach ((array) config('interests', []) as $key => $def) {
            foreach ($this->interestTerms((array) $def) as $term) {
                if (str_contains(' ' . $work . ' ', ' ' . $term . ' ')) {
                    $interests[] = $key;
                    $work = $this->cut($work, $term);
                    break;
                }
            }
        }

        foreach (self::TYPE_PATTERNS as $re) {
            if (preg_match($re, ' ' . $work . ' ', $m)) {
                $work = $this->cut($work, trim($m[0]));
            }
        }
        $leftover = (new QueryAnalyzer())->terms($work);

        $destOut = $dest ? ['id' => $dest['id'], 'slug' => $dest['slug'], 'name' => $dest['name']] : null;
        $primary = null;
        if ($destOut && $days) {
            $primary = 'plan';
        } elseif ($destOut && $month) {
            $primary = 'season';
        } elseif ($types) {
            $primary = $types[0];
        } elseif ($destOut) {
            $primary = 'destination';
        }

        return [
            'question' => $question,
            'destination' => $destOut,
            'month' => $month,
            'days' => $days,
            'interests' => $interests,
            'dislikes' => $dislikes,
            'types' => $types,
            'budget' => $budget,
            'groupSize' => $group,
            'terms' => $leftover,
            'searchQuery' => trim(($destOut['name'] ?? '') . ' ' . implode(' ', $leftover)),
            'primary' => $primary,
            'plannerQuery' => $destOut ? array_filter([
                'diem-den' => $destOut['slug'], 'thang' => $month, 'ngay' => $days, 'nguoi' => $group,
                'ngan-sach' => $budget !== null ? (int) $budget : null,
                'so-thich' => $interests ?: null, 'khong-thich' => $dislikes ?: null,
            ], fn($v) => $v !== null) : [],
        ];
    }

    /** Đủ ý để gợi ý "Lập chuyến đi này": cần điểm đến và ít nhất một trong số ngày / tháng / sở thích. */
    public static function plannable(array $intent): bool
    {
        return $intent['destination'] !== null && ($intent['days'] || $intent['month'] || $intent['interests']);
    }

    /** @return list<string> */
    private function interestTerms(array $def): array
    {
        $terms = array_map('trim', array_map('strval', (array) ($def['aliases'] ?? [])));
        foreach ((array) ($def['keywords'] ?? []) as $k) {
            $k = trim((string) $k);
            if (strlen($k) >= 4 && !in_array($k, self::KEYWORD_SKIP, true)) {   // từ ngắn (ho, lan, den...) quá mơ hồ
                $terms[] = $k;
            }
        }
        $terms = array_values(array_unique(array_filter($terms)));
        usort($terms, fn($a, $b) => strlen($b) <=> strlen($a));   // cụm dài trước

        return $terms;
    }

    /** Tên điểm đến dài nhất khớp nguyên cụm; ưu tiên tên điểm đến, rồi mới tới tên tỉnh. @return ?array<string,mixed> */
    private function bestDestination(string $pad, array $dests): ?array
    {
        foreach (['names', 'provinces'] as $field) {
            $best = null;
            $bestLen = 0;
            $matched = '';
            foreach ($dests as $d) {
                foreach ($d[$field] ?? [] as $n) {
                    if (strlen($n) >= 3 && strlen($n) > $bestLen && str_contains($pad, ' ' . $n . ' ')) {
                        $best = $d;
                        $bestLen = strlen($n);
                        $matched = $n;
                    }
                }
            }
            if ($best) {
                return ['id' => (int) $best['id'], 'slug' => $best['slug'], 'name' => $best['name'], 'matched' => $matched];
            }
        }

        return null;
    }

    /** Cắt đúng một cụm (nguyên từ) khỏi chuỗi làm việc. */
    private function cut(string $work, string $phrase): string
    {
        $out = preg_replace('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/', ' ', $work, 1);

        return trim(preg_replace('/\s+/', ' ', $out ?? $work));
    }

    /** @return list<array<string,mixed>> */
    private function destinations(): array
    {
        return Cache::remember('search.intent.dests.v1', 300, function () {
            $out = [];
            foreach (DB::table('tbl_destinations')->where('isActive', 1)->get(['destinationId', 'slug', 'name', 'province']) as $r) {
                $out[] = [
                    'id' => (int) $r->destinationId, 'slug' => $r->slug, 'name' => $r->name,
                    'names' => array_values(array_filter([SearchCatalog::fold($r->name)])),
                    'provinces' => array_values(array_filter([SearchCatalog::fold((string) $r->province)])),
                ];
            }

            return $out;
        });
    }
}
