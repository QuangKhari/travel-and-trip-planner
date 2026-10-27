<?php

namespace App\Services\Content;

use App\Services\EventDateService;
use Illuminate\Support\Facades\DB;

/**
 * Mức đủ nội dung của từng điểm đến (kế hoạch v6, mục 9 và 0.2). DÙNG CHUNG cho `content:coverage`, trang Admin và test:
 * không nơi nào được tự tính lại điểm.
 *
 * Điểm: mỗi tiêu chí có 'max' và 'target' (config/coverage.php); điểm tiêu chí = max × min(1, có / target).
 * % = làm tròn( điểm đạt / tổng max của các tiêu chí ÁP DỤNG × 100 ) về số nguyên. Tiêu chí "tour" được loại khỏi mẫu số khi điểm đến
 * nằm trong config('coverage.tour_waiver') (mẫu số 95).
 * Planner-ready: mọi điều kiện của config('coverage.planner_ready') đều đạt.
 *
 * Chỉ đếm nội dung đã xuất bản (published): bản nháp không làm đẹp điểm.
 */
final class CoverageScorer
{
    /** @var array<string,mixed>|null */
    private ?array $bulk = null;

    /**
     * @param list<string>|null $slugs null = mọi điểm đến đang bật
     * @return list<array<string,mixed>>
     */
    public function scoreAll(?array $slugs = null): array
    {
        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name');
        if ($slugs) {
            $q->whereIn('slug', $slugs);
        }
        $dests = $q->get();
        if ($dests->isEmpty()) {
            return [];
        }
        $this->bulk = $this->gather($dests->pluck('destinationId')->map(fn($v) => (int) $v)->all());
        try {
            return $dests->map(fn($d) => $this->build($d))->all();
        } finally {
            $this->bulk = null;
        }
    }

    /** @return array<string,mixed>|null */
    public function score(int $destinationId): ?array
    {
        $d = DB::table('tbl_destinations')->where('destinationId', $destinationId)->first();
        if (!$d) {
            return null;
        }
        $this->bulk = $this->gather([(int) $d->destinationId]);
        try {
            return $this->build($d);
        } finally {
            $this->bulk = null;
        }
    }

    /** Làm tròn % theo MỘT quy tắc: về số nguyên gần nhất. */
    public static function percent(float $earned, float $applicable): int
    {
        return $applicable > 0 ? (int) round($earned / $applicable * 100) : 0;
    }

    /** Điểm của một tiêu chí. */
    public static function points(int $max, int $target, int $have): float
    {
        return $target > 0 ? round($max * min(1, max(0, $have) / $target), 2) : 0.0;
    }

    // ------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function build(object $d): array
    {
        $id = (int) $d->destinationId;
        $b = $this->bulk;
        $cfg = (array) config('coverage.criteria');
        $pr = (array) config('coverage.planner_ready');
        $eventWaiver = (array) config('coverage.event_waiver');
        $tourWaiver = (array) config('coverage.tour_waiver');

        $intro = trim((string) ($d->description ?? '')) !== '' ? (string) $d->description : (string) ($d->wikiExtract ?? '');
        $hasIntro = mb_strlen(trim($intro)) >= (int) config('coverage.intro_min_chars', 80);
        $hasCover = !empty($d->coverImage);
        $hasCredit = $hasCover && !empty($d->imageCredit);

        $n = [
            'months' => (int) ($b['months'][$id] ?? 0),
            'climate' => (int) ($b['climate'][$id] ?? 0),
            'places' => (int) ($b['places'][$id]['pub'] ?? 0),
            'placesCoords' => (int) ($b['places'][$id]['coords'] ?? 0),
            'placesEat' => (int) ($b['places'][$id]['eat'] ?? 0),
            'foods' => (int) ($b['foods'][$id] ?? 0),
            'events' => (int) ($b['events'][$id]['pub'] ?? 0),
            'eventsDated' => (int) ($b['events'][$id]['dated'] ?? 0),
            'experiences' => (int) ($b['experiences'][$id]['pub'] ?? 0),
            'experiencesReady' => (int) ($b['experiences'][$id]['ready'] ?? 0),
            'itineraries' => (int) ($b['itineraries'][$id] ?? 0),
            'tours' => (int) ($b['tours'][$id] ?? 0),
        ];
        $have = [
            'months' => min(12, $n['months']), 'climate' => min(12, $n['climate']), 'places' => $n['places'], 'foods' => $n['foods'],
            'events' => $n['events'], 'experiences' => $n['experiences'], 'itineraries' => $n['itineraries'], 'tours' => $n['tours'],
            'cover' => ($hasCover ? 1 : 0) + ($hasCredit ? 1 : 0), 'intro' => $hasIntro ? 1 : 0,
        ];

        $criteria = [];
        $earned = 0.0;
        $applicable = 0.0;
        foreach ($cfg as $key => $c) {
            $isApplicable = !($key === 'tours' && isset($tourWaiver[$d->slug]));
            $pts = $isApplicable ? self::points((int) $c['max'], (int) $c['target'], $have[$key] ?? 0) : 0.0;
            $criteria[$key] = [
                'label' => $c['label'], 'max' => (int) $c['max'], 'target' => (int) $c['target'], 'have' => $have[$key] ?? 0,
                'points' => $pts, 'applicable' => $isApplicable, 'waiver' => $isApplicable ? null : $tourWaiver[$d->slug],
            ];
            if ($isApplicable) {
                $earned += $pts;
                $applicable += (int) $c['max'];
            }
        }

        // Planner-ready (mục 0.2). Mỗi điều kiện: nhãn, đã có, cần, đạt?
        $eventWaived = isset($eventWaiver[$d->slug]) && trim((string) $eventWaiver[$d->slug]) !== '';
        $checks = [
            ['months', '12 tháng có mức', $n['months'], (int) $pr['months']],
            ['climate', 'Khí hậu 12 tháng', $n['climate'], (int) $pr['climate']],
            ['places', 'Địa điểm đã duyệt có tọa độ hợp lệ', $n['placesCoords'], (int) $pr['places_with_coords']],
            ['eat', 'Trong đó địa điểm ăn uống', $n['placesEat'], (int) $pr['places_eat_with_coords']],
            ['foods', 'Món đã duyệt', $n['foods'], (int) $pr['foods']],
            ['experiences', 'Trải nghiệm đã duyệt, đủ độ khó + thời lượng', $n['experiencesReady'], (int) $pr['experiences']],
            ['events', 'Lễ hội đã duyệt, tính ra được ngày', $n['eventsDated'], (int) $pr['events']],
            ['itineraries', 'Lịch trình một điểm đến', $n['itineraries'], (int) $pr['itineraries']],
            ['cover', 'Ảnh bìa, ghi công và giới thiệu', ($hasCover && $hasCredit && $hasIntro) ? 1 : 0, 1],
        ];
        $planner = [];
        foreach ($checks as [$key, $label, $got, $need]) {
            $waived = $key === 'events' && $eventWaived;
            $planner[$key] = ['label' => $label, 'have' => $got, 'need' => $need, 'ok' => $waived || $got >= $need, 'waiver' => $waived ? $eventWaiver[$d->slug] : null];
        }
        $missing = array_values(array_map(fn($p) => $p['label'] . ' (' . $p['have'] . '/' . $p['need'] . ')', array_filter($planner, fn($p) => !$p['ok'])));

        return [
            'destinationId' => $id, 'slug' => $d->slug, 'name' => $d->name,
            'earned' => round($earned, 2), 'applicable' => (int) $applicable,
            'percent' => self::percent($earned, $applicable),
            'criteria' => $criteria, 'counts' => $n,
            'plannerReady' => !$missing, 'planner' => $planner, 'plannerMissing' => $missing,
            'hasCover' => $hasCover, 'hasCredit' => $hasCredit, 'hasIntro' => $hasIntro,
        ];
    }

