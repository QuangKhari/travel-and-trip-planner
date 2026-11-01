<?php

namespace Tests\Unit;

use App\Services\Planner\PlannerEngine;
use PHPUnit\Framework\TestCase;

class PlannerEngineTest extends TestCase
{
    private const CENTER = [16.05, 108.20];

    private function engine(array $over = []): PlannerEngine
    {
        $cfg = array_replace_recursive(require __DIR__ . '/../../config/planner.php', $over);

        return new PlannerEngine($cfg, require __DIR__ . '/../../config/interests.php');
    }

    private function place(int $id, string $name, string $group, float $dLat = 0.0, float $dLng = 0.0, array $x = []): array
    {
        return $x + [
            'key' => "place:$id",
            'type' => 'place',
            'refId' => $id,
            'name' => $name,
            'group' => $group,
            'kind' => $x['kind'] ?? 'attraction',
            'lat' => self::CENTER[0] + $dLat,
            'lng' => self::CENTER[1] + $dLng,
            'rank' => 5,
            'mustTry' => false
        ];
    }

    private function exp(int $id, string $name, array $x = []): array
    {
        return $x + [
            'key' => "experience:$id",
            'type' => 'experience',
            'refId' => $id,
            'name' => $name,
            'category' => 'Thiên nhiên & thuyền',
            'lat' => self::CENTER[0],
            'lng' => self::CENTER[1],
            'mustTry' => false
        ];
    }

    private function food(int $id, string $name, array $x = []): array
    {
        return $x + ['key' => "food:$id", 'type' => 'food', 'refId' => $id, 'name' => $name, 'lat' => null, 'lng' => null, 'mustTry' => false];
    }

    private function req(array $x = []): array
    {
        return $x + ['month' => 6, 'days' => 2, 'interests' => [], 'dislikes' => [], 'groupSize' => 2];
    }

    private function dataset(): array
    {
        $c = [];
        for ($i = 1; $i <= 8; $i++) {
            $c[] = $this->place($i, "Điểm tham quan $i", $i % 2 ? 'nature' : 'culture', $i * 0.01, $i * 0.01);
        }
        $c[] = $this->place(20, 'Quán cơm A', 'eat', 0.0, 0.01, ['kind' => 'restaurant']);
        $c[] = $this->place(21, 'Quán cơm B', 'eat', 0.02, 0.02, ['kind' => 'restaurant']);
        $c[] = $this->place(22, 'Quán cà phê C', 'eat', 0.03, 0.01, ['kind' => 'cafe']);
        $c[] = $this->place(23, 'Quán ăn D', 'eat', 0.04, 0.03, ['kind' => 'restaurant']);

        return $c;
    }

    public function test_is_deterministic(): void
    {
        $a = $this->engine()->plan($this->dataset(), $this->req(), ['center' => self::CENTER]);
        $b = $this->engine()->plan(array_reverse($this->dataset()), $this->req(), ['center' => self::CENTER]);
        $keys = fn($p) => array_map(fn($d) => array_column($d['items'], 'key'), $p['days']);
        $this->assertSame($keys($a), $keys($b), 'thứ tự ứng viên đầu vào không được đổi kết quả');
    }

    public function test_days_have_activities_and_two_meals_with_slots(): void
    {
        $p = $this->engine()->plan($this->dataset(), $this->req(), ['center' => self::CENTER]);
        $this->assertCount(2, $p['days']);
        foreach ($p['days'] as $d) {
            $meals = array_values(array_filter($d['items'], fn($i) => $i['isMeal']));
            $this->assertCount(2, $meals, 'mỗi ngày đủ bữa trưa và bữa tối');
            $this->assertSame('noon', $meals[0]['slot']);
            $this->assertSame('evening', $meals[1]['slot']);
            // nhịp normal: số hoạt động do độ dày quyết định (≤ 80% ngày, cùng thang với Sức khỏe chuyến đi), không ép đủ 3 mục dài
            $this->assertGreaterThanOrEqual(2, count($d['items']) - 2, 'nhịp normal: ít nhất 2 hoạt động');
            $rules = new \App\Services\Planner\PlannerRules(require __DIR__ . '/../../config/planner.php');
            $all = array_sum(array_column($d['items'], 'durationMin'));
            $this->assertLessThanOrEqual(0.8, $rules->occupied($all, $d['travelMin'], true, true)['occupied'] / $rules->availableMin(), 'ngày vừa tạo không bị chấm Bận');
            foreach ($d['items'] as $i) {
                $this->assertContains($i['slot'], ['morning', 'noon', 'afternoon', 'evening']);
                $this->assertNotEmpty($i['key']);
            }
        }
    }

