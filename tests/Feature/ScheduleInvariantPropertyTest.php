<?php

namespace Tests\Feature;

use App\Services\Planner\Health\DaySchedule;
use App\Services\Content\OpeningHours;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\RouteService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Kiểm thử theo thuộc tính (property-based) cho lõi xếp giờ DaySchedule — mục "Kiểm thử như một sản phẩm".
 * Sinh ngẫu nhiên (có seed, chạy lại ra đúng bộ đó) hàng trăm ngày lịch: số mục, vị trí (có / không), thời lượng, giờ cố định, buổi,
 * giờ mở cửa (có nghỉ trưa, đóng cả ngày, qua nửa đêm), phương tiện từng chặng. Với MỌI ngày sinh ra, các bất biến phải đúng:
 *   1. Mục không cố định giờ KHÔNG BAO GIỜ chồng lên mục trước + chặng đi (bắt đầu >= kết thúc trước + phút di chuyển).
 *   2. Chồng giờ chỉ có thể do giờ cố định của người dùng, và khi đó LUÔN bị gắn cờ xung đột (không chồng âm thầm).
 *   3. Mục không cố định giờ không bao giờ được xếp TRƯỚC giờ mở cửa (hệ thống chờ tới giờ mở, không xếp sai).
 *   4. Mục không cố định giờ còn khung mở cửa vừa đủ thì không bị xếp vào lúc đóng cửa / quá giờ đóng.
 *   5. Tổng thời gian khớp: activity = tổng thời lượng; travel = tổng phút các chặng (+ chặng chỗ ở); occupied = hoạt động + đi + ăn + đệm;
 *      giờ kết thúc ngày = kết thúc mục cuối; mục cố định giữ đúng giờ người dùng đặt.
 *   6. Trình duyệt (planner-schedule.js) và máy chủ ra CÙNG kết quả trên mọi ngày ngẫu nhiên (hợp đồng một nghiệp vụ một kết quả).
 * Thất bại thì thông báo kèm seed + ngày để tái hiện.
 */
class ScheduleInvariantPropertyTest extends TestCase
{
    private const RUNS = 250;

    /** @return array<int,list<array>> */
    private function generate(int $seed, int $runs): array
    {
        mt_srand($seed);
        $hoursPool = [
            null,
            '08:00-17:00',
            'Mo-Su 07:30-11:30,13:30-17:00',      // nghỉ trưa
            'Tu-Su 08:30-17:30; Mo off',          // đóng thứ Hai
            'Mo-Su 18:00-02:00',                  // qua nửa đêm
            'Sa,Su 09:00-12:00',                  // chỉ cuối tuần
            '24/7',
        ];
        $types = ['place', 'food', 'experience', 'note', 'transport'];
        $kinds = [null, 'museum', 'restaurant', 'hotel', 'viewpoint', 'cafe'];
        $slots = [null, null, null, 'morning', 'noon', 'afternoon', 'evening'];
        $modes = [null, null, null, 'walking', 'motorbike', 'bus', 'driving'];
        $days = [];
        for ($d = 1; $d <= $runs; $d++) {
            $n = mt_rand(0, 8);
            $items = [];
            for ($i = 0; $i < $n; $i++) {
                $t = $types[array_rand($types)];
                $it = ['key' => $t . ':' . ($d * 100 + $i), 'type' => $t, 'name' => 'M' . $i];
                if (mt_rand(1, 100) <= 85) {
                    $it['lat'] = round(21.00 + mt_rand(0, 900) / 10000, 4);
                    $it['lng'] = round(105.78 + mt_rand(0, 1200) / 10000, 4);
                }
                if (mt_rand(1, 100) <= 60) {
                    $it['defDur'] = [30, 45, 60, 90, 120, 180][mt_rand(0, 5)];
                }
                if (mt_rand(1, 100) <= 25) {
                    $it['startMin'] = 5 * mt_rand(84, 252);   // 07:00–21:00
                    if (mt_rand(0, 1)) {
                        $it['durationMin'] = 5 * mt_rand(3, 36);
                    }
                }
                if (($k = $kinds[array_rand($kinds)]) !== null && $t === 'place') {
                    $it['kind'] = $k;
                }
                if (($s = $slots[array_rand($slots)]) !== null) {
                    $it['slot'] = $s;
                }
                if (($m = $modes[array_rand($modes)]) !== null) {
                    $it['legMode'] = $m;
                }
                $h = $hoursPool[array_rand($hoursPool)];
                if ($h !== null) {
                    $it['hours'] = OpeningHours::parseOsm($h);
                }
                // như payload thật (TripPlanner::itemForClient): máy chủ luôn gửi kèm thời lượng mặc định theo loại cho trình duyệt
                $it['defDur'] ??= \App\Services\Planner\TripPlanner::defaultDuration($it);
                $items[] = $it;
            }
            $days[$d] = $items;
        }

        return $days;
    }

