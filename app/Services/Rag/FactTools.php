<?php

namespace App\Services\Rag;

use App\Services\Content\ContentCatalog;
use App\Services\EventDateService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * G4: "hàm dữ kiện". Số liệu chính xác (mức mùa đẹp, ngày lễ hội, tour còn chỗ, giá) lấy bằng SQL/luật,
 * rồi đưa vào lời nhắc như DỮ KIỆN. Tìm kiếm ngữ nghĩa có thể trả đoạn "gần giống mà sai số liệu" nên không dùng cho loại này.
 * Mỗi dữ kiện là một "nguồn" có đường dẫn để trích dẫn như đoạn văn.
 */
class FactTools
{
    public function __construct(private EventDateService $dates) {}

    private const LEVEL = [
        'best' => 'là mùa đẹp nhất',
        'ok' => 'đi được, không phải thời điểm đẹp nhất',
        'avoid' => 'nên cân nhắc thời điểm khác',
    ];

    /**
     * @param array<string,mixed> $a kết quả QueryAnalyzer::analyze()
     * @return list<array{key:string,type:string,title:string,text:string,url:string,updatedOn:?string}>
     */
    public function forAnalysis(array $a, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $facts = [];
        foreach ($a['intents'] as $intent) {
            $got = match ($intent) {
                'season' => $this->season($a['destination'], $a['month']),
                'event_dates' => $this->eventDates($a['event'], $a['year'], $today),
                'events_in_month' => $this->eventsInMonth((int) $a['month'], $a['year'], $a['destination']['id'] ?? null, $today),
                'tours' => $this->tours($a['destination'], $today),
                default => [],
            };
            array_push($facts, ...$got);
        }

        return $facts;
    }

    private function fact(string $key, string $title, string $text, string $url): array
    {
        return ['key' => $key, 'type' => 'fact', 'title' => $title, 'text' => $text, 'url' => $url, 'updatedOn' => null];
    }

    /** Mức mùa đẹp (và khí hậu) của điểm đến theo tháng; không có tháng thì tóm tắt cả năm. */
    public function season(array $dest, ?int $month): array
    {
        $months = DB::table('tbl_destination_months')->where('destinationId', $dest['id'])->orderBy('month')->get()->keyBy('month');
        $url = route('destination.show', ['slug' => $dest['slug']], false) . '#thang';
        $title = 'Mùa đẹp: ' . $dest['name'];

        if ($months->isEmpty()) {
            return [$this->fact("season:{$dest['slug']}", $title, "Hệ thống chưa có dữ liệu mùa đẹp theo tháng của {$dest['name']}.", $url)];
        }
        if ($month) {
            $m = $months[$month] ?? null;
            if (!$m) {
                return [$this->fact("season:{$dest['slug']}:$month", $title, "Hệ thống chưa có đánh giá cho tháng $month của {$dest['name']}.", $url)];
            }
            $c = DB::table('tbl_destination_climate')->where('destinationId', $dest['id'])->where('month', $month)->first();
            $text = "Tháng $month " . (self::LEVEL[$m->level] ?? $m->level) . " ở {$dest['name']}."
                . ($m->note ? " Ghi chú: {$m->note}." : '')
                . ($c && $c->tMax !== null ? sprintf(' Khí hậu trung bình tháng %d: %d–%d°C, mưa khoảng %d mm.', $month, round((float) $c->tMin), round((float) $c->tMax), round((float) $c->rainMm)) : '');

            return [$this->fact("season:{$dest['slug']}:$month", $title, $text, $url)];
        }

        $by = fn(string $lv) => $months->where('level', $lv)->pluck('month')->implode(', ');
        $parts = [];
        $best = $by('best');
        $avoid = $by('avoid');
        $ok = $by('ok');
        $best !== '' && $parts[] = "Tháng đẹp nhất: $best.";
        $ok !== '' && $parts[] = "Tháng đi được: $ok.";
        $avoid !== '' && $parts[] = "Tháng nên cân nhắc tránh: $avoid.";
        $notes = $months->where('level', 'best')->filter(fn($m) => $m->note)->map(fn($m) => "tháng {$m->month}: {$m->note}")->implode('; ');
        $notes !== '' && $parts[] = "Ghi chú các tháng đẹp: $notes.";

        return [$this->fact("season:{$dest['slug']}", $title, "Mùa đẹp của {$dest['name']} theo dữ liệu đã duyệt. " . implode(' ', $parts), $url)];
    }

