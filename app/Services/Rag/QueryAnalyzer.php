<?php

namespace App\Services\Rag;

use App\Services\Content\SearchCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * G4: phân luồng câu hỏi bằng LUẬT (xác định, lặp lại được, không tốn chi phí LLM).
 * Nhận ra: điểm đến, lễ hội, tháng, năm trong câu hỏi, và câu hỏi cần DỮ KIỆN (mùa đẹp, ngày lễ hội, tour)
 * hay chỉ cần kiến thức văn bản. Dữ kiện lấy bằng SQL ở FactTools, KHÔNG để mô hình đoán (tài liệu mục 9.1).
 *
 * Mọi so khớp chạy trên bản đã bỏ dấu, chữ thường (SearchCatalog::fold) để "Ninh Binh" và "Ninh Bình" như nhau.
 */
class QueryAnalyzer
{
    /** Từ nghi vấn và từ đệm (đã bỏ dấu), bỏ khi tính độ phủ từ khóa. */
    public const STOP = [
        'gi', 'nao', 'nhu', 'the', 'co', 'khong', 'la', 'o', 'tai', 'cho', 'toi', 'ban', 'minh', 'can', 'nen', 'di', 'den',
        'tu', 'va', 'cua', 'voi', 'hay', 'duoc', 'bao', 'nhieu', 'may', 'nhung', 'cac', 'mot', 'de', 'khi', 'thi', 'ma',
        'se', 'da', 'dang', 'nay', 'do', 'kia', 'vao', 'ra', 'lam', 'sao', 'thi', 'hoi', 'xin', 'cho', 'biet', 'vay',
        'a', 'ak', 'nhe', 'voi', 'rat', 'qua', 'o', 'dau', 'bi', 'phai', 'nua', 'cung', 'hon', 'nhat',
    ];

    public function analyze(string $question, ?string $scopeSlug = null, ?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $folded = SearchCatalog::fold(mb_substr($question, 0, 400));
        $pad = ' ' . $folded . ' ';
        $ent = $this->entities();

        $dest = $this->best($pad, $ent['destinations']);
        if (!$dest && $scopeSlug) {
            foreach ($ent['destinations'] as $d) {
                if ($d['slug'] === $scopeSlug) {
                    $dest = $d;
                    break;
                }
            }
        }
        $event = $this->best($pad, $ent['events']);

        $month = null;
        if (preg_match('/\bthang\s*(\d{1,2})\b/', $folded, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            $month = (int) $m[1];
        } elseif (str_contains($pad, ' thang nay ')) {
            $month = (int) $now->format('n');
        }
        $year = null;
        if (preg_match('/\b(20[2-4]\d)\b/', $folded, $m)) {
            $year = (int) $m[1];
        } elseif (str_contains($pad, ' nam nay ')) {
            $year = (int) $now->format('Y');
        } elseif (str_contains($pad, ' nam sau ') || str_contains($pad, ' nam toi ')) {
            $year = (int) $now->format('Y') + 1;
        }

        $asksSeason = (bool) preg_match('/\b(nen di|mua dep|thoi diem|khi nao di|thang nao|hop khong|dep khong|co nen|mua nao|thoi tiet|thoi gian nao|di luc nao)\b/', $folded);
        $asksDate = (bool) preg_match('/\b(ngay nao|khi nao|bao gio|dien ra|ngay may|thoi gian|to chuc|lich)\b/', $folded);
        $mentionsEvent = (bool) preg_match('/\b(le hoi|su kien|festival|hoi lang)\b/', $folded);
        $asksTour = (bool) preg_match('/\b(tour|dat tour|con cho|khoi hanh)\b/', $folded);

        $intents = [];
        if ($dest && ($month || $asksSeason)) {
            $intents[] = 'season';
        }
        if ($event && ($asksDate || $year || $month)) {
            $intents[] = 'event_dates';
        } elseif (!$event && $mentionsEvent && $month) {
            $intents[] = 'events_in_month';
        }
        if ($asksTour) {
            $intents[] = 'tours';
        }

        $padVol = ' ' . $folded . ' ';
        $volatile = false;
        foreach ((array) config('rag.volatile', []) as $needle) {
            if (str_contains($padVol, $needle)) {
                $volatile = true;
                break;
            }
        }

        return [
            'question' => $question,
            'folded' => $folded,
            'terms' => $this->terms($folded),
            'destination' => $dest,
            'event' => $event,
            'month' => $month,
            'year' => $year,
            'intents' => $intents,
            'volatile' => $volatile,
        ];
    }

    /** Từ khóa nội dung: bỏ từ đệm, từ 1 ký tự và trùng lặp. @return list<string> */
    public function terms(string $folded): array
    {
        $out = [];
        foreach (explode(' ', $folded) as $t) {
            if ($t === '' || (strlen($t) < 2 && !ctype_digit($t)) || in_array($t, self::STOP, true)) {
                continue;
            }
            $out[$t] = true;
        }

        return array_keys($out);
    }

    /** Tên dài nhất khớp nguyên cụm trong câu hỏi. @param list<array<string,mixed>> $items */
    private function best(string $pad, array $items): ?array
    {
        $best = null;
        $bestLen = 0;
        foreach ($items as $it) {
            foreach ($it['names'] as $n) {
                if (strlen($n) >= 3 && strlen($n) > $bestLen && str_contains($pad, ' ' . $n . ' ')) {
                    $best = $it;
                    $bestLen = strlen($n);
                }
            }
        }

        return $best;
    }

    /** @return array{destinations:list<array<string,mixed>>,events:list<array<string,mixed>>} */
    private function entities(): array
    {
        return Cache::remember('rag.entities.v1', 300, function () {
            $d = [];
            foreach (DB::table('tbl_destinations')->where('isActive', 1)->get(['destinationId', 'slug', 'name', 'province']) as $r) {
                $d[] = ['id' => (int) $r->destinationId, 'slug' => $r->slug, 'name' => $r->name,
                    'names' => array_values(array_unique(array_filter([SearchCatalog::fold($r->name), SearchCatalog::fold($r->province)])))];
            }
            $e = [];
            foreach (DB::table('tbl_events')->where('reviewStatus', 'published')->get(['eventId', 'slug', 'name']) as $r) {
                $full = SearchCatalog::fold($r->name);
                $names = [$full];
                $short = preg_replace('/^(le hoi|hoi|le|festival)\s+/', '', $full);
                if ($short !== $full && strlen($short) >= 4) {
                    $names[] = $short;
                }
                $e[] = ['id' => (int) $r->eventId, 'slug' => $r->slug, 'name' => $r->name, 'names' => $names];
            }

            return ['destinations' => $d, 'events' => $e];
        });
    }
}
