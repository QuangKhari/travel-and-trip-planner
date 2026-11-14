<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\PublishGate;
use App\Services\EventDateService;
use App\Services\Planner\Health\DaySchedule;
use App\Services\Planner\Route\RouteService;
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
     * @param array<string,mixed> $req destinationId|slug | placeName+placeLat+placeLng, month, days, interests, dislikes, budget,
     *                                 groupSize|adults+children, pace, maxDifficulty, startDate, mode (auto|curated|open)
     * @return array<string,mixed> ['ok'=>false,'code','message','missing'?] hoặc
     *         ['ok'=>true,'mode'(curated|open),'destination','place','request','plan','tours','verdict','gate','openSources','disclaimer']
     *
     * Chế độ:
     *  - curated: chỉ nội dung Travela đã duyệt (điểm đến Planner-ready).
     *  - open (đề xuất mở rộng): điểm đến chưa đủ dữ liệu hoặc nơi bất kỳ trên bản đồ. Dùng nội dung đã duyệt nếu có,
     *    bổ sung điểm tham quan / quán ăn từ OpenStreetMap; mọi mục bản đồ được gắn nhãn "chưa được Travela biên tập".
     *  - auto (mặc định): curated nếu đủ dữ liệu, không thì open (khi planner.open_mode.enabled).
     */
    public function generate(array $req, bool $ignoreGate = false): array
    {
        $place = null;
        $dest = null;
        if (!empty($req['destinationId']) || !empty($req['slug'])) {
            $dest = DB::table('tbl_destinations')->where('isActive', 1)
                ->when(!empty($req['destinationId']), fn($q) => $q->where('destinationId', (int) $req['destinationId']), fn($q) => $q->where('slug', (string) ($req['slug'] ?? '')))
                ->first();
            if (!$dest) {
                return ['ok' => false, 'code' => 'no_destination', 'message' => 'Không tìm thấy điểm đến.'];
            }
        } elseif (isset($req['placeLat'], $req['placeLng']) && is_numeric($req['placeLat']) && is_numeric($req['placeLng'])) {
            $place = ['name' => mb_substr(trim((string) ($req['placeName'] ?? '')) ?: 'Nơi đã chọn', 0, 120),
                'lat' => round((float) $req['placeLat'], 6), 'lng' => round((float) $req['placeLng'], 6)];
        } else {
            return ['ok' => false, 'code' => 'no_destination', 'message' => 'Hãy chọn điểm đến hoặc một nơi trên bản đồ.'];
        }
        $month = DestinationMonthBrief::validMonth($req['month'] ?? null);
        if ($month === null) {
            return ['ok' => false, 'code' => 'bad_month', 'message' => 'Tháng đi phải từ 1 đến 12.'];
        }
        $req['month'] = $month;

        $status = $dest ? $this->gate->status((int) $dest->destinationId) : ['known' => false, 'ready' => false, 'missing' => [], 'percent' => 0];
        $want = (string) ($req['mode'] ?? 'auto');
        $openOn = (bool) config('planner.open_mode.enabled', true);
        $mode = ($status['ready'] || $ignoreGate) && $want !== 'open' ? 'curated' : 'open';
        if ($mode === 'open' && (!$openOn || $want === 'curated')) {
            return ['ok' => false, 'code' => 'not_ready', 'missing' => $status['missing'],
                'message' => ($dest->name ?? $place['name']) . ' chưa đủ dữ liệu để Planner đề xuất lịch trình (còn thiếu: ' . implode('; ', $status['missing']) . ').'];
        }

        $nreq = $this->engine->normalizeRequest($req);
        $year = $nreq['startDate'] ? (int) date('Y', strtotime($nreq['startDate'])) : DestinationMonthBrief::defaultYear($month);
        $brief = $dest ? $this->brief->for((int) $dest->destinationId, $month, $year) : null;
        $center = $dest && $dest->latitude !== null && $dest->longitude !== null ? [(float) $dest->latitude, (float) $dest->longitude]
            : ($place ? [$place['lat'], $place['lng']] : null);

        $ctx = ['center' => $center, 'ratingLevel' => $brief['rating']['level'] ?? null];
        if (!empty($brief['climate'])) {
            $dim = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $ctx['rainyShare'] = $brief['climate']['rainyDays'] / $dim;
            $ctx['tMax'] = $brief['climate']['tMax'];
        }

        // T-20: chuyến trong 16 ngày tới → dự báo mưa từng ngày (Open-Meteo, cache theo giờ) thay số liệu theo tháng
        $weather = null;
        if ($center && $nreq['startDate']) {
            $weather = $this->dayRain($center, (string) $nreq['startDate'], (int) $nreq['days']);
            if ($weather) {
                $ctx['dayRain'] = $weather;
            }
        }

        $log = [];
        $cands = $dest ? $this->candidates($dest, $nreq, $year, $log) : [];
        $openSources = null;
        if ($mode === 'open' && $center) {
            $o = app(PlaceFinder::class)->openCandidates($center[0], $center[1]);
            $openSources = ['ok' => $o['ok'], 'from' => $o['from'], 'count' => count($o['items'])];
            $cands = array_merge($cands, self::osmCandidates($o['items']));
        }
        $plan = $this->engine->plan($cands, $nreq, $ctx);
        $this->verifyWithSchedule($plan, $nreq);
        $plan['filterLog'] = array_merge($log, $plan['filterLog']);
        $plan['stats']['candidates'] += count($log);
        $plan['stats']['filtered'] = count($plan['filterLog']);
        $plan['stats']['fromMap'] = count(array_filter(array_merge(...array_map(fn($d) => $d['items'], $plan['days'])), fn($i) => !empty($i['osm'])));

        return [
            'ok' => true,
            'mode' => $mode,
            'destination' => $dest ? ['id' => (int) $dest->destinationId, 'slug' => $dest->slug, 'name' => $dest->name] : ['id' => null, 'slug' => null, 'name' => $place['name']],
            'place' => $place,
            'center' => $center,
            'request' => $nreq + ['year' => $year],
            'plan' => $plan,
            'tours' => $dest ? $this->tours((int) $dest->destinationId, $nreq, $year) : [],
            'verdict' => $brief ? DestinationMonthBrief::summaryText($brief) : null,
            'gate' => $status,
            'openSources' => $openSources,
            'weather' => $weather ? ['source' => 'forecast', 'dayRain' => $weather] : null,
            'disclaimer' => DestinationMonthBrief::DISCLAIMER,
        ];
    }

    /** @return array<int,int>|null  ngày (1..n) => % mưa cao nhất 08–20h, chỉ các ngày nằm trong phạm vi dự báo */
    private function dayRain(array $center, string $start, int $days): ?array
    {
        if (!TripWeather::enabled()) {
            return null;
        }
        $horizon = (int) config('planner.weather.days', 16);
        $first = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($start)->startOfDay(), false);
        if ($first < 0 || $first >= $horizon) {
            return null;
        }
        try {
            $fc = app(TripWeather::class)->forPoints([$center]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        $f = reset($fc);
        if (!$f) {
            return null;
        }
        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = \Carbon\Carbon::parse($start)->addDays($d - 1)->toDateString();
            if (isset($f['days'][$date])) {
                $out[$d] = TripWeather::dayRain($f['days'][$date]);
            }
        }

        return $out ?: null;
    }

    /**
     * Mục OpenStreetMap -> ứng viên của PlannerEngine (type place, không có refId, osm = true).
     * Giờ mở cửa dạng OSM (nếu nguồn có) được đọc để engine không xếp vào lúc đóng cửa.
     *
     * @param list<array<string,mixed>> $items kết quả PlaceFinder::openCandidates
     * @return list<array<string,mixed>>
     */
    public static function osmCandidates(array $items): array
    {
        $kindGroup = [];
        foreach ((array) config('planner.kind_groups') as $g => $kinds) {
            foreach ($kinds as $k) {
                $kindGroup[$k] = $g;
            }
        }
        $out = [];
        foreach ($items as $it) {
            $kind = (string) ($it['kindKey'] ?? '');
            $group = $it['cat'] === 'sight' ? ($kindGroup[$kind] ?? 'see') : 'eat';
            $c = ['key' => (string) $it['key'], 'type' => 'place', 'refId' => null, 'osm' => true, 'name' => (string) $it['name'],
                'kind' => $it['cat'] === 'cafe' ? 'cafe' : ($it['cat'] === 'restaurant' ? 'restaurant' : $kind), 'group' => $group,
                'lat' => (float) $it['lat'], 'lng' => (float) $it['lng'], 'rank' => (int) ($it['rank'] ?? 0), 'mustTry' => false,
                'address' => $it['address'] ?? null, 'kindLabel' => $it['kind'] ?? null, 'website' => $it['website'] ?? null];
            if (!empty($it['hours']) && is_string($it['hours'])) {
                $h = OpeningHours::parseOsm($it['hours']);
                if ($h) {
                    $c['hours'] = $h;
                }
            }
            $out[] = $c;
        }

        return $out;
    }

    /** Lưu kết quả generate() thành chuyến đi của người dùng. Trả tripId. */
    public function save(int $userId, array $result): int
    {
        if (empty($result['ok'])) {
            throw new \InvalidArgumentException('Không thể lưu một kết quả lỗi.');
        }
        TripPlanner::assertCanCreate($userId);
        $r = $result['request'];
        $plan = $result['plan'];
        $notes = array_values(array_filter(array_merge($plan['warnings'], [$plan['budget']['message'] ?? null])));

        return DB::transaction(function () use ($userId, $result, $r, $plan, $notes) {
            $tripId = (int) DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'destinationId' => $result['destination']['id'] ?? null,
                'title' => mb_substr($result['destination']['name'] . ' ' . $r['days'] . ' ngày, tháng ' . $r['month'], 0, 160),
                'startDate' => $r['startDate'], 'days' => $r['days'], 'budget' => $r['budget'], 'groupSize' => $r['groupSize'],
                'status' => 'planned', 'month' => $r['month'], 'source' => 'planner',
                'interests' => json_encode($r['interests'], JSON_UNESCAPED_UNICODE), 'dislikes' => json_encode($r['dislikes'], JSON_UNESCAPED_UNICODE),
                'notes' => $notes ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null,
                'createdAt' => now(), 'updatedAt' => now(),
            ]);
            $rows = [];
            $seen = [];
            foreach ($plan['days'] as $day) {
                foreach ($day['items'] as $order => $it) {
                    $seen[$it['key']] = true;
                    $rows[] = self::row($tripId, $it, $day['day'], $order, [
                        'slot' => $it['slot'], 'cost' => $this->engine->costOf($it, $r), 'durationMin' => $it['durationMin'],
                        'reasons' => $it['reasons'] ? json_encode($it['reasons'], JSON_UNESCAPED_UNICODE) : null]);
                }
            }
            // mục điểm cao chưa xếp được (hết chỗ, đóng cửa ngày đó): vào khu "Đã lưu" (dayNo = null) thay vì bị bỏ đi
            $o = 0;
            foreach ($plan['unplaced'] ?? [] as $u) {
                if (isset($seen[$u['key']]) || (empty($u['refId']) && empty($u['osm'])) || !in_array($u['type'], TripPlanner::TYPES, true)) {
                    continue;
                }
                $seen[$u['key']] = true;
                $reasons = array_values(array_filter(array_merge($u['reasons'] ?? [], [$u['why'] ?? null])));
                $rows[] = self::row($tripId, $u, null, $o++, ['slot' => null, 'cost' => null, 'durationMin' => $u['durationMin'] ?? null,
                    'reasons' => $reasons ? json_encode($reasons, JSON_UNESCAPED_UNICODE) : null]);
            }
            if ($rows) {
                DB::table('tbl_trip_items')->insert($rows);
            }

            return $tripId;
        });
    }

    /**
     * Một dòng tbl_trip_items. Mục nội dung Travela: refType/refId. Mục bản đồ (osm): lưu như địa điểm tự thêm
     * (refType note, có tên và tọa độ, source = search), nên trang sửa hiện "Ngoài dữ liệu Travela" và tính đường đi bình thường.
     */
    private static function row(int $tripId, array $it, ?int $day, int $order, array $extra): array
    {
        $osm = !empty($it['osm']);

        return ['tripId' => $tripId, 'refType' => $osm ? 'note' : $it['type'], 'refId' => $osm ? null : (int) $it['refId'],
            'label' => $osm ? mb_substr((string) $it['name'], 0, 120) : null,
            'latitude' => $osm && isset($it['lat']) ? round((float) $it['lat'], 7) : null,
            'longitude' => $osm && isset($it['lng']) ? round((float) $it['lng'], 7) : null,
            'source' => $osm && isset($it['lat']) ? 'search' : null,
            'dayNo' => $day, 'orderNo' => $order] + $extra;
    }

    // ------------------------------------------------------------------ nạp ứng viên

    /**
     * Danh sách ứng viên ĐÚNG như engine nhận vào (trước lọc cứng) cho một yêu cầu, dùng cho thí nghiệm A1b
     * ("LLM + dữ liệu thật, không engine"): LLM và engine nhận cùng dữ liệu, khác nhau ở cách lọc và xếp.
     * @return array{ok:bool,dest?:object,req?:array,cands?:list<array>}
     */
    public function candidatesFor(array $req): array
    {
        $dest = DB::table('tbl_destinations')->where('isActive', 1)->where('slug', (string) ($req['slug'] ?? ''))->first();
        if (!$dest) {
            return ['ok' => false];
        }
        $nreq = $this->engine->normalizeRequest($req);
        $year = $nreq['startDate'] ? (int) date('Y', strtotime($nreq['startDate'])) : DestinationMonthBrief::defaultYear($nreq['month']);
        $log = [];

        return ['ok' => true, 'dest' => $dest, 'req' => $nreq, 'cands' => $this->candidates($dest, $nreq, $year, $log)];
    }

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

        // trong nhà / ngoài trời theo loại nơi (cùng danh sách với ItemInsights): dùng cho mưa theo mùa và dự báo theo ngày (T-20)
        $inKinds = (array) config('planner.indoor_place_kinds', []);
        $outKinds = array_merge((array) config('planner.dislikes.outdoor.place_kinds', []), ['peak', 'beach', 'viewpoint', 'cave_entrance', 'hot_spring']);
        foreach (DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', 'published')->get() as $p) {
            $out[] = ['key' => 'place:' . $p->placeId, 'type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'kind' => $p->kind,
                'group' => $kindGroup[$p->kind] ?? 'see', 'lat' => $p->latitude !== null ? (float) $p->latitude : null, 'lng' => $p->longitude !== null ? (float) $p->longitude : null,
                'rank' => (int) $p->rank, 'mustTry' => !empty($p->isMustTry),
                'indoor' => in_array($p->kind, $inKinds, true) ? true : (in_array($p->kind, $outKinds, true) ? false : null)];
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

        // món ăn: có quán gắn sẵn (tbl_foods.placeId) thì dùng vị trí quán; không thì không có vị trí (engine không tạo chặng đi giả)
        $fq = DB::table('tbl_foods as f')->where('f.reviewStatus', 'published')->where(function ($q) use ($id) {
            $q->where('f.destinationId', $id)->orWhereIn('f.foodId', DB::table('tbl_food_destination')->where('destinationId', $id)->select('foodId'));
        });
        if (\Illuminate\Support\Facades\Schema::hasColumn('tbl_foods', 'placeId')) {
            $fq->leftJoin('tbl_external_places as fp', function ($j) {
                $j->on('fp.placeId', '=', 'f.placeId')->where('fp.reviewStatus', 'published');
            })->select('f.*', 'fp.latitude as pLat', 'fp.longitude as pLng');
        }
        foreach ($fq->get() as $f) {
            $out[] = ['key' => 'food:' . $f->foodId, 'type' => 'food', 'refId' => (int) $f->foodId, 'name' => $f->name, 'category' => (string) $f->category,
                'monthFrom' => $f->monthFrom, 'monthTo' => $f->monthTo, 'lat' => isset($f->pLat) && $f->pLat !== null ? (float) $f->pLat : null,
                'lng' => isset($f->pLng) && $f->pLng !== null ? (float) $f->pLng : null, 'mustTry' => !empty($f->isMustTry)];
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

        // giờ mở cửa (địa điểm, trải nghiệm): engine không xếp vào lúc / ngày đóng cửa khi biết ngày đi
        $hours = app(OpeningHours::class)->forItems(array_map(fn($c) => ['type' => $c['type'], 'refId' => $c['refId']], $out));
        foreach ($out as &$c) {
            if (isset($hours[$c['key']])) {
                $c['hours'] = $hours[$c['key']];
            }
        }
        unset($c);

        return $out;
    }

    /**
     * Kiểm tra lại từng ngày bằng ĐÚNG bộ xếp giờ của trang sửa / TripHealth (DaySchedule): mục nào sẽ bị báo
     * "đóng cửa / tới sau giờ đóng" thì đưa ra khu "Đã lưu" trước khi lưu, để chuyến vừa tạo mở ra không có lỗi cứng về giờ mở cửa.
     */
    private function verifyWithSchedule(array &$plan, array $req): void
    {
        if (empty($req['startDate']) && ($req['arriveMin'] ?? null) === null && ($req['leaveMin'] ?? null) === null) {
            return;   // chưa có ngày đi: không biết thứ, TripHealth cũng không kiểm tra giờ mở cửa
        }
        $route = RouteService::make();
        foreach ($plan['days'] as &$day) {
            for ($guard = 0; $guard < 10; $guard++) {
                $rows = array_map(fn($it) => ['type' => $it['type'], 'refId' => $it['refId'], 'name' => $it['name'], 'kind' => $it['kind'] ?? null,
                    'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null, 'slot' => $it['slot'], 'durationMin' => $it['durationMin'], 'hours' => $it['hours'] ?? null], $day['items']);
                $ctx = ['arrive' => $day['day'] === 1 ? ($req['arriveMin'] ?? null) : null, 'leave' => $day['day'] === (int) $req['days'] ? ($req['leaveMin'] ?? null) : null];
                $sch = new DaySchedule($day['day'], $rows, $route, null, $req['startDate'] ? OpeningHours::dowOf($req['startDate'], $day['day']) : null, $ctx);
                $bad = null;
                foreach ($sch->rows as $i => $r) {
                    if (in_array($r['hours'], ['closed', 'late'], true) || $r['conflict'] === 'departure') {
                        $bad = $i;
                        break;
                    }
                }
                if ($bad === null) {
                    break;
                }
                $it = $day['items'][$bad];
                array_splice($day['items'], $bad, 1);
                $why = 'Ngày ' . $day['day'] . ': ' . ($sch->rows[$bad]['conflict'] === 'departure' ? 'không kịp trước giờ phải rời đi' : ($sch->rows[$bad]['hours'] === 'closed' ? 'đóng cửa' : 'tới sau giờ đóng cửa'));
                $plan['filterLog'][] = ['key' => $it['key'], 'name' => $it['name'], 'type' => $it['type'], 'code' => 'hours', 'reason' => $why];
                $plan['unplaced'][] = ['key' => $it['key'], 'name' => $it['name'], 'type' => $it['type'], 'refId' => (int) $it['refId'], 'score' => $it['score'] ?? 0,
                    'reasons' => $it['reasons'] ?? [], 'durationMin' => $it['durationMin'] ?? null, 'priceMin' => $it['priceMin'] ?? null, 'why' => $why,
                    'osm' => !empty($it['osm']), 'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null];
            }
        }
        unset($day);
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
                'priceChild' => $t->priceChild !== null ? (float) $t->priceChild : null,
                // giá cả nhóm: người lớn theo priceAdult, trẻ em theo priceChild (tour chưa có giá trẻ em thì tính như người lớn)
                'forGroup' => (float) $t->priceAdult * ($req['adults'] ?? $req['groupSize']) + (float) ($t->priceChild ?? $t->priceAdult) * ($req['children'] ?? 0),
                'url' => route('tour-detail', ['id' => $t->tourId])];
        }
        if (!$out) {
            return $out;
        }
        // A-01: cùng công thức Tour Match với trang sửa chuyến (điểm thành phần để La bàn / giao diện giải thích vì sao hợp)
        $want = ['startDate' => $req['startDate'] ?? null, 'month' => $req['month'] ?? null, 'days' => (int) $req['days'],
            'adults' => (int) ($req['adults'] ?? $req['groupSize'] ?? 1), 'children' => (int) ($req['children'] ?? 0),
            'budget' => $req['budget'] ?? null, 'interests' => (array) ($req['interests'] ?? []), 'placeNames' => []];
        $ranked = [];
        foreach (app(TourMatch::class)->rank($want, [$destId], 40) as $m) {
            $ranked[$m['tourId']] = $m;
        }
        foreach ($out as &$o) {
            if (isset($ranked[$o['id']])) {
                $m = $ranked[$o['id']];
                $o += ['score' => $m['score'], 'pct' => $m['pct'], 'components' => $m['components'], 'bookUrl' => $m['bookUrl']];
            }
        }
        unset($o);
        usort($out, fn($a, $b) => [($b['score'] ?? 0), $a['id']] <=> [($a['score'] ?? 0), $b['id']]);

        return $out;
    }
}