    /** Ngày dương của lễ hội, tính từ quy tắc (âm lịch hoặc dương lịch). Có năm thì chỉ các đợt trong năm đó. */
    public function eventDates(array $ev, ?int $year, DateTimeImmutable $today): array
    {
        $e = DB::table('tbl_events')->where('eventId', $ev['id'])->where('reviewStatus', 'published')->first();
        if (!$e) {
            return [];
        }
        $url = route('events.show', ['slug' => $e->slug], false);
        $title = 'Ngày diễn ra: ' . $e->name;
        $rule = ChunkBuilder::eventRule($e);

        if ($year) {
            $list = array_values(array_filter($this->dates->upcoming($e, new DateTimeImmutable("$year-01-01"), 8), fn($d) => (int) substr($d['start'], 0, 4) === $year));
            $what = "trong năm $year";
        } else {
            $list = $this->dates->upcoming($e, $today, 3);
            $what = 'sắp tới';
        }
        if (!$list) {
            return [$this->fact("event_dates:{$e->slug}" . ($year ? ":$year" : ''), $title, "{$e->name}: $rule Hệ thống chưa tính được ngày dương $what.", $url)];
        }
        $lines = array_map(fn($d) => ContentCatalog::range($d) . (!empty($d['approx']) ? ' (chỉ biết tháng, ngày cụ thể chưa cố định)' : ''), $list);

        return [$this->fact("event_dates:{$e->slug}" . ($year ? ":$year" : ''), $title,
            "{$e->name}: $rule Các đợt $what (ngày dương, tính từ quy tắc): " . implode('; ', $lines) . '.', $url)];
    }

    /** Lễ hội có đợt bắt đầu trong tháng $month (của điểm đến nếu có, cộng lễ hội chung cả nước). */
    public function eventsInMonth(int $month, ?int $year, ?int $destinationId, DateTimeImmutable $today): array
    {
        $y = $year ?? (int) $today->format('Y');
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $month));
        if (!$year && $from < $today->modify('first day of this month')) {
            $from = $from->modify('+1 year');
        }
        $last = $from->modify('last day of this month')->format('Y-m-d');
        $q = DB::table('tbl_events')->where('reviewStatus', 'published');
        if ($destinationId) {
            $q->where(fn($w) => $w->where('destinationId', $destinationId)->orWhereNull('destinationId'));
        }
        $items = [];
        foreach ($q->orderBy('name')->get() as $e) {
            foreach ($this->dates->upcoming($e, $from, 40) as $d) {
                if ($d['start'] >= $from->format('Y-m-d') && $d['start'] <= $last) {
                    $items[] = $e->name . ' (' . ContentCatalog::range($d) . ')';
                    break;
                }
            }
        }
        $label = "tháng $month/" . $from->format('Y');
        $text = $items ? "Lễ hội bắt đầu trong $label: " . implode('; ', $items) . '.' : "Hệ thống chưa có lễ hội đã duyệt bắt đầu trong $label.";

        return [$this->fact("events_in_month:$month:" . $from->format('Y') . ($destinationId ? ":d$destinationId" : ''), "Lễ hội $label", $text, route('events.index', [], false))];
    }

    /** Tour còn chỗ, chưa khởi hành, của điểm đến (hoặc 3 tour gần nhất nếu câu hỏi không nêu điểm đến). */
    public function tours(?array $dest, DateTimeImmutable $today): array
    {
        $q = DB::table('tbl_tours')->where('availability', 1)->where('quantity', '>', 0)->whereDate('startDate', '>', $today->format('Y-m-d'));
        if ($dest) {
            $q->where('destinationId', $dest['id']);
        }
        $rows = $q->orderBy('startDate')->limit(3)->get(['tourId', 'title', 'startDate', 'priceAdult', 'priceChild', 'quantity']);
        if ($rows->isEmpty()) {
            return [$this->fact('tours:none' . ($dest ? ":{$dest['slug']}" : ''), 'Tour còn chỗ',
                'Hiện chưa có tour còn chỗ' . ($dest ? " đến {$dest['name']}" : '') . ' trong dữ liệu.', route('tours', [], false))];
        }
        $out = [];
        foreach ($rows as $t) {
            $text = sprintf('Tour "%s" khởi hành ngày %s, còn %d chỗ, giá người lớn %s đồng, trẻ em %s đồng.',
                $t->title, date('d/m/Y', strtotime($t->startDate)), (int) $t->quantity,
                number_format((float) $t->priceAdult, 0, ',', '.'), number_format((float) $t->priceChild, 0, ',', '.'));
            $out[] = $this->fact('tour:' . $t->tourId, 'Tour: ' . $t->title, $text, route('tour-detail', ['id' => $t->tourId], false));
        }

        return $out;
    }
}
