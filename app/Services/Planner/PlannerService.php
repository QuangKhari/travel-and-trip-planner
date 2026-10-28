<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\PublishGate;
use App\Services\EventDateService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tuần 5: nạp ứng viên đã xuất bản của MỘT điểm đến, gọi PlannerEngine, rồi (tùy chọn) lưu thành chuyến đi (tbl_trips kind = trip).
 * Cổng Planner-ready chặn điểm đến thiếu dữ liệu (CLI có --force để thử trên dữ liệu chưa đủ; web (tuần 6) không có cách bỏ qua).
 */
final class PlannerService
{
    public function __construct(
        private PlannerEngine $engine,
        private PlannerGate $gate,
        private DestinationMonthBrief $brief,
        private EventDateService $dates,
    ) {}

    /**
     * @param array<string,mixed> $req destinationId|slug, month, days, interests, dislikes, budget, groupSize, pace, maxDifficulty, startDate
     * @return array<string,mixed> ['ok'=>false,'code','message','missing'?] hoặc ['ok'=>true,'destination','request','plan','tours','verdict','disclaimer']
     */
    public function generate(array $req, bool $ignoreGate = false): array
    {
        $dest = DB::table('tbl_destinations')->where('isActive', 1)
            ->when(!empty($req['destinationId']), fn($q) => $q->where('destinationId', (int) $req['destinationId']), fn($q) => $q->where('slug', (string) ($req['slug'] ?? '')))
            ->first();
        if (!$dest) {
            return ['ok' => false, 'code' => 'no_destination', 'message' => 'Không tìm thấy điểm đến.'];
        }
        $month = DestinationMonthBrief::validMonth($req['month'] ?? null);
        if ($month === null) {
            return ['ok' => false, 'code' => 'bad_month', 'message' => 'Tháng đi phải từ 1 đến 12.'];
        }
        $req['month'] = $month;

        $status = $this->gate->status((int) $dest->destinationId);
        if (!$status['ready'] && !$ignoreGate) {
            return ['ok' => false, 'code' => 'not_ready', 'missing' => $status['missing'],
                'message' => $dest->name . ' chưa đủ dữ liệu để Planner đề xuất lịch trình (còn thiếu: ' . implode('; ', $status['missing']) . ').'];
        }

        $nreq = $this->engine->normalizeRequest($req);
        $year = $nreq['startDate'] ? (int) date('Y', strtotime($nreq['startDate'])) : DestinationMonthBrief::defaultYear($month);
        $brief = $this->brief->for((int) $dest->destinationId, $month, $year);

        $ctx = ['center' => $dest->latitude !== null && $dest->longitude !== null ? [(float) $dest->latitude, (float) $dest->longitude] : null,
            'ratingLevel' => $brief['rating']['level'] ?? null];
        if (!empty($brief['climate'])) {
            $dim = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $ctx['rainyShare'] = $brief['climate']['rainyDays'] / $dim;
            $ctx['tMax'] = $brief['climate']['tMax'];
        }

        $log = [];
        $cands = $this->candidates($dest, $nreq, $year, $log);
        $plan = $this->engine->plan($cands, $nreq, $ctx);
        $plan['filterLog'] = array_merge($log, $plan['filterLog']);
        $plan['stats']['candidates'] += count($log);
        $plan['stats']['filtered'] = count($plan['filterLog']);

        return [
            'ok' => true,
            'destination' => ['id' => (int) $dest->destinationId, 'slug' => $dest->slug, 'name' => $dest->name],
            'request' => $nreq + ['year' => $year],
            'plan' => $plan,
            'tours' => $this->tours((int) $dest->destinationId, $nreq, $year),
            'verdict' => $brief ? DestinationMonthBrief::summaryText($brief) : null,
            'gate' => $status,
            'disclaimer' => DestinationMonthBrief::DISCLAIMER,
        ];
    }

