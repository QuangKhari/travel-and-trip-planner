<?php

namespace App\Services\Content;

use App\Services\EventDateService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Điểm đến X vào tháng N" (kế hoạch v6, mục 10): SERVICE DUY NHẤT trả lời câu hỏi theo thời điểm.
 * Trang /diem-den/{slug}/thang-{n}, khối xem trước Planner, (tuần 6) câu trả lời nhanh của Search và (tuần 7) FactTools/RAG
 * đều gọi lớp này, không nơi nào tự tính lại mùa, lễ hội hay lịch trình.
 *
 * Nguồn: tbl_destination_months + tbl_destination_climate (qua TravelAdvice), tbl_events, tbl_foods, tbl_experiences,
 * tbl_itineraries (lịch trình MỘT điểm đến, qua ItineraryScope), tbl_tours. Chỉ nội dung đã xuất bản.
 * Kết quả chỉ chứa dữ liệu công khai nên được cache (PublicCache::flush đổi "thế hệ" cache khi admin sửa nội dung).
 */
final class DestinationMonthBrief
{
    public const TTL = 21600;   // 6 giờ
    public const DISCLAIMER = 'Giờ mở cửa, giá vé và lịch sự kiện có thể thay đổi. Vui lòng kiểm tra với đơn vị tổ chức trước khi đi.';

    public function __construct(private ?TravelAdvice $advice = null, private ?EventDateService $dates = null)
    {
        $this->advice ??= new TravelAdvice();
        $this->dates ??= new EventDateService();
    }

    public static function validMonth(mixed $m): ?int
    {
        return is_numeric($m) && (int) $m == $m && (int) $m >= 1 && (int) $m <= 12 ? (int) $m : null;
    }

    /** Năm của lần "tháng N" sắp tới: năm nay nếu chưa qua tháng đó, không thì năm sau. */
    public static function defaultYear(int $month, ?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable('today');

        return (int) $today->format('Y') + ($month < (int) $today->format('n') ? 1 : 0);
    }

    /** Tháng $m có nằm trong mùa [from, to] không (qua năm được: 11 → 2). null khi mùa chưa khai báo đủ hai đầu. */
    public static function inSeason(mixed $from, mixed $to, int $m): ?bool
    {
        $f = self::validMonth($from);
        $t = self::validMonth($to);
        if ($f === null || $t === null) {
            return null;
        }

        return $f <= $t ? ($m >= $f && $m <= $t) : ($m >= $f || $m <= $t);
    }

    public function forSlug(string $slug, int $month, ?int $year = null): ?array
    {
        $id = DB::table('tbl_destinations')->where('slug', $slug)->where('isActive', 1)->value('destinationId');

        return $id ? $this->for((int) $id, $month, $year) : null;
    }

    /** @return array<string,mixed>|null null nếu điểm đến không tồn tại/đã tắt hoặc tháng ngoài 1–12 */
    public function for(int $destinationId, int $month, ?int $year = null): ?array
    {
        if ($month < 1 || $month > 12) {
            return null;
        }
        $year ??= self::defaultYear($month);
        $key = sprintf('monthbrief.v1.g%s.d%d.m%d.y%d', (string) Cache::get('monthbrief.gen', '0'), $destinationId, $month, $year);

        return Cache::remember($key, self::TTL, fn() => $this->build($destinationId, $month, $year));
    }

    /** Một câu đọc được cho Search/RAG/xem trước Planner. Chỉ ghép từ dữ liệu có thật trong $b. */
    public static function summaryText(array $b): string
    {
        $name = $b['destination']['name'];
        $m = $b['month'];
        $parts = [];
        $r = $b['rating'];
        $parts[] = match ($r['level']) {
            'best' => "Tháng {$m} là mùa đẹp nhất của {$name}" . ($r['note'] ? ': ' . rtrim($r['note'], '.') : '') . '.',
            'ok' => "Tháng {$m} đi {$name} được, không phải thời điểm đẹp nhất" . ($r['note'] ? ': ' . rtrim($r['note'], '.') : '') . '.',
            'avoid' => "Tháng {$m} nên cân nhắc thời điểm khác cho {$name}" . ($r['note'] ? ': ' . rtrim($r['note'], '.') : '') . '.',
            default => "Chưa có đánh giá mùa cho {$name} vào tháng {$m}.",
        };
        if ($b['climate']) {
            $c = $b['climate'];
            $parts[] = 'Khí hậu trung bình: ' . round($c['tMin']) . '–' . round($c['tMax']) . '°C, khoảng ' . round($c['rainyDays']) . ' ngày mưa.';
        }
        if ($b['events']) {
            $parts[] = 'Lễ hội: ' . implode('; ', array_map(fn($e) => $e['name'] . ' (' . $e['when'] . ')', array_slice($b['events'], 0, 3))) . '.';
        }
        if ($b['foods']) {
            $parts[] = 'Món theo mùa: ' . implode(', ', array_column(array_slice($b['foods'], 0, 4), 'name')) . '.';
        }

        return implode(' ', $parts);
    }