    /**
     * Gom số liệu cho nhiều điểm đến bằng vài truy vấn nhóm (không N+1).
     *
     * @param list<int> $ids
     * @return array<string,mixed>
     */
    private function gather(array $ids): array
    {
        $out = [];
        $grp = fn(string $table) => DB::table($table)->whereIn('destinationId', $ids)->selectRaw('destinationId d, COUNT(*) c')->groupBy('destinationId')->pluck('c', 'd')->all();

        $out['months'] = $grp('tbl_destination_months');
        $out['climate'] = $grp('tbl_destination_climate');
        $out['tours'] = $grp('tbl_tours');
        $out['foods'] = DB::table('tbl_foods')->whereIn('destinationId', $ids)->where('reviewStatus', 'published')
            ->selectRaw('destinationId d, COUNT(*) c')->groupBy('destinationId')->pluck('c', 'd')->all();

        // Tọa độ hợp lệ: có đủ hai số, không phải (0,0), nằm trong miền giá trị của kinh vĩ độ
        $valid = 'latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND NOT (latitude = 0 AND longitude = 0)';
        foreach (DB::table('tbl_external_places')->whereIn('destinationId', $ids)->where('reviewStatus', 'published')
            ->selectRaw("destinationId d, COUNT(*) pub, SUM(CASE WHEN {$valid} THEN 1 ELSE 0 END) coords, SUM(CASE WHEN {$valid} AND category = 'eat' THEN 1 ELSE 0 END) eat")
            ->groupBy('destinationId')->get() as $r) {
            $out['places'][(int) $r->d] = ['pub' => (int) $r->pub, 'coords' => (int) $r->coords, 'eat' => (int) $r->eat];
        }

        foreach (DB::table('tbl_experiences')->whereIn('destinationId', $ids)->where('reviewStatus', 'published')
            ->selectRaw("destinationId d, COUNT(*) pub, SUM(CASE WHEN difficulty IS NOT NULL AND difficulty <> '' AND durationMinutes IS NOT NULL AND durationMinutes > 0 THEN 1 ELSE 0 END) ready")
            ->groupBy('destinationId')->get() as $r) {
            $out['experiences'][(int) $r->d] = ['pub' => (int) $r->pub, 'ready' => (int) $r->ready];
        }

        // Lễ hội: "tính ra được ngày" cần chạy EventDateService nên đọc từng dòng (số lễ hội đã duyệt nhỏ)
        $svc = new EventDateService();
        $today = new \DateTimeImmutable('today');
        foreach (DB::table('tbl_events')->whereIn('destinationId', $ids)->where('reviewStatus', 'published')->get() as $e) {
            $d = (int) $e->destinationId;
            $out['events'][$d]['pub'] = ($out['events'][$d]['pub'] ?? 0) + 1;
            $out['events'][$d]['dated'] = ($out['events'][$d]['dated'] ?? 0) + ($svc->upcoming($e, $today, 1) ? 1 : 0);
        }

        // Lịch trình MỘT điểm đến (ItineraryScope), đã xuất bản
        foreach ((new ItineraryScope())->resolve() as $info) {
            if ($info['single'] !== null && in_array($info['single'], $ids, true) && $info['stops'] > 0) {
                $out['itineraries'][$info['single']] = ($out['itineraries'][$info['single']] ?? 0) + 1;
            }
        }

        return $out;
    }
}