    /** Lưu kết quả generate() thành chuyến đi của người dùng. Trả tripId. */
    public function save(int $userId, array $result): int
    {
        if (empty($result['ok'])) {
            throw new \InvalidArgumentException('Không thể lưu một kết quả lỗi.');
        }
        $r = $result['request'];
        $plan = $result['plan'];
        $notes = array_values(array_filter(array_merge($plan['warnings'], [$plan['budget']['message'] ?? null])));

        return DB::transaction(function () use ($userId, $result, $r, $plan, $notes) {
            $tripId = (int) DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'destinationId' => $result['destination']['id'],
                'title' => mb_substr($result['destination']['name'] . ' ' . $r['days'] . ' ngày, tháng ' . $r['month'], 0, 160),
                'startDate' => $r['startDate'], 'days' => $r['days'], 'budget' => $r['budget'], 'groupSize' => $r['groupSize'],
                'status' => 'planned', 'month' => $r['month'], 'source' => 'planner',
                'interests' => json_encode($r['interests'], JSON_UNESCAPED_UNICODE), 'dislikes' => json_encode($r['dislikes'], JSON_UNESCAPED_UNICODE),
                'notes' => $notes ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null,
                'createdAt' => now(), 'updatedAt' => now(),
            ]);
            $rows = [];
            foreach ($plan['days'] as $day) {
                foreach ($day['items'] as $order => $it) {
                    $known = ($it['priceMin'] ?? null) !== null ? (float) $it['priceMin'] * $r['groupSize'] : null;
                    $rows[] = ['tripId' => $tripId, 'refType' => $it['type'], 'refId' => (int) $it['refId'], 'dayNo' => $day['day'], 'orderNo' => $order,
                        'slot' => $it['slot'], 'cost' => $known, 'durationMin' => $it['durationMin'],
                        'reasons' => $it['reasons'] ? json_encode($it['reasons'], JSON_UNESCAPED_UNICODE) : null];
                }
            }
            if ($rows) {
                DB::table('tbl_trip_items')->insert($rows);
            }

            return $tripId;
        });
    }

    // ------------------------------------------------------------------ nạp ứng viên

    /** @return list<array<string,mixed>> */
    private function candidates(object $dest, array $req, int $year, array &$log): array
    {
        $id = (int) $dest->destinationId;
        $kindGroup = [];
        foreach ((array) config('planner.kind_groups') as $g => $kinds) {
            foreach ($kinds as $k) {
                $kindGroup[$k] = $g;
            }
        }
        $out = [];

        foreach (DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', 'published')->get() as $p) {
            $out[] = ['key' => 'place:' . $p->placeId, 'type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'kind' => $p->kind,
                'group' => $kindGroup[$p->kind] ?? 'see', 'lat' => $p->latitude !== null ? (float) $p->latitude : null, 'lng' => $p->longitude !== null ? (float) $p->longitude : null,
                'rank' => (int) $p->rank, 'mustTry' => !empty($p->isMustTry)];
        }

        foreach (DB::table('tbl_experiences')->where('destinationId', $id)->where('reviewStatus', 'published')->get() as $x) {
            if ($why = PublishGate::plannerProblems('experiences', $x)) {
                $log[] = ['key' => 'experience:' . $x->experienceId, 'name' => $x->name, 'type' => 'experience', 'code' => 'planner_incomplete',
                    'reason' => 'Chưa đủ dữ liệu cho Planner: ' . implode('; ', $why)];
                continue;
            }
            $out[] = ['key' => 'experience:' . $x->experienceId, 'type' => 'experience', 'refId' => (int) $x->experienceId, 'name' => $x->name,
                'category' => (string) ($x->category ?? ''), 'durationMin' => (int) $x->durationMinutes, 'difficulty' => $x->difficulty,
                'indoor' => $x->indoor === null ? null : (bool) $x->indoor, 'priceMin' => $x->priceMin !== null ? (float) $x->priceMin : null,
                'priceMax' => $x->priceMax !== null ? (float) $x->priceMax : null, 'monthFrom' => $x->monthFrom, 'monthTo' => $x->monthTo,
                'lat' => ($x->latitude ?? null) !== null ? (float) $x->latitude : null, 'lng' => ($x->longitude ?? null) !== null ? (float) $x->longitude : null,
                'mustTry' => !empty($x->isMustTry)];
        }

        $foods = DB::table('tbl_foods')->where('reviewStatus', 'published')->where(function ($q) use ($id) {
            $q->where('destinationId', $id)->orWhereIn('foodId', DB::table('tbl_food_destination')->where('destinationId', $id)->select('foodId'));
        })->get();
        foreach ($foods as $f) {
            $out[] = ['key' => 'food:' . $f->foodId, 'type' => 'food', 'refId' => (int) $f->foodId, 'name' => $f->name, 'category' => (string) $f->category,
                'monthFrom' => $f->monthFrom, 'monthTo' => $f->monthTo, 'lat' => null, 'lng' => null, 'mustTry' => !empty($f->isMustTry)];
        }

        // Lễ hội: có đợt diễn ra giao với khoảng chuyến đi (biết ngày đi) hoặc với tháng đi
        $from = $req['startDate'] ? new DateTimeImmutable($req['startDate']) : new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $req['month']));
        $to = $req['startDate'] ? $from->modify('+' . ($req['days'] - 1) . ' days') : $from->modify('last day of this month');
        foreach (DB::table('tbl_events')->where('destinationId', $id)->where('reviewStatus', 'published')->get() as $e) {
            foreach ($this->dates->upcoming($e, $from, 6) as $o) {
                if ($o['start'] <= $to->format('Y-m-d') && $o['end'] >= $from->format('Y-m-d')) {
                    $out[] = ['key' => 'event:' . $e->eventId, 'type' => 'event', 'refId' => (int) $e->eventId, 'name' => $e->name, 'lat' => null, 'lng' => null,
                        'eventStart' => $o['start'], 'eventEnd' => $o['end'], 'durationMin' => null, 'mustTry' => !empty($e->isMustTry)];
                    break;
                }
            }
        }

        return $out;
    }

    /** Khối tour riêng (không cộng vào chi phí chuyến). */
    private function tours(int $destId, array $req, int $year): array
    {
        $q = DB::table('tbl_tours')->where('destinationId', $destId)->where('availability', 1)->where('quantity', '>', 0)->whereDate('startDate', '>=', date('Y-m-d'));
        if ($req['startDate']) {
            $w = (int) config('planner.tour_window_days', 14);
            $q->whereBetween('startDate', [date('Y-m-d', strtotime($req['startDate'] . " -$w days")), date('Y-m-d', strtotime($req['startDate'] . " +$w days"))]);
        } else {
            $first = sprintf('%04d-%02d-01', $year, $req['month']);
            $q->whereBetween('startDate', [$first, date('Y-m-t', strtotime($first))]);
        }
        $out = [];
        foreach ($q->orderBy('startDate')->limit((int) config('planner.tours_max', 3))->get() as $t) {
            $out[] = ['id' => (int) $t->tourId, 'title' => $t->title, 'start' => date('d/m/Y', strtotime($t->startDate)), 'priceAdult' => (float) $t->priceAdult,
                'forGroup' => (float) $t->priceAdult * $req['groupSize'], 'url' => route('tour-detail', ['id' => $t->tourId])];
        }

        return $out;
    }
}