    public function test_no_item_is_used_twice(): void
    {
        $p = $this->engine()->plan($this->dataset(), $this->req(['days' => 3]), ['center' => self::CENTER]);
        $keys = [];
        foreach ($p['days'] as $d) {
            foreach ($d['items'] as $i) {
                $keys[] = $i['key'];
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_hard_filters_log_a_reason(): void
    {
        $c = $this->dataset();
        $c[] = $this->exp(1, 'Leo núi mạo hiểm', ['difficulty' => 'hard', 'indoor' => false]);
        $c[] = $this->exp(2, 'Chèo thuyền mùa đông', ['monthFrom' => 11, 'monthTo' => 12]);
        $c[] = $this->place(30, 'Đỉnh Bà Nà', 'nature', 0.05, 0.05, ['kind' => 'peak']);
        $c[] = $this->place(31, 'Không tọa độ', 'see', 0, 0, ['lat' => null, 'lng' => null]);
        $c[] = $this->place(32, 'Điểm tham quan 1', 'see', 0.5, 0.5);   // trùng tên với #1

        $p = $this->engine()->plan($c, $this->req(['dislikes' => ['trekking'], 'maxDifficulty' => 'medium']), ['center' => self::CENTER]);
        $by = [];
        foreach ($p['filterLog'] as $l) {
            $by[$l['key']] = $l['code'];
        }
        $this->assertSame('dislike', $by['experience:1']);
        $this->assertSame('season', $by['experience:2']);
        $this->assertSame('dislike', $by['place:30']);
        $this->assertSame('no_coords', $by['place:31']);
        $this->assertSame('duplicate', $by['place:32']);
        foreach ($p['filterLog'] as $l) {
            $this->assertNotSame('', $l['reason']);
        }
    }

    public function test_difficulty_limit_excludes_harder_experiences(): void
    {
        $c = [$this->exp(1, 'Chinh phục đỉnh', ['difficulty' => 'hard']), $this->exp(2, 'Đi bộ nhẹ', ['difficulty' => 'easy'])];
        $p = $this->engine()->plan(array_merge($this->dataset(), $c), $this->req(['maxDifficulty' => 'easy']), ['center' => self::CENTER]);
        $codes = array_column($p['filterLog'], 'code', 'key');
        $this->assertSame('difficulty', $codes['experience:1']);
        $this->assertArrayNotHasKey('experience:2', $codes);
    }

    public function test_season_unknown_is_not_excluded(): void
    {
        $c = [$this->exp(1, 'Quanh năm'), $this->exp(2, 'Chỉ khai báo một đầu', ['monthFrom' => 11])];
        $p = $this->engine()->plan($c, $this->req(), ['center' => self::CENTER]);
        $this->assertSame([], array_column($p['filterLog'], 'code', 'key'), 'mùa chưa khai báo đủ hai đầu thì không loại');
    }

    public function test_must_try_and_interest_raise_score_with_reasons(): void
    {
        $c = [
            $this->place(1, 'Thường', 'culture', 0.01, 0.01, ['rank' => 0]),
            $this->place(2, 'Bãi biển nổi tiếng', 'nature', 0.02, 0.02, ['rank' => 0, 'mustTry' => true]),
        ];
        $p = $this->engine()->plan($c, $this->req(['days' => 1, 'interests' => ['nature']]), ['center' => self::CENTER]);
        $items = $p['days'][0]['items'];
        $top = null;
        foreach ($items as $i) {
            if ($i['key'] === 'place:2') {
                $top = $i;
            }
        }
        $this->assertNotNull($top);
        $this->assertGreaterThan(40, $top['score']);
        $this->assertContains('Hợp sở thích: Thiên nhiên', $top['reasons']);
        $this->assertContains('Biên tập viên đánh dấu "phải thử/phải ghé"', $top['reasons']);
    }

    public function test_rainy_month_prefers_indoor(): void
    {
        $in = $this->exp(1, 'Bảo tàng nghề', ['indoor' => true, 'category' => 'Lớp học']);
        $out = $this->exp(2, 'Dạo bờ sông', ['indoor' => false, 'category' => 'Ngắm cảnh']);
        $p = $this->engine()->plan([$in, $out], $this->req(['days' => 1]), ['center' => self::CENTER, 'rainyShare' => 0.6]);
        $score = [];
        foreach ($p['days'][0]['items'] as $i) {
            $score[$i['key']] = $i['score'];
        }
        $this->assertGreaterThan($score['experience:2'], $score['experience:1']);
    }

    public function test_events_outside_trip_window_are_filtered(): void
    {
        $ev = [
            'key' => 'event:1',
            'type' => 'event',
            'refId' => 1,
            'name' => 'Lễ hội X',
            'lat' => null,
            'lng' => null,
            'eventStart' => '2027-06-20',
            'eventEnd' => '2027-06-22'
        ];
        $p = $this->engine()->plan(array_merge($this->dataset(), [$ev]), $this->req(['startDate' => '2027-06-01', 'days' => 3]), ['center' => self::CENTER]);
        $this->assertSame('event_outside_trip', array_column($p['filterLog'], 'code', 'key')['event:1']);
    }

    public function test_event_is_pinned_to_its_day(): void
    {
        $ev = [
            'key' => 'event:1',
            'type' => 'event',
            'refId' => 1,
            'name' => 'Lễ hội X',
            'lat' => null,
            'lng' => null,
            'eventStart' => '2027-06-02',
            'eventEnd' => '2027-06-02'
        ];
        $p = $this->engine()->plan(array_merge($this->dataset(), [$ev]), $this->req(['startDate' => '2027-06-01', 'days' => 3]), ['center' => self::CENTER]);
        $day = null;
        foreach ($p['days'] as $d) {
            if (in_array('event:1', array_column($d['items'], 'key'), true)) {
                $day = $d['day'];
            }
        }
        $this->assertSame(2, $day, 'lễ hội ngày 02/06 phải nằm ở ngày 2');
    }

    public function test_geographic_grouping_keeps_far_clusters_on_different_days(): void
    {
        $c = [];
        foreach ([1, 2, 3] as $i) {
            $c[] = $this->place($i, "Gần $i", 'culture', $i * 0.005, 0);          // cụm A quanh tâm
            $c[] = $this->place(10 + $i, "Xa $i", 'culture', 0.9 + $i * 0.005, 0.9);   // cụm B cách ~130 km
        }
        $p = $this->engine()->plan($c, $this->req(['pace' => 'relaxed']), ['center' => self::CENTER]);
        $dayOf = [];
        foreach ($p['days'] as $d) {
            foreach ($d['items'] as $i) {
                $dayOf[$i['key']] = $d['day'];
            }
        }
        $this->assertSame($dayOf['place:1'], $dayOf['place:2']);
        $this->assertSame($dayOf['place:11'], $dayOf['place:12']);
        $this->assertNotSame($dayOf['place:1'], $dayOf['place:11']);
    }

    public function test_budget_never_claims_fit_when_prices_unknown(): void
    {
        $c = [$this->exp(1, 'Có giá', ['priceMin' => 200000, 'priceMax' => 300000]), $this->exp(2, 'Chưa có giá')];
        $p = $this->engine()->plan($c, $this->req(['days' => 1, 'budget' => 10000000, 'groupSize' => 2]), ['center' => self::CENTER]);
        $b = $p['budget'];
        $this->assertSame('PARTIAL', $b['status']);
        $this->assertSame(400000.0, $b['knownMin']);
        $this->assertSame(600000.0, $b['knownMax']);
        $this->assertSame(1, $b['unknownCount']);
        $this->assertFalse($b['overKnown']);
        $this->assertStringNotContainsString('vừa', $b['message']);
        $this->assertStringContainsString('CHƯA phải tổng chi phí', $b['message']);
    }

    public function test_budget_status_unknown_and_full(): void
    {
        $unknown = $this->engine()->plan([$this->exp(1, 'A')], $this->req(['days' => 1]), ['center' => self::CENTER])['budget'];
        $this->assertSame('UNKNOWN', $unknown['status']);
        $full = $this->engine()->plan([$this->exp(1, 'A', ['priceMin' => 100000])], $this->req(['days' => 1, 'budget' => 300000, 'groupSize' => 2]), ['center' => self::CENTER])['budget'];
        $this->assertSame('FULL', $full['status']);
        $this->assertFalse($full['overKnown']);
    }

    /** Ngân sách là ràng buộc cứng: mục làm tổng giá đã biết vượt ngân sách bị loại (có lý do), mục rẻ hơn được thay vào. */
    public function test_budget_is_a_hard_constraint_on_known_prices(): void
    {
        $c = [$this->exp(1, 'Đắt', ['priceMin' => 900000, 'mustTry' => true]), $this->exp(2, 'Vừa', ['priceMin' => 200000]), $this->exp(3, 'Miễn phí', ['priceMin' => 0])];
        $p = $this->engine()->plan($c, $this->req(['days' => 1, 'budget' => 500000, 'groupSize' => 2, 'pace' => 'normal']), ['center' => self::CENTER]);
        $names = array_column($p['days'][0]['items'], 'name');
        $this->assertNotContains('Đắt', $names);
        $this->assertContains('Vừa', $names);
        $this->assertFalse($p['budget']['overKnown']);
        $this->assertLessThanOrEqual(500000, $p['budget']['knownMin']);
        $this->assertNotNull(collect($p['filterLog'])->first(fn($x) => $x['code'] === 'budget' && $x['name'] === 'Đắt'));
    }

    public function test_children_are_counted_in_known_prices(): void
    {
        $p = $this->engine()->plan([$this->exp(1, 'A', ['priceMin' => 100000])], $this->req(['days' => 1, 'adults' => 2, 'children' => 1]), ['center' => self::CENTER]);
        $this->assertSame(3, $p['budget']['groupSize']);
        $this->assertSame(300000.0, $p['budget']['knownMin']);
        $this->assertStringContainsString('trẻ em', $p['budget']['message']);
    }

    /** Biết ngày đi: nơi đóng cửa ngày đó không được xếp vào ngày đó; nơi mở muộn được chờ tới giờ mở. */
    public function test_opening_hours_are_respected_when_start_date_is_known(): void
    {
        // 2026-12-07 là Thứ Hai
        $closedMon = ['1' => [], '2' => [[480, 1020]], '3' => [[480, 1020]], '4' => [[480, 1020]], '5' => [[480, 1020]], '6' => [[480, 1020]], '7' => [[480, 1020]]];
        $late = array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], [[840, 1020]]);
        $c = [$this->exp(1, 'Bảo tàng nghỉ thứ Hai', ['hours' => $closedMon, 'mustTry' => true]), $this->exp(2, 'Chợ chiều', ['hours' => $late]), $this->exp(3, 'Đi bộ')];
        $p = $this->engine()->plan($c, $this->req(['days' => 1, 'startDate' => '2026-12-07']), ['center' => self::CENTER]);
        $items = collect($p['days'][0]['items']);
        $this->assertNull($items->firstWhere('name', 'Bảo tàng nghỉ thứ Hai'));
        $this->assertGreaterThanOrEqual(840, $items->firstWhere('name', 'Chợ chiều')['startMin']);
        $this->assertSame(1, $p['days'][0]['dow']);
        // không mất: nằm trong unplaced để đưa vào "Đã lưu"
        $this->assertContains('Bảo tàng nghỉ thứ Hai', array_column($p['unplaced'], 'name'));
    }

    public function test_food_without_coordinates_creates_no_fake_travel_leg(): void
    {
        $c = [$this->exp(1, 'A', ['lat' => 21.03, 'lng' => 105.85]), $this->exp(2, 'B', ['lat' => 21.031, 'lng' => 105.851]),
            ['key' => 'food:9', 'type' => 'food', 'refId' => 9, 'name' => 'Phở', 'category' => 'mon', 'lat' => null, 'lng' => null, 'mustTry' => true]];
        $p = $this->engine()->plan($c, $this->req(['days' => 1]), ['center' => [21.10, 105.95]]);
        $pho = collect($p['days'][0]['items'])->firstWhere('name', 'Phở');
        $this->assertNotNull($pho);
        $this->assertSame(0, $pho['travelMin']);
        $this->assertTrue($pho['coordsApprox']);
        $this->assertLessThan(10, $p['days'][0]['travelMin'], 'không có chặng giả về tâm điểm đến');
    }

    public function test_dislike_long_travel_limits_distance(): void
    {
        $c = [$this->exp(1, 'Gần', ['lat' => self::CENTER[0] + 0.01, 'lng' => self::CENTER[1]]), $this->exp(2, 'Xa 50km', ['lat' => self::CENTER[0] + 0.45, 'lng' => self::CENTER[1]])];
        $p = $this->engine()->plan($c, $this->req(['days' => 1, 'dislikes' => ['long_travel']]), ['center' => self::CENTER]);
        $this->assertNotContains('Xa 50km', array_column($p['days'][0]['items'], 'name'));
        $this->assertSame('far', collect($p['filterLog'])->firstWhere('name', 'Xa 50km')['code']);
    }

    public function test_warns_when_data_is_too_thin(): void
    {
        $p = $this->engine()->plan([$this->place(1, 'Chỉ một điểm', 'see')], $this->req(['days' => 3]), ['center' => self::CENTER]);
        $this->assertNotEmpty($p['warnings']);
        $empty = $this->engine()->plan([], $this->req(), ['center' => self::CENTER]);
        $this->assertContains('Không đủ dữ liệu đã duyệt để đề xuất lịch trình cho lựa chọn này.', $empty['warnings']);
        $this->assertSame([], array_merge(...array_map(fn($d) => $d['items'], $empty['days'])));
    }

    public function test_request_is_clamped(): void
    {
        $r = $this->engine()->normalizeRequest(['days' => 99, 'interests' => ['nature', 'bogus'], 'dislikes' => ['x', 'crowds'], 'pace' => 'zzz']);
        $this->assertSame(7, $r['days']);
        $this->assertSame(['nature'], $r['interests']);
        $this->assertSame(['crowds'], $r['dislikes']);
        $this->assertSame('normal', $r['pace']);
    }
}
