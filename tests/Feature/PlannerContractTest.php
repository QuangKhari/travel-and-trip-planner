<?php

namespace Tests\Feature;

use App\Services\Planner\Health\DaySchedule;
use App\Services\Planner\PlannerEngine;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Planner Contract Tests: MỘT nghiệp vụ, MỘT kết quả.
 *  - cùng danh sách mục, lõi xếp giờ ở trình duyệt (planner-schedule.js + planner-geo.js, chạy bằng Node) và DaySchedule ở máy chủ
 *    ra cùng giờ bắt đầu/kết thúc, cùng phút đi lại, cùng xung đột, cùng buổi, cùng độ dày;
 *  - trang sửa, sức khỏe chuyến đi, tối ưu tuyến và tự tạo lịch dùng cùng tham số tuyến/thời gian (PlannerRules, RouteService).
 */
class PlannerContractTest extends TestCase
{
    use RefreshDatabase;

    /** Bộ lịch mẫu bao các tình huống: nối tiếp, giờ cố định, trùng giờ, không kịp đi, chờ (thời gian trống), buổi, cùng khu, chưa có vị trí, lưu trú, ăn uống. */
    private function fixtures(): array
    {
        return [
            1 => [
                ['key' => 'note:hotel', 'type' => 'note', 'name' => 'Khách sạn Sen', 'lat' => 21.0300, 'lng' => 105.8500],
                ['key' => 'place:1', 'type' => 'place', 'name' => 'Văn Miếu', 'lat' => 21.0277, 'lng' => 105.8355, 'defDur' => 90],
                ['key' => 'place:2', 'type' => 'place', 'name' => 'Hoàng thành', 'lat' => 21.0352, 'lng' => 105.8400, 'defDur' => 90],
                ['key' => 'food:1', 'type' => 'food', 'name' => 'Bún chả', 'lat' => 21.0340, 'lng' => 105.8520, 'defDur' => 60, 'slot' => 'noon'],
                ['key' => 'place:3', 'type' => 'place', 'name' => 'Bảo tàng Dân tộc học', 'lat' => 21.0405, 'lng' => 105.7985, 'defDur' => 90],
                ['key' => 'place:4', 'type' => 'place', 'name' => 'Hồ Tây', 'lat' => 21.0560, 'lng' => 105.8190, 'startMin' => 960, 'durationMin' => 45],
                ['key' => 'note:walk', 'type' => 'note', 'name' => 'Phố đi bộ', 'lat' => 21.0287, 'lng' => 105.8525, 'slot' => 'evening'],
            ],
            2 => [
                ['key' => 'place:5', 'type' => 'place', 'name' => 'Lăng Bác', 'lat' => 21.0369, 'lng' => 105.8347, 'startMin' => 540, 'durationMin' => 90],
                ['key' => 'place:6', 'type' => 'place', 'name' => 'Chùa Một Cột', 'lat' => 21.0359, 'lng' => 105.8336, 'startMin' => 600],         // trùng giờ
                ['key' => 'place:7', 'type' => 'place', 'name' => 'Làng gốm Bát Tràng', 'lat' => 20.9760, 'lng' => 105.9130, 'startMin' => 665], // không kịp đi
                ['key' => 'transport:1', 'type' => 'transport', 'name' => 'Taxi về'],                                                            // chưa có vị trí
                ['key' => 'note:cafe', 'type' => 'note', 'name' => 'Cà phê trứng', 'lat' => 21.0335, 'lng' => 105.8530],
            ],
            3 => [],
            5 => [   // giờ mở cửa (ngày 5 là Thứ Hai): tới sớm thì chờ, đóng cửa, quá giờ, nghỉ trưa
                ['key' => 'place:20', 'type' => 'place', 'name' => 'Bảo tàng', 'lat' => 21.04, 'lng' => 105.80, 'defDur' => 90, 'hours' => ['1' => [[510, 1050]]]],
                ['key' => 'place:21', 'type' => 'place', 'name' => 'Hoàng thành', 'lat' => 21.035, 'lng' => 105.84, 'defDur' => 90, 'hours' => ['1' => [], '2' => [[480, 1020]]]],
                ['key' => 'place:22', 'type' => 'place', 'name' => 'Chả cá', 'lat' => 21.033, 'lng' => 105.85, 'defDur' => 60, 'hours' => ['1' => [[600, 840], [1020, 1260]]], 'slot' => 'afternoon'],
                ['key' => 'place:23', 'type' => 'place', 'name' => 'Chùa', 'lat' => 21.048, 'lng' => 105.836, 'startMin' => 960, 'durationMin' => 60, 'hours' => ['1' => [[480, 990]]]],
                ['key' => 'place:24', 'type' => 'place', 'name' => 'Chưa biết giờ', 'lat' => 21.03, 'lng' => 105.85, 'defDur' => 60, 'hours' => ['3' => [[480, 600]]]],
            ],
            4 => [   // ngày không có bữa ăn: chừa sẵn 60 phút; nhiều mục để vượt sức chứa
                ['key' => 'place:8', 'type' => 'place', 'name' => 'A', 'lat' => 16.05, 'lng' => 108.20, 'defDur' => 150],
                ['key' => 'place:9', 'type' => 'place', 'name' => 'B', 'lat' => 16.10, 'lng' => 108.25, 'defDur' => 150],
                ['key' => 'experience:1', 'type' => 'experience', 'name' => 'C', 'lat' => 16.00, 'lng' => 108.26, 'defDur' => 180],
                ['key' => 'place:10', 'type' => 'place', 'name' => 'D', 'lat' => 15.88, 'lng' => 108.33, 'defDur' => 120],
            ],
            6 => [   // địa điểm trong kho nhận diện theo kind: quán ăn là bữa ăn (không chừa thêm 60 phút), khách sạn là lưu trú (30 phút)
                ['key' => 'place:30', 'type' => 'place', 'kind' => 'hotel', 'name' => 'Sen', 'lat' => 21.03, 'lng' => 105.85, 'defDur' => 60],
                ['key' => 'place:31', 'type' => 'place', 'kind' => 'museum', 'name' => 'Bảo tàng', 'lat' => 21.04, 'lng' => 105.80, 'defDur' => 90],
                ['key' => 'place:32', 'type' => 'place', 'kind' => 'restaurant', 'name' => 'Ngon', 'lat' => 21.035, 'lng' => 105.84, 'defDur' => 60, 'slot' => 'noon'],
            ],
        ];
    }

