<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Support\Geo;
use Illuminate\Support\Str;

/**
 * Tuần 5: lõi Planner tự đề xuất lịch trình MỘT điểm đến (kế hoạch v6, mục 0.1 và 11).
 *
 * HÀM THUẦN: không đụng DB, không đọc giờ hệ thống; cùng đầu vào cho cùng đầu ra (hòa điểm thì xếp theo khóa).
 * PlannerService nạp ứng viên từ DB rồi gọi lớp này; kiểm thử đơn vị truyền dữ liệu giả.
 *
 * Quy trình: (1) lọc CỨNG và ghi nhật ký lý do loại, (2) chấm điểm MỀM kèm lý do chọn (chỉ lý do có dữ liệu thật),
 * (3) chọn số mục theo nhịp, (4) gom theo vị trí thành từng ngày, (5) xếp bữa trưa/tối, (6) tính giờ + khung giờ + quãng đường,
 * (7) ước tính ngân sách theo quy tắc 0.1-D (chỉ cộng giá ĐÃ BIẾT, không bao giờ kết luận "vừa ngân sách").
 *
 * Ứng viên ($candidates), mỗi phần tử:
 *  key "type:refId", type place|experience|food|event, refId, name, group (eat|nature|culture|fun|see|null), kind, category,
 *  lat, lng (null = dùng tâm điểm đến, đánh dấu ước lượng), durationMin?, difficulty?, indoor?(bool|null), mustTry(bool), rank(int),
 *  monthFrom?, monthTo?, priceMin?, priceMax?, eventStart?, eventEnd? (Y-m-d)
 * Yêu cầu ($req): month, days, interests[], dislikes[], budget?, groupSize, pace?, maxDifficulty?, startDate?
 * Bối cảnh ($ctx): center [lat,lng]|null, rainyShare?, tMax?, ratingLevel?
 */
final class PlannerEngine
{
    private array $cfg;
    private array $interests;

    public function __construct(?array $cfg = null, ?array $interests = null)
    {
        $this->cfg = $cfg ?? (array) config('planner');
        $this->interests = $interests ?? (array) config('interests');
    }