    // ------------------------------------------------------------------------------------------------------

    private function build(int $destinationId, int $month, int $year): ?array
    {
        $d = DB::table('tbl_destinations')->where('destinationId', $destinationId)->where('isActive', 1)->first();
        if (!$d) {
            return null;
        }
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');

        $adv = $this->advice->forMonth($destinationId, $month);
        $row = DB::table('tbl_destination_months')->where(['destinationId' => $destinationId, 'month' => $month])->first();
        $cl = DB::table('tbl_destination_climate')->where(['destinationId' => $destinationId, 'month' => $month])->first();

        $level = $row->level ?? null;
        $rating = ['level' => $level, 'label' => TravelAdvice::LABELS[$level ?? 'unknown'] ?? TravelAdvice::LABELS['unknown'], 'note' => $row->note ?? null,
            'summary' => $adv['summary'], 'suitable' => $adv['suitable'], 'caution' => $adv['caution'], 'packing' => $adv['packing']];
        $climate = $cl && $cl->tMax !== null && $cl->tMin !== null && $cl->rainyDays !== null
            ? ['tMax' => (float) $cl->tMax, 'tMin' => (float) $cl->tMin, 'rainMm' => $cl->rainMm !== null ? (float) $cl->rainMm : null, 'rainyDays' => (float) $cl->rainyDays,
                'years' => (int) $cl->years, 'periodFrom' => (int) $cl->periodFrom, 'periodTo' => (int) $cl->periodTo]
            : null;

        // Lễ hội có đợt diễn ra giao với tháng này (năm $year)
        $events = [];
        foreach (DB::table('tbl_events')->where('destinationId', $destinationId)->where('reviewStatus', 'published')->orderBy('name')->get() as $e) {
            foreach ($this->dates->upcoming($e, $first, 6) as $o) {
                if ($o['start'] <= $last->format('Y-m-d') && $o['end'] >= $first->format('Y-m-d')) {
                    $events[] = ['id' => (int) $e->eventId, 'slug' => $e->slug, 'name' => $e->name, 'url' => route('events.show', ['slug' => $e->slug]),
                        'when' => self::range($o), 'start' => $o['start'], 'end' => $o['end'], 'approx' => (bool) $o['approx'], 'mustTry' => !empty($e->isMustTry)];
                    break;
                }
            }
        }
        usort($events, fn($a, $b) => [$b['mustTry'], $a['start']] <=> [$a['mustTry'], $b['start']]);

        // Món có mùa trùng tháng này (chỉ món đã khai báo đủ monthFrom + monthTo)
        $foods = [];
        foreach (DB::table('tbl_foods')->where('destinationId', $destinationId)->where('reviewStatus', 'published')->whereNotNull('monthFrom')->whereNotNull('monthTo')
            ->orderByDesc('isMustTry')->orderBy('name')->get() as $f) {
            if (self::inSeason($f->monthFrom, $f->monthTo, $month)) {
                $foods[] = ['id' => (int) $f->foodId, 'slug' => $f->slug, 'name' => $f->name, 'category' => (string) $f->category, 'url' => route('foods.show', ['slug' => $f->slug]),
                    'season' => self::seasonText($f->monthFrom, $f->monthTo), 'mustTry' => !empty($f->isMustTry)];
            }
        }

        // Trải nghiệm: có mùa trùng tháng này trước, rồi trải nghiệm quanh năm (chưa khai báo mùa). Mùa khai báo mà không trùng thì loại.
        $exps = [];
        foreach (DB::table('tbl_experiences')->where('destinationId', $destinationId)->where('reviewStatus', 'published')->orderByDesc('isMustTry')->orderBy('name')->get() as $x) {
            $in = self::inSeason($x->monthFrom ?? null, $x->monthTo ?? null, $month);
            if ($in === false) {
                continue;
            }
            $exps[] = ['id' => (int) $x->experienceId, 'slug' => $x->slug, 'name' => $x->name, 'category' => (string) ($x->category ?? ''), 'duration' => (string) ($x->duration ?? ''),
                'url' => route('experiences.show', ['slug' => $x->slug]), 'seasonal' => $in === true, 'season' => $in === true ? self::seasonText($x->monthFrom, $x->monthTo) : null,
                'mustTry' => !empty($x->isMustTry)];
        }
        usort($exps, fn($a, $b) => [$b['seasonal'], $b['mustTry']] <=> [$a['seasonal'], $a['mustTry']]);

        // Lịch trình mẫu MỘT điểm đến
        $itins = [];
        $scope = (new ItineraryScope())->resolve();
        $ids = array_keys(array_filter($scope, fn($s) => $s['single'] === $destinationId));
        if ($ids) {
            foreach (DB::table('tbl_itineraries')->whereIn('itineraryId', $ids)->where('reviewStatus', 'published')->orderBy('days')->limit(3)->get() as $i) {
                $itins[] = ['id' => (int) $i->itineraryId, 'slug' => $i->slug, 'title' => $i->title, 'days' => (int) $i->days, 'url' => route('itineraries.show', ['slug' => $i->slug])];
            }
        }

        // Tour khởi hành trong tháng này, còn chỗ
        $tours = [];
        foreach (DB::table('tbl_tours')->where('destinationId', $destinationId)->where('availability', 1)->where('quantity', '>', 0)
            ->whereBetween('startDate', [$first->format('Y-m-d'), $last->format('Y-m-d')])->whereDate('startDate', '>=', date('Y-m-d'))->orderBy('startDate')->limit(3)->get() as $t) {
            $tours[] = ['id' => (int) $t->tourId, 'title' => $t->title, 'start' => date('d/m/Y', strtotime($t->startDate)), 'price' => (float) $t->priceAdult, 'url' => route('tour-detail', ['id' => $t->tourId])];
        }

        $warnings = [];
        if (!$row) {
            $warnings[] = "Chưa có đánh giá mùa cho tháng {$month}.";
        } elseif ($level === 'avoid') {
            $warnings[] = "Tháng {$month} thuộc mức \"nên cân nhắc\"" . ($row->note ? ': ' . rtrim($row->note, '.') : '') . '.';
        }
        if (!$climate) {
            $warnings[] = "Chưa có dữ liệu khí hậu cho tháng {$month}.";
        }
        if (!$events && !$foods && !$exps) {
            $warnings[] = "Chưa có lễ hội, món hay trải nghiệm nào được ghi nhận cho tháng {$month}.";
        }

        return [
            'destination' => ['id' => (int) $d->destinationId, 'slug' => $d->slug, 'name' => $d->name, 'province' => $d->province, 'domain' => $d->domain,
                'url' => route('destination.show', ['slug' => $d->slug])],
            'month' => $month, 'year' => $year, 'monthLabel' => 'Tháng ' . $month,
            'rating' => $rating, 'climate' => $climate,
            'events' => $events, 'foods' => $foods, 'experiences' => $exps, 'itineraries' => $itins, 'tours' => $tours,
            'warnings' => $warnings, 'hasRating' => (bool) $row, 'disclaimer' => self::DISCLAIMER, 'climateNote' => TravelAdvice::DISCLAIMER,
        ];
    }

    private static function seasonText(mixed $from, mixed $to): string
    {
        return (int) $from === (int) $to ? 'Tháng ' . (int) $from : 'Tháng ' . (int) $from . '–' . (int) $to;
    }

    private static function range(array $o): string
    {
        $s = new DateTimeImmutable($o['start']);
        $e = new DateTimeImmutable($o['end']);
        if (!empty($o['approx'])) {
            return 'khoảng tháng ' . $s->format('n/Y');
        }
        if ($o['start'] === $o['end']) {
            return $s->format('d/m/Y');
        }

        return $s->format('d/m') . '–' . $e->format('d/m/Y');
    }
}