    private function node(array $input): array
    {
        $bin = trim(strtok((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'), "\r\n") ?: '');
        if ($bin === '') {
            $this->markTestSkipped('Không có Node.js để chạy phần trình duyệt.');
        }
        $p = new Process([$bin, base_path('tests/js/schedule-contract.cjs')]);
        $p->setInput(json_encode($input, JSON_UNESCAPED_UNICODE));
        $p->mustRun();

        return json_decode($p->getOutput(), true);
    }

    public function test_client_and_server_schedule_are_identical(): void
    {
        $rules = PlannerRules::fromConfig();
        $route = RouteService::fromRules($rules->route() + []);
        $days = $this->fixtures();
        $dows = [5 => 1];   // ngày 5 là Thứ Hai; các ngày khác coi như chuyến chưa có ngày đi
        $js = $this->node(['rules' => $rules->client(), 'days' => $days, 'dows' => (object) $dows]);

        foreach ($days as $d => $items) {
            $server = (new DaySchedule($d, $items, $route, $rules, $dows[$d] ?? null))->contract();
            $client = $js[(string) $d];
            $this->assertSame(count($server['rows']), count($client['rows']), "ngày $d: số dòng");
            foreach ($server['rows'] as $i => $row) {
                foreach (['key', 'start', 'end', 'dur', 'fixed', 'slot', 'travelMin', 'waitMin', 'conflict', 'hours'] as $k) {
                    $this->assertSame($row[$k], $client['rows'][$i][$k], "ngày $d, dòng " . ($i + 1) . " ({$row['key']}): $k");
                }
                $this->assertEqualsWithDelta($row['travelKm'], $client['rows'][$i]['travelKm'], 0.11, "ngày $d, dòng " . ($i + 1) . ': travelKm');
            }
            foreach (['activityMin', 'travelMin', 'mealMin', 'bufferMin', 'freeMin', 'occupiedMin', 'availableMin', 'loadPct', 'level', 'loadLabel', 'start', 'end'] as $k) {
                $this->assertSame($server[$k], $client[$k], "ngày $d: $k");
            }
            $this->assertEqualsWithDelta($server['travelKm'], $client['travelKm'], 0.11, "ngày $d: travelKm");
        }

        // các tình huống trong bộ mẫu thật sự xảy ra (không so khớp hai kết quả rỗng)
        $d1 = (new DaySchedule(1, $days[1], $route, $rules))->contract();
        $d2 = (new DaySchedule(2, $days[2], $route, $rules))->contract();
        $d4 = (new DaySchedule(4, $days[4], $route, $rules))->contract();
        $this->assertSame(['overlap', 'travel'], array_values(array_filter(array_column($d2['rows'], 'conflict'))));
        $this->assertGreaterThan(0, $d1['freeMin']);
        $this->assertGreaterThanOrEqual(690, $d1['rows'][3]['start']);      // bữa trưa không trước đầu buổi trưa (11:30)
        $this->assertSame('noon', $d1['rows'][3]['slot']);
        $this->assertSame('evening', end($d1['rows'])['slot']);
        $this->assertSame(30, $d1['rows'][0]['dur']);                         // lưu trú tự thêm: 30 phút
        $this->assertSame(0, $d1['mealMin']);                                 // có món ăn: không chừa thêm
        $this->assertSame(60, $d4['mealMin']);
        $this->assertSame('bad', $d4['level']);
        $d5 = (new DaySchedule(5, $days[5], $route, $rules, 1))->contract();
        $this->assertSame(510, $d5['rows'][0]['start']);                     // chưa tới 08:30: chờ tới giờ mở
        $this->assertNull($d5['rows'][0]['hours']);
        $this->assertSame('closed', $d5['rows'][1]['hours']);                // Thứ Hai đóng cửa
        $this->assertSame(1020, $d5['rows'][2]['start']);                    // buổi chiều nhưng quán nghỉ 14:00-17:00: chờ ca tối
        $this->assertSame('late', $d5['rows'][3]['hours']);                  // giờ cố định 16:00-17:00, chùa đóng 16:30
        $this->assertNull($d5['rows'][4]['hours']);                          // không có giờ Thứ Hai: chưa biết, không cảnh báo
        $d6 = (new DaySchedule(6, $days[6], $route, $rules))->contract();
        $this->assertSame(30, $d6['rows'][0]['dur']);                         // khách sạn trong kho: lưu trú 30 phút
        $this->assertSame(0, $d6['mealMin']);                                 // quán ăn trong kho là bữa ăn: không chừa thêm
    }

    public function test_slot_boundaries_and_kind_patterns_are_shared(): void
    {
        $rules = PlannerRules::fromConfig();
        $probe = [0, 479, 480, 675, 689, 690, 809, 810, 1079, 1080, 1439];
        $kinds = [
            ['type' => 'note', 'name' => 'Homestay Bên Hồ'], ['type' => 'note', 'name' => 'Quán bún cá'], ['type' => 'note', 'name' => 'Ghé quán'],
            ['type' => 'note', 'name' => 'Quảng trường'], ['type' => 'food', 'name' => 'X'], ['type' => 'transport', 'name' => 'Xe'], ['type' => 'place', 'name' => 'Khách sạn'],
        ];
        $js = $this->node(['rules' => $rules->client(), 'days' => (object) [], 'probeMinutes' => $probe, 'probeKinds' => $kinds]);

        $server = array_map(fn($m) => $rules->slotOf($m), $probe);
        $this->assertSame($server, $js['_slotOf']);
        $this->assertSame('morning', $rules->slotOf(675));                    // 11:15 là buổi sáng ở MỌI nơi (trước đây trình duyệt tính là trưa)
        $this->assertSame(array_map(fn($it) => $rules->kindOf($it), $kinds), $js['_kinds']);
        $this->assertSame(['lodging', 'meal', 'meal', null, 'meal', 'transport', null], $js['_kinds']);   // địa điểm không có kind: không đoán theo tên
    }

    public function test_rules_are_derived_from_one_config(): void
    {
        $r = PlannerRules::fromConfig();
        $this->assertSame(600, $r->availableMin());                           // 08:00 - 18:00
        $this->assertSame(480, $r->itemBudgetMin());                          // floor((600 / 1.1 - 60) / 10) * 10
        $this->assertSame(1320, $r->dayEnd());
        foreach (['day_budget_min', 'day_available_min', 'day_start_min', 'late_end_min', 'travel_speed_kmh', 'detour_factor', 'slot_bounds_min'] as $old) {
            $this->assertNull(config('planner.' . $old), "khóa cũ $old không còn: mỗi khái niệm chỉ khai báo một lần");
        }
        // buổi liền nhau, không hở, không chồng
        $prev = null;
        foreach ($r->slots() as $s) {
            if ($prev !== null) {
                $this->assertSame($prev, $s['start']);
            }
            $prev = $s['end'];
        }
        // phương tiện: đổi mode thì tốc độ đổi, không khóa cứng vào ô tô
        $walk = RouteService::fromRules($r->route('walking'));
        $car = RouteService::fromRules($r->route('driving'));
        $this->assertGreaterThan($car->estimate([21.0, 105.0], [21.0, 105.05])['min'], $walk->estimate([21.0, 105.0], [21.0, 105.05])['min']);
        $this->assertSame('walking', $walk->mode());
    }

    public function test_editor_payload_health_and_engine_use_the_same_route_numbers(): void
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $id = app(TripPlanner::class)->create($uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
        $items = array_map(fn($x) => ['type' => 'note', 'label' => $x['name'], 'day' => 1, 'lat' => $x['lat'], 'lng' => $x['lng'], 'source' => 'link'],
            array_slice($this->fixtures()[1], 1, 5));
        $this->withSession(['userId' => $uid, 'username' => 'u1'])->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'items' => $items])->assertOk();

        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $payload = app(TripPlanner::class)->payload($trip);
        $health = $this->withSession(['userId' => $uid, 'username' => 'u1'])->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json('days.0');
        $this->assertSame($payload['days'][0]['minutes'], $health['travelMin']);   // trang sửa và sức khỏe: cùng phút đi lại
        $this->assertSame((int) round($health['travelKm']), $payload['days'][0]['km']);

        // trang sửa nhận đúng bộ quy tắc (không còn hằng số rời rạc / khóa osrm sai chỗ)
        $html = $this->withSession(['userId' => $uid, 'username' => 'u1'])->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('"rules":{"dayStart":480,"softEnd":1080,"dayEnd":1320,"availableMin":600', $html);
        $this->assertStringContainsString('"osrmUrl":null', $html);
        $this->assertStringContainsString('planner-schedule.js', $html);
        $this->assertStringNotContainsString('dayAvailableMin', $html);

        // tự tạo lịch: giờ cùng thang phút-trong-ngày với startMin của trang sửa, đi lại cùng RouteService
        $engine = new PlannerEngine();
        $ref = new \ReflectionMethod($engine, 'timeline');
        $warn = [];
        $mk = fn($k, $n, $la, $lo, $meal = false) => ['key' => $k, 'type' => $meal ? 'food' : 'place', 'name' => $n, 'lat' => $la, 'lng' => $lo, 'isMeal' => $meal, 'durationMinutes' => 60, 'kind' => 'attraction', 'group' => 'see'];
        $args = [1, [$mk('place:1', 'A', 21.0277, 105.8355), $mk('place:2', 'B', 21.0560, 105.8190)], [$mk('food:1', 'Bún', 21.034, 105.852, true)], [21.03, 105.85], 360, &$warn];
        $day = $ref->invokeArgs($engine, $args);
        $this->assertSame(480, $day['items'][0]['startMin']);
        $leg = app(RouteService::class)->leg([21.0277, 105.8355], [21.0560, 105.8190]);
        $this->assertSame($leg['min'], $day['items'][1]['travelMin']);
    }
}