    public function test_random_days_keep_every_scheduling_invariant_and_client_matches_server(): void
    {
        $seed = (int) (getenv('SCHEDULE_PROP_SEED') ?: 20261118);
        $rules = PlannerRules::fromConfig();
        $route = RouteService::fromRules($rules->route() + []);
        $days = $this->generate($seed, self::RUNS);
        $dows = [];
        $server = [];
        foreach ($days as $d => $items) {
            $dows[$d] = $d % 8 === 0 ? null : ($d % 7) + 1;   // có ngày chưa biết thứ (chuyến chưa có ngày đi)
            $s = new DaySchedule($d, $items, $route, $rules, $dows[$d]);
            $server[$d] = $s->contract();
            $where = "seed $seed, ngày $d";

            // 5. tổng thời gian khớp
            $this->assertCount(count($items), $s->rows, "$where: mỗi mục một dòng");
            $this->assertSame(array_sum(array_column($s->rows, 'dur')), $s->activityMin, "$where: activity = tổng thời lượng");
            $this->assertSame(array_sum(array_column($s->rows, 'travelMin')) + ($s->startLeg['min'] ?? 0) + ($s->endLeg['min'] ?? 0), $s->travelMin, "$where: travel = tổng chặng");
            $this->assertSame($s->activityMin + $s->travelMin + $s->mealMin + $s->bufferMin, $s->occupiedMin(), "$where: occupied");
            $this->assertSame($s->rows ? end($s->rows)['end'] : $s->start, $s->end, "$where: giờ kết thúc ngày");

            foreach ($s->rows as $i => $r) {
                $it = $r['item'];
                $this->assertSame($r['start'] + $r['dur'], $r['end'], "$where mục $i: end = start + dur");
                $this->assertGreaterThan(0, $r['dur'], "$where mục $i: thời lượng dương");
                if ($r['fixed']) {
                    $this->assertSame((int) $it['startMin'], $r['start'], "$where mục $i: giữ đúng giờ cố định");
                }
                if ($i === 0) {
                    continue;
                }
                $p = $s->rows[$i - 1];
                if (!$r['fixed']) {
                    // 1. không chồng, kể cả phút đi (làm tròn 5 phút chỉ có thể làm muộn hơn)
                    $this->assertGreaterThanOrEqual($p['end'] + $p['travelMin'], $r['start'], "$where mục $i: mục tự xếp chồng lên mục trước");
                    $this->assertNull($r['conflict'], "$where mục $i: mục tự xếp không có xung đột giờ");
                    // 3. không bao giờ tới trước giờ mở cửa
                    $this->assertNotSame('early', $r['hours'], "$where mục $i: mục tự xếp tới trước giờ mở cửa");
                    // 4. còn khung mở vừa đủ từ lúc sẵn sàng thì không rơi vào đóng cửa / quá giờ
                    $ready = (int) (ceil(($p['end'] + $p['travelMin']) / 5) * 5);
                    if (!empty($it['hours']) && $dows[$d] !== null && empty($it['slot']) && OpeningHours::fit($it['hours'], $dows[$d], $ready, $r['dur']) !== null) {
                        $this->assertContains($r['hours'], [null, 'open', 'ok'], "$where mục $i: còn khung mở mà vẫn xếp lúc đóng ({$r['hours']})");
                    }
                } elseif ($r['start'] < $p['end'] - 5) {
                    // 2. chồng giờ do giờ cố định: luôn có cờ
                    $this->assertNotNull($r['conflict'], "$where mục $i: chồng giờ không bị gắn cờ");
                }
            }
        }

        // 6. hợp đồng trình duyệt = máy chủ trên đúng các ngày ngẫu nhiên đó
        $bin = trim(strtok((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'), "\r\n") ?: '');
        if ($bin === '') {
            $this->markTestIncomplete('Không có Node.js: đã kiểm bất biến máy chủ, bỏ qua so trình duyệt.');
        }
        $p = new Process([$bin, base_path('tests/js/schedule-contract.cjs')]);
        $p->setInput(json_encode(['rules' => $rules->client(), 'days' => $days, 'dows' => (object) array_filter($dows, fn($v) => $v !== null)], JSON_UNESCAPED_UNICODE));
        $p->setTimeout(120);
        $p->mustRun();
        $js = json_decode($p->getOutput(), true);
        foreach ($server as $d => $sv) {
            $cl = $js[(string) $d];
            $this->assertSame(count($sv['rows']), count($cl['rows']), "seed $seed, ngày $d: số dòng (trình duyệt / máy chủ)");
            foreach ($sv['rows'] as $i => $row) {
                foreach (['start', 'end', 'dur', 'fixed', 'travelMin', 'conflict', 'hours'] as $k) {
                    $this->assertSame($row[$k], $cl['rows'][$i][$k], "seed $seed, ngày $d, mục $i: $k khác nhau giữa trình duyệt và máy chủ");
                }
            }
            $this->assertSame($sv['occupiedMin'], $cl['occupiedMin'], "seed $seed, ngày $d: occupiedMin");
        }
    }
}