    public static function normalize(string $s): string
    {
        return ' ' . trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($s)))) . ' ';
    }

    /** @return array{days:list<array>,filterLog:list<array>,warnings:list<string>,budget:array,stats:array,unplaced:list<array>} */
    public function plan(array $candidates, array $req, array $ctx = []): array
    {
        $req = $this->normalizeRequest($req);
        $log = [];
        $warnings = [];

        // (1) lọc cứng
        $kept = $this->hardFilter($candidates, $req, $ctx, $log);

        // (2) chấm điểm mềm
        $maxRank = 0;
        foreach ($kept as $c) {
            $maxRank = max($maxRank, (int) ($c['rank'] ?? 0));
        }
        $scored = [];
        foreach ($kept as $c) {
            [$c['score'], $c['reasons']] = $this->score($c, $req, $ctx, $maxRank);
            $c['isMeal'] = $this->isMeal($c);
            $scored[] = $c;
        }
        $cmp = fn($a, $b) => [$b['score'], $a['key']] <=> [$a['score'], $b['key']];   // điểm giảm dần, hòa thì theo khóa
        usort($scored, $cmp);

        $acts = array_values(array_filter($scored, fn($c) => !$c['isMeal']));
        $meals = array_values(array_filter($scored, fn($c) => $c['isMeal']));

        // (3) số mục hoạt động
        $pace = $this->cfg['pace'][$req['pace']];
        $perDayMax = (int) $pace['max_per_day'];
        $perDayMin = (int) $pace['min_per_day'];
        $dayBudget = (int) round($this->cfg['day_budget_min'] * (float) $pace['budget_factor']);
        $want = $req['days'] * $perDayMax;
        $picked = array_slice($acts, 0, $want);
        $leftover = array_slice($acts, $want);

        // (4) gom theo ngày
        $center = $ctx['center'] ?? $this->centroid($picked ?: $scored);
        $dayLists = $this->assignDays($picked, $req, $center, $perDayMax, $dayBudget, $log);

        // bổ sung ngày thiếu mục từ phần còn lại (gần trọng tâm ngày, trong giới hạn khoảng cách)
        for ($d = 0; $d < $req['days']; $d++) {
            while (count($dayLists[$d]) < $perDayMin && $leftover) {
                $cent = $this->centroid($dayLists[$d] ?: $picked) ?? $center;
                $best = null;
                foreach ($leftover as $i => $c) {
                    $km = $this->km($cent, $this->pt($c, $center));
                    if ($km <= $this->cfg['max_leg_km'] && ($best === null || $km < $best[1])) {
                        $best = [$i, $km];
                    }
                }
                if ($best === null) {
                    break;
                }
                $dayLists[$d][] = $leftover[$best[0]];
                array_splice($leftover, $best[0], 1);
            }
        }

        // (5) bữa ăn
        $usedMeal = [];
        $dayMeals = [];
        for ($d = 0; $d < $req['days']; $d++) {
            $cent = $this->centroid($dayLists[$d]) ?? $center;
            $dayMeals[$d] = [];
            for ($m = 0; $m < (int) $this->cfg['meals_per_day']; $m++) {
                $best = null;
                foreach ($meals as $c) {
                    if (isset($usedMeal[$c['key']])) {
                        continue;
                    }
                    $km = $cent ? $this->km($cent, $this->pt($c, $center)) : 0.0;
                    if ($km > $this->cfg['max_leg_km']) {
                        continue;
                    }
                    // điểm cao trước; cùng điểm thì gần hơn
                    $rank = [-$c['score'], round($km, 2), $c['key']];
                    if ($best === null || $rank < $best[1]) {
                        $best = [$c, $rank];
                    }
                }
                if ($best === null) {
                    break;
                }
                $usedMeal[$best[0]['key']] = true;
                $dayMeals[$d][] = $best[0];
            }
            if (count($dayMeals[$d]) < (int) $this->cfg['meals_per_day'] && $dayLists[$d]) {
                $warnings[] = 'Ngày ' . ($d + 1) . ': chưa đủ quán/món đã duyệt để xếp bữa trưa và tối. Hãy tự thêm bữa ăn.';
            }
        }

        // (6) giờ, khung giờ, quãng đường
        $days = [];
        $selected = [];
        for ($d = 0; $d < $req['days']; $d++) {
            $day = $this->timeline($d + 1, $dayLists[$d], $dayMeals[$d], $center, $dayBudget, $warnings);
            foreach ($day['items'] as $it) {
                $selected[] = $it;
            }
            $days[] = $day;
        }
        if (!array_filter($days, fn($x) => $x['items'])) {
            $warnings[] = 'Không đủ dữ liệu đã duyệt để đề xuất lịch trình cho lựa chọn này.';
        } else {
            $nAct = count(array_filter($selected, fn($i) => !$i['isMeal']));
            if ($nAct < $req['days'] * $perDayMin) {
                $warnings[] = 'Dữ liệu chỉ đủ ' . $nAct . ' hoạt động cho ' . $req['days'] . ' ngày (mục tiêu tối thiểu ' . ($req['days'] * $perDayMin) . '). Một số ngày sẽ thưa.';
            }
        }

        $unplaced = array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'score' => $c['score']], array_slice($leftover, 0, 10));

        return [
            'days' => $days,
            'filterLog' => $log,
            'warnings' => array_values(array_unique($warnings)),
            'budget' => $this->budget($selected, $req),
            'stats' => ['candidates' => count($candidates), 'kept' => count($kept), 'selected' => count($selected), 'filtered' => count($log)],
            'unplaced' => $unplaced,
        ];
    }

    // ------------------------------------------------------------------ (0) chuẩn hóa yêu cầu

    public function normalizeRequest(array $r): array
    {
        $maxDays = (int) $this->cfg['max_days'];
        $pace = isset($this->cfg['pace'][$r['pace'] ?? '']) ? $r['pace'] : $this->cfg['default_pace'];
        $diff = isset($this->cfg['difficulty_rank'][$r['maxDifficulty'] ?? '']) ? $r['maxDifficulty'] : $this->cfg['default_max_difficulty'];

        return [
            'month' => (int) ($r['month'] ?? 1),
            'days' => max(1, min($maxDays, (int) ($r['days'] ?? 1))),
            'interests' => array_values(array_intersect((array) ($r['interests'] ?? []), array_keys($this->interests))),
            'dislikes' => array_values(array_intersect((array) ($r['dislikes'] ?? []), array_keys($this->cfg['dislikes']))),
            'budget' => isset($r['budget']) && $r['budget'] !== '' && $r['budget'] !== null ? (float) $r['budget'] : null,
            'groupSize' => max(1, (int) ($r['groupSize'] ?? 1)),
            'pace' => $pace,
            'maxDifficulty' => $diff,
            'startDate' => !empty($r['startDate']) ? (string) $r['startDate'] : null,
        ];
    }

    // ------------------------------------------------------------------ (1) lọc cứng

    private function hardFilter(array $cands, array $req, array $ctx, array &$log): array
    {
        $rank = $this->cfg['difficulty_rank'];
        $maxRank = $rank[$req['maxDifficulty']];
        $seen = [];
        $out = [];
        $reject = function (array $c, string $code, string $why) use (&$log) {
            $log[] = ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'code' => $code, 'reason' => $why];
        };
        $tripEnd = $req['startDate'] ? date('Y-m-d', strtotime($req['startDate'] . ' +' . ($req['days'] - 1) . ' days')) : null;

        foreach ($cands as $c) {
            $c['key'] = (string) ($c['key'] ?? ($c['type'] . ':' . $c['refId']));
            $c['rank'] = (int) ($c['rank'] ?? 0);
            $c['mustTry'] = !empty($c['mustTry']);

            // "không thích"
            foreach ($req['dislikes'] as $dk) {
                $rule = $this->cfg['dislikes'][$dk] ?? [];
                if ($c['type'] === 'place' && in_array($c['kind'] ?? null, $rule['place_kinds'] ?? [], true)) {
                    $reject($c, 'dislike', 'Bạn chọn "không thích" ' . $dk . ' (loại địa điểm ' . ($c['kind'] ?? '') . ')');
                    continue 2;
                }
                if ($c['type'] === 'experience') {
                    if (in_array($c['difficulty'] ?? null, $rule['experience_difficulty'] ?? [], true)) {
                        $reject($c, 'dislike', 'Bạn chọn "không thích" ' . $dk . ' (trải nghiệm độ khó ' . $c['difficulty'] . ')');
                        continue 2;
                    }
                    if (array_key_exists('experience_indoor', $rule) && array_key_exists('indoor', $c) && $c['indoor'] !== null
                        && in_array((bool) $c['indoor'], $rule['experience_indoor'], true)) {
                        $reject($c, 'dislike', 'Bạn chọn "không thích" ' . $dk . ' (trải nghiệm ngoài trời)');
                        continue 2;
                    }
                }
            }
            // độ khó tối đa
            if ($c['type'] === 'experience' && !empty($c['difficulty']) && ($rank[$c['difficulty']] ?? 0) > $maxRank) {
                $reject($c, 'difficulty', 'Độ khó "' . $c['difficulty'] . '" vượt mức tối đa bạn chọn ("' . $req['maxDifficulty'] . '")');
                continue;
            }
            // mùa: chỉ loại khi ĐÃ khai báo đủ hai đầu và không trùng tháng đi
            if (in_array($c['type'], ['experience', 'food'], true)
                && DestinationMonthBrief::inSeason($c['monthFrom'] ?? null, $c['monthTo'] ?? null, $req['month']) === false) {
                $reject($c, 'season', 'Mùa của mục này (tháng ' . (int) $c['monthFrom'] . '–' . (int) $c['monthTo'] . ') không trùng tháng ' . $req['month']);
                continue;
            }
            // lễ hội phải diễn ra trong chuyến khi biết ngày đi
            if ($c['type'] === 'event' && $tripEnd && !empty($c['eventStart']) && !empty($c['eventEnd'])
                && ($c['eventStart'] > $tripEnd || $c['eventEnd'] < $req['startDate'])) {
                $reject($c, 'event_outside_trip', 'Lễ hội diễn ra ' . $c['eventStart'] . ' → ' . $c['eventEnd'] . ', ngoài thời gian chuyến đi');
                continue;
            }
            // địa điểm phải có tọa độ (không có thì không tính được lộ trình)
            if ($c['type'] === 'place' && (($c['lat'] ?? null) === null || ($c['lng'] ?? null) === null)) {
                $reject($c, 'no_coords', 'Địa điểm chưa có tọa độ nên không xếp được lộ trình');
                continue;
            }
            // trùng tên (cùng loại)
            $nk = $c['type'] . '|' . self::normalize((string) $c['name']);
            if (isset($seen[$nk])) {
                $reject($c, 'duplicate', 'Trùng tên với "' . $seen[$nk] . '"');
                continue;
            }
            $seen[$nk] = $c['name'];
            $out[] = $c;
        }

        return $out;
    }

    // ------------------------------------------------------------------ (2) chấm điểm

    /** @return array{0:int|float,1:list<string>} điểm và các lý do chọn (chỉ lý do có dữ liệu thật) */
    private function score(array $c, array $req, array $ctx, int $maxRank): array
    {
        $w = $this->cfg['score'];
        $score = 0.0;
        $why = [];

        if ($c['mustTry']) {
            $score += $w['must_try'];
            $why[] = 'Biên tập viên đánh dấu "phải thử/phải ghé"';
        }

        $hit = [];
        $hay = self::normalize(trim(($c['category'] ?? '') . ' ' . ($c['name'] ?? '')));
        foreach ($req['interests'] as $ik) {
            $def = $this->interests[$ik] ?? null;
            if (!$def) {
                continue;
            }
            $match = ($c['type'] === 'place' && in_array($c['group'] ?? null, $def['place_groups'] ?? [], true))
                || ($c['type'] === 'food' && !empty($def['foods']))
                || ($c['type'] === 'experience' && !empty($def['difficulty_match']) && in_array($c['difficulty'] ?? null, $def['difficulty'] ?? [], true))
                || $this->kwHit($hay, $def);
            if ($match) {
                $hit[] = $def['label'];
            }
        }
        $hit = array_slice($hit, 0, (int) $w['interest_cap']);
        if ($hit) {
            $score += $w['interest_match'] * count($hit);
            $why[] = 'Hợp sở thích: ' . implode(', ', $hit);
        }

        $m = $req['month'];
        if (in_array($c['type'], ['experience', 'food'], true) && DestinationMonthBrief::inSeason($c['monthFrom'] ?? null, $c['monthTo'] ?? null, $m) === true) {
            $score += $w['in_season'];
            $why[] = 'Đúng mùa (tháng ' . (int) $c['monthFrom'] . ((int) $c['monthFrom'] === (int) $c['monthTo'] ? '' : '–' . (int) $c['monthTo']) . ')';
        }
        if ($c['type'] === 'event') {
            $score += $w['event_in_month'];
            $why[] = 'Lễ hội diễn ra trong thời gian đi' . (!empty($c['eventStart']) ? ' (' . date('d/m', strtotime($c['eventStart'])) . ')' : '');
        }
        if ($c['type'] === 'place' && $maxRank > 0 && ($c['rank'] ?? 0) > 0) {
            $score += $w['rank_max'] * ((int) $c['rank'] / $maxRank);
            if ((int) $c['rank'] / $maxRank >= 0.7) {
                $why[] = 'Nổi bật theo xếp hạng dữ liệu bản đồ';
            }
        }

        $rainy = isset($ctx['rainyShare']) && $ctx['rainyShare'] !== null && $ctx['rainyShare'] >= $this->cfg['rainy_share'];
        $indoor = array_key_exists('indoor', $c) ? $c['indoor'] : null;
        if ($rainy && $indoor === true) {
            $score += $w['rain_indoor'];
            $why[] = 'Tháng ' . $m . ' mưa nhiều, mục trong nhà nên hợp';
        } elseif ($rainy && $indoor === false) {
            $score += $w['rain_outdoor'];
        }
        if (isset($ctx['tMax']) && $ctx['tMax'] !== null && $ctx['tMax'] > $this->cfg['hot_tmax'] && $indoor === false && ($c['difficulty'] ?? null) === 'hard') {
            $score += $w['hot_outdoor_hard'];
        }
        if (($ctx['ratingLevel'] ?? null) === 'avoid' && $indoor === false) {
            $score += $w['avoid_month_outdoor'];
        }

        return [round($score, 2), $why];
    }

    /** Từ khóa ngắn (dưới 4 ký tự) chỉ khớp nguyên từ; từ khóa dài khớp theo đoạn con. $hay đã bỏ dấu, có khoảng trắng hai đầu. */
    private function kwHit(string $hay, array $def): bool
    {
        foreach ($def['keywords'] ?? [] as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '') {
                continue;
            }
            if (str_contains($hay, ' ' . $kw . ' ') || (strlen($kw) >= 4 && str_contains($hay, $kw))) {
                return true;
            }
        }

        return false;
    }

    private function isMeal(array $c): bool
    {
        return $c['type'] === 'food' || ($c['type'] === 'place' && ($c['group'] ?? null) === 'eat');
    }

    // ------------------------------------------------------------------ (4) gom ngày theo vị trí

    /**
     * Chọn $days "hạt giống" cách xa nhau (farthest-point), gán mỗi mục vào hạt giống gần nhất còn chỗ.
     * Lễ hội biết ngày diễn ra được ghim vào đúng ngày đó. Trả về $days danh sách (đã sắp theo đường đi gần nhất).
     */
    private function assignDays(array $picked, array $req, ?array $center, int $perDayMax, int $dayBudget, array &$log): array
    {
        $n = $req['days'];
        $lists = array_fill(0, $n, []);
        $load = array_fill(0, $n, 0);
        $pool = [];

        foreach ($picked as $c) {
            if ($c['type'] === 'event' && $req['startDate'] && !empty($c['eventStart'])) {
                $off = (int) floor((strtotime(max($c['eventStart'], $req['startDate'])) - strtotime($req['startDate'])) / 86400);
                if ($off >= 0 && $off < $n && count($lists[$off]) < $perDayMax) {
                    $lists[$off][] = $c;
                    $load[$off] += $this->dur($c);
                    continue;
                }
            }
            $pool[] = $c;
        }

        // hạt giống: mục điểm cao nhất, rồi lần lượt mục xa các hạt giống hiện có nhất
        $seeds = [];
        foreach ($lists as $d => $l) {
            if ($l) {
                $seeds[$d] = $this->pt($l[0], $center);
            }
        }
        $free = array_keys(array_diff_key(array_fill(0, $n, 1), $seeds));
        foreach ($free as $d) {
            $best = null;
            foreach ($pool as $i => $c) {
                $p = $this->pt($c, $center);
                $dist = $seeds ? min(array_map(fn($s) => $this->km($p, $s), $seeds)) : 0.0;
                if ($best === null || $dist > $best[1] + 1e-9) {
                    $best = [$i, $dist];
                }
            }
            if ($best === null) {
                break;
            }
            $c = $pool[$best[0]];
            array_splice($pool, $best[0], 1);
            $lists[$d][] = $c;
            $load[$d] += $this->dur($c);
            $seeds[$d] = $this->pt($c, $center);
        }

        foreach ($pool as $c) {
            $p = $this->pt($c, $center);
            $order = array_keys($seeds);
            usort($order, fn($a, $b) => [$this->km($p, $seeds[$a]), $a] <=> [$this->km($p, $seeds[$b]), $b]);
            $placed = false;
            foreach ($order as $d) {
                $dur = $this->dur($c);
                if (count($lists[$d]) < $perDayMax && $load[$d] + $dur <= $dayBudget) {
                    $lists[$d][] = $c;
                    $load[$d] += $dur;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $log[] = ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'code' => 'capacity',
                    'reason' => 'Các ngày đều đã đủ thời gian/số mục, không còn chỗ cho mục này'];
            }
        }

        foreach ($lists as $d => $l) {
            $lists[$d] = $this->orderByRoute($l, $center);
        }

        return $lists;
    }

    /** Đi từ mục gần tâm điểm đến nhất, mỗi bước sang mục chưa đi gần nhất (hòa thì theo khóa). */
    private function orderByRoute(array $items, ?array $center): array
    {
        if (count($items) < 2) {
            return $items;
        }
        $cur = $center;
        $left = $items;
        $out = [];
        while ($left) {
            $best = null;
            foreach ($left as $i => $c) {
                $d = $cur ? $this->km($cur, $this->pt($c, $center)) : 0.0;
                $k = [round($d, 3), $c['key']];
                if ($best === null || $k < $best[1]) {
                    $best = [$i, $k];
                }
            }
            $c = $left[$best[0]];
            array_splice($left, $best[0], 1);
            $out[] = $c;
            $cur = $this->pt($c, $center);
        }

        return $out;
    }

    // ------------------------------------------------------------------ (6) timeline

    private function timeline(int $dayNo, array $acts, array $meals, ?array $center, int $dayBudget, array &$warnings): array
    {
        // hoạt động buổi sáng đến khi đủ ~200 phút, bữa trưa, hoạt động buổi chiều, bữa tối
        $morning = [];
        $afternoon = [];
        $acc = 0;
        foreach ($acts as $c) {
            if ($acc < 200) {
                $morning[] = $c;
                $acc += $this->dur($c);
            } else {
                $afternoon[] = $c;
            }
        }
        $seq = [];
        foreach ($morning as $c) {
            $seq[] = [$c, null];
        }
        if (isset($meals[0])) {
            $seq[] = [$meals[0], 'noon'];
        }
        foreach ($afternoon as $c) {
            $seq[] = [$c, null];
        }
        if (isset($meals[1])) {
            $seq[] = [$meals[1], 'evening'];
        }

        $bounds = $this->cfg['slot_bounds_min'];
        $clock = 0;
        $prev = null;
        $items = [];
        $travelTotal = 0;
        $kmTotal = 0.0;
        $actMin = 0;
        foreach ($seq as $i => [$c, $forced]) {
            $p = $this->pt($c, $center);
            $travelKm = 0.0;
            $travelMin = 0;
            if ($prev !== null) {
                $straight = $this->km($prev, $p);
                if ($straight >= 0.5) {   // cùng một khu: không tính đoạn đường
                    $travelKm = $straight * (float) $this->cfg['detour_factor'];
                    $travelMin = (int) round($travelKm / (float) $this->cfg['travel_speed_kmh'] * 60);
                }
            }
            $clock += $travelMin;
            $slot = $forced ?? $this->slotAt($clock, $bounds);
            if ($forced === null && $slot === 'noon') {
                $slot = 'afternoon';   // khung trưa dành cho bữa ăn
            }
            if ($forced === 'noon' && $clock < $bounds['noon'][0]) {
                $clock = $bounds['noon'][0];   // bữa trưa không sớm hơn 11:30
            }
            if ($forced === 'evening' && $clock < $bounds['evening'][0]) {
                $clock = $bounds['evening'][0];   // bữa tối không sớm hơn 18:00
            }
            $dur = $this->dur($c);
            $items[] = $c + ['slot' => $slot, 'startMin' => $clock, 'durationMin' => $dur, 'travelKm' => round($travelKm, 1), 'travelMin' => $travelMin, 'coordsApprox' => ($c['lat'] ?? null) === null || ($c['lng'] ?? null) === null];
            $clock += $dur;
            if (!$c['isMeal']) {
                $actMin += $dur;
            }
            $travelTotal += $travelMin;
            $kmTotal += $travelKm;
            $prev = $p;
        }

        if ($actMin + $travelTotal > $dayBudget * 1.1) {
            $warnings[] = 'Ngày ' . $dayNo . ' khá dày: khoảng ' . round(($actMin + $travelTotal) / 60, 1) . ' giờ hoạt động và di chuyển.';
        }
        if ($travelTotal > (int) $this->cfg['max_travel_min_per_day']) {
            $warnings[] = 'Ngày ' . $dayNo . ' di chuyển khoảng ' . round($travelTotal / 60, 1) . ' giờ (ước tính), nên bớt điểm hoặc chia sang ngày khác.';
        }

        return ['day' => $dayNo, 'items' => $items, 'activityMin' => $actMin, 'travelMin' => $travelTotal, 'km' => (int) round($kmTotal)];
    }

    private function slotAt(int $min, array $bounds): string
    {
        foreach ($bounds as $slot => [$from, $to]) {
            if ($min >= $from && $min < $to) {
                return $slot;
            }
        }

        return 'evening';
    }

    // ------------------------------------------------------------------ (7) ngân sách (quy tắc 0.1-D)

    private function budget(array $items, array $req): array
    {
        $g = $req['groupSize'];
        $knownMin = 0.0;
        $knownMax = 0.0;
        $unknown = 0;
        $priced = 0;
        foreach ($items as $it) {
            $min = $it['priceMin'] ?? null;
            $max = $it['priceMax'] ?? null;
            if ($min === null && $max === null) {
                $unknown++;
                continue;
            }
            $priced++;
            $lo = (float) ($min ?? $max);
            $hi = (float) ($max ?? $min);
            $knownMin += $lo * $g;
            $knownMax += max($lo, $hi) * $g;
        }
        $status = $priced === 0 ? 'UNKNOWN' : ($unknown === 0 ? 'FULL' : 'PARTIAL');
        $limit = $req['budget'];
        $msg = match ($status) {
            'UNKNOWN' => 'Chưa có giá cho các mục được chọn, nên chưa ước tính được chi phí.',
            'PARTIAL' => 'Mới biết giá của ' . $priced . '/' . count($items) . ' mục; con số này CHƯA phải tổng chi phí.',
            default => 'Đã biết giá của mọi mục được chọn (chưa gồm đi lại, lưu trú và chi tiêu cá nhân).',
        };
        if ($limit !== null && $priced > 0 && $knownMin > $limit) {
            $msg .= ' Riêng phần đã biết giá đã vượt ngân sách bạn nhập.';
        }

        return ['status' => $status, 'knownMin' => $knownMin, 'knownMax' => $knownMax, 'pricedCount' => $priced, 'unknownCount' => $unknown,
            'limit' => $limit, 'overKnown' => $limit !== null && $priced > 0 && $knownMin > $limit, 'groupSize' => $g, 'message' => $msg];
    }

    // ------------------------------------------------------------------ tiện ích

    private function dur(array $c): int
    {
        if (!empty($c['durationMin'])) {
            return (int) $c['durationMin'];
        }
        $d = $this->cfg['default_duration_min'];
        if ($c['type'] === 'place') {
            return (int) ($d['place'][$c['group'] ?? 'default'] ?? $d['place']['default']);
        }

        return (int) ($d[$c['type']] ?? 60);
    }

    /** @return array{0:float,1:float}|null */
    private function pt(array $c, ?array $center): ?array
    {
        if (($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null) {
            return [(float) $c['lat'], (float) $c['lng']];
        }

        return $center;
    }

    private function km(?array $a, ?array $b): float
    {
        return $a && $b ? Geo::distanceKm($a[0], $a[1], $b[0], $b[1]) : 0.0;
    }

    private function centroid(array $items): ?array
    {
        $la = 0.0;
        $lo = 0.0;
        $n = 0;
        foreach ($items as $c) {
            if (($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null) {
                $la += (float) $c['lat'];
                $lo += (float) $c['lng'];
                $n++;
            }
        }

        return $n ? [$la / $n, $lo / $n] : null;
    }
}
