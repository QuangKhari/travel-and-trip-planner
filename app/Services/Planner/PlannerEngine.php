<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Planner\Route\RouteService;
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
 * Yêu cầu ($req): month, days, interests[], dislikes[], budget?, groupSize | adults+children, pace?, maxDifficulty?, startDate?
 *  hours? (ứng viên): giờ mở cửa theo thứ, dạng OpeningHours::forItems — biết ngày đi thì không xếp mục vào lúc/ngày đóng cửa.
 *
 * Ràng buộc CỨNG ngoài bước (1): giờ mở cửa (khi biết ngày đi), ngân sách (tổng giá ĐÃ BIẾT không vượt ngân sách),
 * "không thích di chuyển xa" (giới hạn phút đi lại mỗi ngày và độ dài một chặng). Mục bị loại vì các ràng buộc này
 * có trong filterLog kèm lý do; mục điểm cao nhưng không còn chỗ nằm trong unplaced (lưu vào khu "Đã lưu" của chuyến).
 * Bối cảnh ($ctx): center [lat,lng]|null, rainyShare?, tMax?, ratingLevel?
 */
final class PlannerEngine
{
    private array $cfg;
    private array $interests;
    private PlannerRules $rules;
    private RouteService $route;

    /**
     * Mọi hằng số thời gian / buổi / tuyến lấy từ PlannerRules và RouteService (cùng nguồn với trang sửa và TripHealth).
     * $route = null: dựng từ chính $cfg (chạy được trong Unit test không có Laravel).
     */
    public function __construct(?array $cfg = null, ?array $interests = null, ?RouteService $route = null)
    {
        $this->cfg = $cfg ?? (array) config('planner');
        $this->interests = $interests ?? (array) config('interests');
        $this->rules = new PlannerRules($this->cfg);
        $this->route = $route ?? RouteService::fromRules($this->rules->route());
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
        $maxLeg = $this->maxLegKm($req);

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
        // quỹ cho HOẠT ĐỘNG = quỹ thời lượng các mục của một ngày trừ các bữa ăn sẽ chèn, nhân hệ số nhịp đi.
        // Nhờ vậy lịch "vừa phải" tự tạo ra không bị TripHealth chấm là quá dày.
        $mealsMin = (int) $this->cfg['meals_per_day'] * (int) ($this->cfg['meal_minutes'] ?? 60);
        $dayBudget = (int) round(max(120, $this->rules->itemBudgetMin() - $mealsMin) * (float) $pace['budget_factor']);
        $want = $req['days'] * $perDayMax;
        $picked = array_slice($acts, 0, $want);
        $leftover = array_slice($acts, $want);
        if ($req['budget'] !== null) {
            [$picked, $leftover] = $this->fitBudget($picked, $leftover, $req, $log);
        }

        // (4) gom theo ngày
        $center = $ctx['center'] ?? $this->centroid($picked ?: $scored);
        $dayLists = $this->assignDays($picked, $req, $center, $perDayMax, $dayBudget, $log, $leftover);

        // bổ sung ngày thiếu mục từ phần còn lại (gần trọng tâm ngày, trong giới hạn khoảng cách)
        for ($d = 0; $d < $req['days']; $d++) {
            while (count($dayLists[$d]) < $perDayMin && $leftover) {
                $cent = $this->centroid($dayLists[$d] ?: $picked) ?? $center;
                $best = null;
                foreach ($leftover as $i => $c) {
                    if ($this->closedOn($c, $this->dowOf($req, $d + 1))) {
                        continue;
                    }
                    $km = $this->km($cent, $this->pt($c, $center));
                    if ($km <= $maxLeg && ($best === null || $km < $best[1])) {
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
            $dow = $this->dowOf($req, $d + 1);
            $dayMeals[$d] = [];
            for ($m = 0; $m < (int) $this->cfg['meals_per_day']; $m++) {
                $best = null;
                foreach ($meals as $c) {
                    if (isset($usedMeal[$c['key']]) || $this->closedOn($c, $dow)) {
                        continue;
                    }
                    $km = $cent ? $this->km($cent, $this->pt($c, $center)) : 0.0;
                    if ($km > $maxLeg) {
                        continue;
                    }
                    // điểm cao trước (quán có tọa độ thật được cộng nhẹ: món chưa có vị trí thì không tính được đường đi);
                    // cùng điểm thì gần hơn
                    $real = ($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null;
                    $rank = [-($c['score'] + ($real ? (float) ($this->cfg['score']['meal_has_place'] ?? 3) : 0)), round($km, 2), $c['key']];
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
        $dropped = [];
        // độ dày mục tiêu theo nhịp, đo bằng CÙNG công thức với TripHealth (PlannerRules::occupied): ngày vượt mức thì bớt
        // hoạt động điểm thấp nhất (mục "phải thử" bớt sau cùng) đưa vào "Đã lưu", nên lịch vừa tạo không bị chấm "Bận"/"Quá tải"
        $target = (float) ($pace['target_load'] ?? 0.8);
        for ($d = 0; $d < $req['days']; $d++) {
            $acts = $dayLists[$d];
            for ($guard = 0; ; $guard++) {
                $w = [];
                $drop = [];
                $day = $this->timeline($d + 1, $acts, $dayMeals[$d], $center, $dayBudget, $w, $req, $drop);
                $load = $this->loadOf($day);
                $nAct = count(array_filter($day['items'], fn($i) => !$i['isMeal']));
                if ($load <= $target || $nAct <= 1 || $guard > 8) {
                    break;
                }
                $worst = null;
                foreach ($acts as $i => $c) {
                    $k = [$c['type'] === 'event' ? 1 : 0, $c['mustTry'] ? 1 : 0, $c['score'], $c['key']];
                    if ($worst === null || $k < $worst[1]) {
                        $worst = [$i, $k];
                    }
                }
                $c = $acts[$worst[0]];
                array_splice($acts, $worst[0], 1);
                // trả về phần còn lại: ngày sau còn chỗ thì dùng, không thì vào "Đã lưu" kèm lý do
                $leftover[] = $c + ['dropWhy' => 'Ngày ' . ($d + 1) . ' đã đủ dày với nhịp đã chọn (khoảng ' . (int) round($load * 100) . '% thời gian trong ngày)'];
            }
            // ngày còn thưa (dưới mức tối thiểu của nhịp): bù từ phần còn lại, gần trọng tâm ngày, miễn không vượt mức mục tiêu
            $minLoad = (float) ($pace['min_load'] ?? 0.45);
            $tries = 0;
            while ($load < $minLoad && $leftover && $tries++ < 6) {
                $cent = $this->centroid($acts) ?? $center;
                $best = null;
                foreach ($leftover as $i => $c) {
                    if ($this->closedOn($c, $this->dowOf($req, $d + 1)) || !empty($c['triedDay'][$d])) {
                        continue;
                    }
                    $km = $this->km($cent, $this->pt($c, $center));
                    if ($km <= $maxLeg && ($best === null || $km < $best[1])) {
                        $best = [$i, $km];
                    }
                }
                if ($best === null) {
                    break;
                }
                $c = $leftover[$best[0]];
                $w2 = [];
                $drop2 = [];
                $try = $this->timeline($d + 1, $this->orderByRoute(array_merge($acts, [$c]), $center), $dayMeals[$d], $center, $dayBudget, $w2, $req, $drop2);
                if ($this->loadOf($try) > $target || $drop2) {
                    $leftover[$best[0]]['triedDay'][$d] = true;   // không vừa ngày này, thử mục khác
                    continue;
                }
                array_splice($leftover, $best[0], 1);
                $acts = $this->orderByRoute(array_merge($acts, [$c]), $center);
                [$day, $w, $drop, $load] = [$try, $w2, $drop2, $this->loadOf($try)];
            }
            array_push($warnings, ...$w);
            array_push($dropped, ...$drop);
            foreach ($day['items'] as $it) {
                $selected[] = $it;
            }
            $days[] = $day;
        }
        if (!array_filter($days, fn($x) => $x['items'])) {
            $warnings[] = 'Không đủ dữ liệu đã duyệt để đề xuất lịch trình cho lựa chọn này.';
        } else {
            // ngày thưa đo bằng độ dày (cùng thang với Sức khỏe chuyến đi), không đếm số mục: một lớp nấu ăn 3 giờ đã đủ một buổi
            $thin = [];
            foreach ($days as $x) {
                if ($x['items'] && $this->loadOf($x) < (float) ($pace['min_load'] ?? 0.45)) {
                    $thin[] = $x['day'];
                }
            }
            $blank = array_column(array_filter($days, fn($x) => !$x['items']), 'day');
            if ($thin) {
                $warnings[] = 'Ngày ' . implode(', ', $thin) . ' còn thưa vì dữ liệu đã duyệt gần đó chưa đủ. Bạn có thể thêm mục từ khu "Đã lưu" hoặc tự tìm thêm.';
            }
            if ($blank) {
                $warnings[] = 'Ngày ' . implode(', ', $blank) . ' chưa có mục nào: dữ liệu đã duyệt không đủ cho ' . $req['days'] . ' ngày.';
            }
        }

        foreach ($dropped as $c) {
            $log[] = ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'code' => 'hours', 'reason' => $c['dropWhy']];
        }
        // mục điểm cao không còn chỗ (hoặc bị loại vì giờ mở cửa ngày đó): đưa vào khu "Đã lưu" để người dùng tự xếp
        $spare = array_merge($dropped, array_values(array_filter($leftover, fn($c) => empty($c['overBudget']))));
        $unplaced = array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'refId' => (int) ($c['refId'] ?? 0),
            'score' => $c['score'], 'reasons' => $c['reasons'] ?? [], 'durationMin' => $c['durationMin'] ?? null, 'priceMin' => $c['priceMin'] ?? null,
            'why' => $c['dropWhy'] ?? 'Điểm cao nhưng các ngày đã đủ', 'osm' => !empty($c['osm']), 'lat' => $c['lat'] ?? null, 'lng' => $c['lng'] ?? null],
            array_slice($spare, 0, 10));

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

        // người lớn + trẻ em; chỉ có groupSize thì coi tất cả là người lớn
        $adults = isset($r['adults']) && $r['adults'] !== null && $r['adults'] !== '' ? max(1, (int) $r['adults']) : max(1, (int) ($r['groupSize'] ?? 1));
        $children = isset($r['adults']) && $r['adults'] !== null && $r['adults'] !== '' ? max(0, (int) ($r['children'] ?? 0)) : 0;

        return [
            'month' => (int) ($r['month'] ?? 1),
            'days' => max(1, min($maxDays, (int) ($r['days'] ?? 1))),
            'interests' => array_values(array_intersect((array) ($r['interests'] ?? []), array_keys($this->interests))),
            'dislikes' => array_values(array_intersect((array) ($r['dislikes'] ?? []), array_keys($this->cfg['dislikes']))),
            'budget' => isset($r['budget']) && $r['budget'] !== '' && $r['budget'] !== null && (float) $r['budget'] > 0 ? (float) $r['budget'] : null,
            'groupSize' => $adults + $children,
            'adults' => $adults,
            'children' => $children,
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
            // "không thích di chuyển xa": bỏ nơi cách tâm điểm đến quá một chặng cho phép
            if (in_array('long_travel', $req['dislikes'], true) && !empty($ctx['center']) && ($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null) {
                $far = $this->km($ctx['center'], [(float) $c['lat'], (float) $c['lng']]);
                if ($far > $this->maxLegKm($req)) {
                    $reject($c, 'far', 'Cách trung tâm khoảng ' . round($far) . ' km, bạn chọn "không thích di chuyển xa"');
                    continue;
                }
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
    private function assignDays(array $picked, array $req, ?array $center, int $perDayMax, int $dayBudget, array &$log, array &$leftover = []): array
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
                if ($this->closedOn($c, $this->dowOf($req, $d + 1))) {
                    continue;
                }
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
                if ($this->closedOn($c, $this->dowOf($req, $d + 1))) {
                    continue;   // biết ngày đi: không xếp vào ngày nơi này đóng cửa
                }
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
                array_unshift($leftover, $c);   // vẫn giữ trong "Đã lưu" để người dùng tự xếp
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

    /**
     * Xếp giờ một ngày: sáng (~200 phút hoạt động), bữa trưa, chiều, bữa tối.
     * Biết thứ của ngày (có ngày đi): mục tới trước giờ mở thì chờ; không còn khoảng mở đủ dài thì thử dời xuống cuối ngày,
     * vẫn không được thì bỏ khỏi ngày (đưa vào $dropped kèm lý do). Mục không có tọa độ (món ăn chưa gắn quán) không tạo chặng đi.
     */
    private function timeline(int $dayNo, array $acts, array $meals, ?array $center, int $dayBudget, array &$warnings, array $req = [], array &$dropped = []): array
    {
        $dow = $this->dowOf($req, $dayNo);
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
        $queue = [];
        foreach ($morning as $c) {
            $queue[] = [$c, null];
        }
        if (isset($meals[0])) {
            $queue[] = [$meals[0], 'noon'];
        }
        foreach ($afternoon as $c) {
            $queue[] = [$c, null];
        }
        if (isset($meals[1])) {
            $queue[] = [$meals[1], 'evening'];
        }

        $clock = $this->rules->dayStart();   // phút kể từ 00:00, cùng thang với startMin của mục chuyến đi
        $prev = null;
        $items = [];
        $travelTotal = 0;
        $kmTotal = 0.0;
        $actMin = 0;
        $retried = [];
        $maxWait = (int) ($this->cfg['hours_max_wait_min'] ?? 90);
        while ($queue) {
            [$c, $forced] = array_shift($queue);
            $p = $this->realPt($c);
            $travelKm = 0.0;
            $travelMin = 0;
            if ($prev !== null && $p !== null) {
                $leg = $this->route->leg($prev, $p);   // RouteService: cùng quy tắc "cùng một khu" và cùng tốc độ với trang sửa
                $travelKm = $leg['km'];
                $travelMin = $leg['min'];
            }
            $start = (int) (ceil(($clock + $travelMin) / 5) * 5);   // làm tròn 5 phút như DaySchedule
            if ($forced !== null && $start < (int) $this->rules->slotStart($forced)) {
                $start = (int) $this->rules->slotStart($forced);   // bữa trưa không sớm hơn đầu buổi trưa, bữa tối không sớm hơn đầu buổi tối
            }
            $dur = $this->dur($c);
            if ($dow !== null && isset($c['hours'][(string) $dow])) {
                $fit = OpeningHours::fit($c['hours'], $dow, $start, $dur);
                if ($fit === null || $fit - $start > $maxWait) {
                    if (empty($retried[$c['key']]) && $queue) {
                        // thử lại sau: chèn trước bữa tối (nếu còn), để mục khác đi trước
                        $retried[$c['key']] = true;
                        $last = end($queue);
                        if ($last !== false && $last[1] === 'evening') {
                            array_splice($queue, count($queue) - 1, 0, [[$c, $forced]]);
                        } else {
                            $queue[] = [$c, $forced];
                        }
                        continue;
                    }
                    if ($fit === null) {
                        $dropped[] = $c + ['dropWhy' => 'Ngày ' . $dayNo . ' (' . self::DOW[$dow] . ') không còn khoảng mở cửa đủ ' . $dur . ' phút'
                            . (($h = OpeningHours::describe($c['hours'], $dow)) ? ' (giờ mở: ' . $h . ')' : '')];
                        continue;
                    }
                }
                $start = max($start, $fit);
            }
            $slot = $forced ?? $this->rules->slotOf($start);
            if ($forced === null && $slot === 'noon') {
                $slot = 'afternoon';   // khung trưa dành cho bữa ăn
            }
            // array_merge (không dùng +): ứng viên có thể mang sẵn khóa durationMin = null (lễ hội) và phải được ghi đè
            $items[] = array_merge($c, ['slot' => $slot, 'startMin' => $start, 'durationMin' => $dur, 'travelKm' => round($travelKm, 1), 'travelMin' => $travelMin,
                'coordsApprox' => $p === null]);
            $clock = $start + $dur;
            if (!$c['isMeal']) {
                $actMin += $dur;
            }
            $travelTotal += $travelMin;
            $kmTotal += $travelKm;
            if ($p !== null) {
                $prev = $p;   // mục không có vị trí không làm đứt đoạn đi giữa hai điểm thật
            }
        }

        $out = ['day' => $dayNo, 'items' => $items, 'travelMin' => $travelTotal];
        if ($this->loadOf($out) >= $this->rules->loadWarn()) {
            $warnings[] = 'Ngày ' . $dayNo . ' khá dày: khoảng ' . round(($actMin + $travelTotal) / 60, 1) . ' giờ hoạt động và di chuyển.';
        }
        if ($travelTotal > $this->maxTravelMin($req)) {
            $warnings[] = 'Ngày ' . $dayNo . ' di chuyển khoảng ' . round($travelTotal / 60, 1) . ' giờ (ước tính), nên bớt điểm hoặc chia sang ngày khác.';
        }

        return ['day' => $dayNo, 'dow' => $dow, 'items' => $items, 'activityMin' => $actMin, 'travelMin' => $travelTotal, 'km' => (int) round($kmTotal)];
    }

    /** Độ dày của một ngày theo PlannerRules::occupied (hoạt động gồm cả bữa ăn + di chuyển + đệm) / sức chứa. */
    private function loadOf(array $day): float
    {
        $all = array_sum(array_map(fn($i) => (int) $i['durationMin'], $day['items']));
        $hasMeal = (bool) array_filter($day['items'], fn($i) => !empty($i['isMeal']) || $this->rules->kindOf($i) === 'meal');
        $o = $this->rules->occupied($all, (int) $day['travelMin'], $hasMeal, (bool) $day['items']);

        return $o['occupied'] / max(1, $this->rules->availableMin());
    }

    // ------------------------------------------------------------------ ràng buộc cứng: ngân sách, giờ mở cửa, đi xa

    private const DOW = [1 => 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'];

    /** Giá ĐÃ BIẾT của một mục cho cả nhóm (null = chưa có giá). Trẻ em: chưa có giá riêng thì tính như người lớn (ghi rõ trong thông báo ngân sách). */
    public function costOf(array $c, array $req): ?float
    {
        $min = $c['priceMin'] ?? null;
        if ($min === null) {
            return null;
        }
        $child = $c['priceChild'] ?? null;

        return (float) $min * $req['adults'] + (float) ($child ?? $min) * $req['children'];
    }

    /**
     * Ngân sách là ràng buộc CỨNG trên phần đã biết giá: tổng giá đã biết của các mục được chọn không được vượt ngân sách.
     * Bỏ dần mục đắt nhất (mục "phải thử" bỏ sau cùng), thay bằng mục kế tiếp còn vừa ngân sách; mục bị bỏ ghi vào nhật ký.
     *
     * @return array{0:list<array>,1:list<array>} [picked, leftover]
     */
    private function fitBudget(array $picked, array $leftover, array $req, array &$log): array
    {
        $limit = (float) $req['budget'];
        $total = fn(array $l) => array_sum(array_map(fn($c) => (float) ($this->costOf($c, $req) ?? 0), $l));
        while ($total($picked) > $limit) {
            // riêng các mục "phải thử" đã vượt ngân sách thì phải bỏ bớt chúng; nếu không, chỉ bỏ mục thường
            $must = array_sum(array_map(fn($c) => $c['mustTry'] ? (float) ($this->costOf($c, $req) ?? 0) : 0.0, $picked));
            $dropMust = $must > $limit;
            $worst = null;
            foreach ($picked as $i => $c) {
                $cost = $this->costOf($c, $req);
                if ($cost === null || $cost <= 0 || $c['mustTry'] !== $dropMust) {
                    continue;
                }
                $k = [-$cost, $c['score'], $c['key']];   // đắt nhất trước, cùng giá thì điểm thấp hơn trước
                if ($worst === null || $k < $worst[1]) {
                    $worst = [$i, $k];
                }
            }
            if ($worst === null) {
                break;
            }
            $c = $picked[$worst[0]];
            array_splice($picked, $worst[0], 1);
            $log[] = ['key' => $c['key'], 'name' => $c['name'], 'type' => $c['type'], 'code' => 'budget',
                'reason' => 'Giá ' . number_format((float) $this->costOf($c, $req), 0, ',', '.') . 'đ cho cả nhóm làm tổng phần đã biết giá vượt ngân sách ' . number_format($limit, 0, ',', '.') . 'đ'];
            // thay bằng mục kế tiếp (điểm cao nhất) còn vừa ngân sách
            $room = $limit - $total($picked);
            foreach ($leftover as $j => $x) {
                $xc = $this->costOf($x, $req);
                if ($xc === null || $xc <= $room) {
                    $picked[] = $x;
                    array_splice($leftover, $j, 1);
                    break;
                }
            }
        }
        // phần còn lại vượt ngân sách thì không dùng để bù ngày thưa
        $room = $limit - $total($picked);
        foreach ($leftover as &$x) {
            $xc = $this->costOf($x, $req);
            if ($xc !== null && $xc > $room) {
                $x['overBudget'] = true;
            }
        }
        unset($x);
        $leftover = array_values(array_filter($leftover, fn($x) => empty($x['overBudget'])));

        return [$picked, $leftover];
    }

    /** Thứ ISO của ngày thứ $day khi biết ngày đi; null khi chưa có ngày đi. */
    private function dowOf(array $req, int $day): ?int
    {
        return OpeningHours::dowOf($req['startDate'] ?? null, $day);
    }

    /** Biết chắc nơi này đóng cửa cả ngày đó. */
    private function closedOn(array $c, ?int $dow): bool
    {
        return $dow !== null && isset($c['hours']) && is_array($c['hours']) && array_key_exists((string) $dow, $c['hours']) && $c['hours'][(string) $dow] === [];
    }

    /** "Không thích di chuyển xa": giới hạn phút đi lại mỗi ngày chặt hơn. */
    private function maxTravelMin(array $req): int
    {
        $d = (int) $this->cfg['max_travel_min_per_day'];
        if (in_array('long_travel', $req['dislikes'] ?? [], true)) {
            $d = min($d, (int) ($this->cfg['dislikes']['long_travel']['max_travel_min_per_day'] ?? 90));
        }

        return $d;
    }

    private function maxLegKm(array $req): float
    {
        $d = (float) $this->cfg['max_leg_km'];
        if (in_array('long_travel', $req['dislikes'] ?? [], true)) {
            $d = min($d, (float) ($this->cfg['dislikes']['long_travel']['max_leg_km'] ?? 25));
        }

        return $d;
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
            $knownMin += (float) $this->costOf(['priceMin' => $lo] + $it, $req);
            $knownMax += (float) $this->costOf(['priceMin' => max($lo, $hi), 'priceChild' => null], $req);
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
        if (($req['children'] ?? 0) > 0 && $priced > 0) {
            $msg .= ' Trải nghiệm chưa có giá trẻ em riêng nên trẻ em được tính như người lớn.';
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

    /** Tọa độ thật của mục (không thay bằng tâm điểm đến). */
    private function realPt(array $c): ?array
    {
        return ($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null ? [(float) $c['lat'], (float) $c['lng']] : null;
    }

    /** @return array{0:float,1:float}|null */
    private function pt(array $c, ?array $center): ?array
    {
        if (($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null) {
            return [(float) $c['lat'], (float) $c['lng']];
        }

        return $center;
    }

    /** Khoảng cách chim bay, CHỈ để gom điểm theo cụm khi chia ngày (không phải thời gian di chuyển: cái đó luôn qua RouteService). */
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
