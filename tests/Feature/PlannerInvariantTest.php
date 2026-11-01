<?php

namespace Tests\Feature;

use App\Services\Planner\OpeningHours;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bất biến nghiệp vụ của Planner (không phải chi tiết cài đặt):
 *  1. Xem trước = Áp dụng: áp dụng phương án rồi lưu thật, sức khỏe máy chủ trùng với "after"/"effects" đã xem trước.
 *  2. Mục có giờ cố định không bị tối ưu tuyến / cân bằng đổi chỗ hay đổi giờ.
 *  3. Mục đã khóa không bị di chuyển.
 *  4. Nơi đóng cửa cả ngày: không phương án nào "giải quyết" bằng cách bỏ giờ cố định / dời giờ.
 *  5. Giờ mở cửa: trong giờ thì không báo; ngoài giờ là lỗi cứng (hard, bad).
 *  6-7. Nguồn tuyến minh bạch; OSRM lỗi thì dùng ước tính, ghi rõ fallback, Planner vẫn chạy.
 *  8. Hai người cùng sửa: lưu bằng bản cũ (rev cũ) bị từ chối 409.
 */
class PlannerInvariantTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(int $days = 3, ?string $start = '2026-11-16'): int   // 16/11/2026 là Thứ Hai
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => $days, 'groupSize' => 2, 'startDate' => $start, 'budget' => null]);
    }

    private function place(string $name, float $lat, float $lng, ?string $hours = null, string $kind = 'museum'): int
    {
        $id = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        if ($hours) {
            app(OpeningHours::class)->store('place', $id, $hours);
        }

        return $id;
    }

    private function note(string $label, float $lat, float $lng, array $o = []): array
    {
        return $o + ['type' => 'note', 'label' => $label, 'lat' => $lat, 'lng' => $lng, 'source' => 'link'];
    }

    /** @param array<int,list<array>> $days  ngày -> mục (định dạng gửi lên /luu, chưa có khóa day) */
    private array $rain = [];   // phương án mưa đang lưu (định dạng gửi lên /luu)

    private function save(int $id, array $days, array $inbox = [], ?string $start = '2026-11-16'): void
    {
        $items = [];
        foreach ($days as $d => $list) {
            foreach ($list as $it) {
                $items[] = $it + ['day' => $d];
            }
        }
        foreach ($inbox as $it) {
            $items[] = ['day' => null] + $it;
        }
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => count($days), 'groupSize' => 2, 'startDate' => $start,
            'items' => $items, 'rainPlan' => (object) $this->rain])->assertOk();
    }

    /** Áp dụng phương án ĐÚNG như trình duyệt (applyResolution): move = bỏ giờ cố định + xuống cuối ngày nhận; time; reorder. */
    private function applyLikeBrowser(array $days, array $inbox, array $changes): array
    {
        $orig = $days;
        $remove = [];
        foreach ($changes as $c) {
            if ($c['op'] === 'rainplan') {
                $this->rain[(string) $c['day']] = array_map(fn($x) => ['type' => $x['type'], 'refId' => $x['refId']], $c['items']);
            } elseif ($c['op'] === 'time') {
                if ($c['startMin'] === null) {
                    unset($days[$c['day']][$c['index']]['startMin']);
                } else {
                    $days[$c['day']][$c['index']]['startMin'] = $c['startMin'];
                }
            } elseif ($c['op'] === 'reorder') {
                $days[$c['day']] = array_map(fn($i) => $orig[$c['day']][$i], $c['order']);
            } elseif ($c['op'] === 'replace') {
                $old = $orig[$c['day']][$c['index']];
                $days[$c['day']][$c['index']] = array_filter(['type' => $c['with']['type'], 'refId' => $c['with']['refId'],
                    'startMin' => $old['startMin'] ?? null, 'slot' => $old['slot'] ?? null], fn($v) => $v !== null);
                unset($old['startMin']);
                $inbox[] = $old;
            } elseif ($c['op'] === 'move') {
                $it = $orig[$c['day']][$c['index']];
                unset($it['startMin']);
                $remove[$c['day']][] = $c['index'];
                if ($c['toDay'] === null) {
                    $inbox[] = $it;
                } else {
                    $days[$c['toDay']][] = $it;
                }
            }
        }
        foreach ($remove as $d => $idx) {
            $days[$d] = array_values(array_filter($days[$d], fn($k) => !in_array($k, $idx, true), ARRAY_FILTER_USE_KEY));
        }

        return [$days, $inbox];
    }

    /**
     * Áp dụng phương án bằng CHÍNH mã trình duyệt: payload đã lưu -> itemForClient -> planner-resolve.js -> planner-save.js -> POST /luu.
     * Không có Node thì dùng bản PHP tương đương (applyLikeBrowser).
     */
    private function applyViaBrowserJs(int $id, array $changes, array $days, array $inbox, ?string $start): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            [$d2, $i2] = $this->applyLikeBrowser($days, $inbox, $changes);
            $this->save($id, $d2, $i2, $start);

            return;
        }
        $planner = app(TripPlanner::class);
        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $p = $planner->payload($trip);
        $items = array_map([TripPlanner::class, 'itemForClient'], array_merge(...array_map(fn($d) => $d['items'], $p['days']), ...[$p['unassigned']]));
        $rain = [];
        foreach ($p['rain']['plan'] as $d => $list) {
            $rain[(string) $d] = $list;
        }
        $in = ['items' => $items, 'rain' => (object) $rain, 'changes' => $this->lastChanges = $changes, 'rev' => (int) ($trip->rev ?? 0),
            'meta' => ['title' => $trip->title, 'start' => $trip->startDate, 'days' => (int) $trip->days, 'group' => (int) $trip->groupSize, 'budget' => $trip->budget, 'dest' => $trip->destinationId]];
        $proc = new \Symfony\Component\Process\Process([$node, base_path('tests/js/apply-resolution.cjs')]);
        $proc->setInput(json_encode($in, JSON_UNESCAPED_UNICODE));
        $proc->mustRun();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", json_decode($proc->getOutput(), true))->assertOk();
    }

    private array $lastChanges = [];

    private function health(int $id): array
    {
        return $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
    }

    private function resolve(int $id, string $code, ?int $day, ?int $index = null): array
    {
        return $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=$code" . ($day ? "&day=$day" : '') . ($index !== null ? "&index=$index" : ''))->assertOk()->json();
    }

    /** Với MỌI phương án: áp dụng như trình duyệt, lưu thật, so với xem trước; rồi khôi phục để thử phương án kế tiếp. */
    private function assertPreviewEqualsApply(int $id, array $days, array $inbox, string $code, ?int $day, ?int $index = null): array
    {
        $res = $this->resolve($id, $code, $day, $index);
        $this->assertNotEmpty($res['options'], "không có phương án cho $code");
        foreach ($res['options'] as $o) {
            $this->applyViaBrowserJs($id, $o['changes'], $days, $inbox, '2026-11-16');
            $h = $this->health($id);
            $this->assertSame($o['after']['score'], $h['score'], "{$o['id']}: điểm sau khi áp dụng khác xem trước");
            foreach ($o['effects'] as $e) {
                $real = collect($h['days'])->firstWhere('day', $e['day']);
                $this->assertSame($e['afterPct'], $real['loadPct'], "{$o['id']}: độ dày ngày {$e['day']} khác xem trước");
                $this->assertSame($e['afterMin'], $real['occupiedMin'], "{$o['id']}: phút đã dùng ngày {$e['day']} khác xem trước");
            }
            if ($o['resolves']) {
                $still = collect($h['checks'])->filter(fn($c) => $c['code'] === $code && $c['day'] === $day && ($index === null || $c['index'] === $index));
                $this->assertCount(0, $still, "{$o['id']} báo đã giải quyết nhưng $code vẫn còn");
                $this->assertSame([], array_values(array_filter($o['newIssues'], fn($x) => $x['severity'] === 'hard')));
            }
            $this->rain = [];
            $this->save($id, $days, $inbox);   // khôi phục
        }

        return $res;
    }

    // ------------------------------------------------------------------ 1. Xem trước = Áp dụng

    public function test_preview_equals_apply_for_balance_and_time_conflicts(): void
    {
        $id = $this->trip(3, null);
        $d1 = [$this->note('Khách sạn', 21.03, 105.85, ['durationMin' => 30])];
        for ($i = 0; $i < 8; $i++) {
            $d1[] = $this->note('Điểm ' . $i, 21.03 + $i * 0.003, 105.85, ['durationMin' => 75]);
        }
        $days = [
            1 => $d1,
            2 => [$this->note('Hồ', 21.00, 105.80, ['startMin' => 540, 'durationMin' => 90]), $this->note('Làng', 21.00, 105.95, ['startMin' => 600])],
            3 => [$this->note('Chợ', 21.035, 105.85)],
        ];
        $this->save($id, $days, [], null);

        $bal = $this->assertPreviewEqualsApply($id, $days, [], 'capacity.overload', 1);
        $this->assertTrue($bal['options'][0]['resolves']);
        $this->assertNotEmpty($bal['options'][0]['reasons']);
        $this->assertArrayHasKey('beforeKm', $bal['options'][0]['effects'][0]);

        $this->assertPreviewEqualsApply($id, $days, [], 'time.overlap', 2, 1);
    }

    public function test_preview_equals_apply_for_opening_hours_and_route(): void
    {
        $museum = $this->place('Bảo tàng', 21.0405, 105.7985, 'Tu-Su 08:30-17:30; Mo off');
        $this->place('Bảo tàng mở thứ Hai', 21.0420, 105.8010, 'Mo-Su 08:00-17:00');      // nơi tương tự, mở cửa hôm đó
        $this->place('Bảo tàng cũng đóng', 21.0410, 105.7990, 'Tu-Su 08:00-17:00; Mo off'); // tương tự nhưng cũng đóng: không được đề xuất
        $this->place('Quán xa loại khác', 21.0406, 105.7986, 'Mo-Su 08:00-17:00', 'restaurant');
        $id = $this->trip(2);
        $days = [
            1 => [['type' => 'place', 'refId' => $museum, 'startMin' => 540], $this->note('Quán', 21.03, 105.85)],
            2 => [$this->note('Khách sạn', 21.03, 105.85, ['durationMin' => 30]), $this->note('Xa', 21.10, 105.85), $this->note('Gần', 21.035, 105.85), $this->note('Xa nữa', 21.12, 105.86)],
        ];
        $this->save($id, $days);
        $res = $this->assertPreviewEqualsApply($id, $days, [], 'hours.closed', 1, 0);
        $ids = array_column($res['options'], 'id');
        $this->assertContains('move', $ids);
        $this->assertContains('replace', $ids);
        $rep = collect($res['options'])->firstWhere('id', 'replace');
        $this->assertSame('Bảo tàng mở thứ Hai', $rep['changes'][0]['with']['name']);   // cùng loại, mở cửa; không đề xuất nơi cũng đóng / khác loại
        $this->assertCount(1, collect($res['options'])->where('id', 'replace'));
        $this->assertTrue($rep['resolves']);
    }

    public function test_preview_equals_apply_for_rain_plan(): void
    {
        DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => 11, 'rainyDays' => 18, 'years' => 10, 'periodFrom' => 2015, 'periodTo' => 2024, 'fetchedAt' => now()]);   // tháng 11 hay mưa
        $view = $this->place('Điểm ngắm cảnh', 21.03, 105.85, null, 'viewpoint');
        $beach = $this->place('Bãi đá sông Hồng', 21.05, 105.86, null, 'viewpoint');
        $this->place('Bảo tàng gần', 21.031, 105.851, null, 'museum');
        $this->place('Bảo tàng khác', 21.051, 105.861, null, 'museum');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => '2026-11-17', 'budget' => null, 'destinationId' => $this->hn]);
        $days = [1 => [['type' => 'place', 'refId' => $view], ['type' => 'place', 'refId' => $beach]]];
        $this->save($id, $days, [], '2026-11-17');
        $rain = collect($this->health($id)['checks'])->firstWhere('code', 'context.rain');
        $this->assertNotNull($rain, 'tháng mưa + 2 mục ngoài trời phải có cảnh báo mưa');

        $res = $this->resolve($id, 'context.rain', 1);
        foreach ($res['options'] as $o) {
            $this->applyViaBrowserJs($id, $o['changes'], $days, [], '2026-11-17');
            $h = $this->health($id);
            $this->assertSame($o['after']['score'], $h['score'], "{$o['id']}: điểm sau khi áp dụng khác xem trước");
            $this->assertTrue($o['resolves']);
            $this->assertNull(collect($h['checks'])->firstWhere('code', 'context.rain'), "{$o['id']} báo đã giải quyết nhưng vẫn còn cảnh báo mưa");
            $this->rain = [];
            $this->save($id, $days, [], '2026-11-17');
        }
        $this->assertSame(['rain-backup', 'rain-swap'], collect($res['options'])->pluck('id')->sort()->values()->all());
        $swap = collect($res['options'])->firstWhere('id', 'rain-swap');
        $this->assertSame(['Bảo tàng gần', 'Bảo tàng khác'], array_map(fn($c) => $c['with']['name'], $swap['changes']));   // mỗi nơi lấy nơi trong nhà gần nhất
    }

    // ------------------------------------------------------------------ 2-3. Cố định / khóa không bị đụng

    public function test_fixed_and_locked_items_are_never_moved_by_optimizer_or_balance(): void
    {
        $id = $this->trip(2, null);
        $d1 = [
            $this->note('Khách sạn', 21.03, 105.85, ['durationMin' => 30]),
            $this->note('Xa A', 21.10, 105.85, ['durationMin' => 120]),
            $this->note('Khóa', 21.09, 105.85, ['locked' => true, 'durationMin' => 120]),
            $this->note('Gần B', 21.035, 105.85, ['durationMin' => 120]),
            $this->note('Cố định', 21.12, 105.86, ['startMin' => 900, 'durationMin' => 120]),
            $this->note('Gần C', 21.032, 105.851, ['durationMin' => 120]),
            $this->note('Xa D', 21.11, 105.84, ['durationMin' => 120]),
        ];
        $this->save($id, [1 => $d1, 2 => [$this->note('Chợ', 21.03, 105.85)]], [], null);

        $bal = $this->resolve($id, 'capacity.overload', 1);
        $this->assertNotEmpty($bal['options']);
        foreach ($bal['options'] as $o) {
            foreach ($o['changes'] as $c) {
                $this->assertNotContains($c['index'], [0, 2, 4], "{$o['id']} đụng tới mục đầu ngày / đã khóa / cố định giờ");
                $this->assertNotSame('time', $c['op']);
            }
        }
        // sắp tuyến: vị trí 0 (đầu ngày), 2 (khóa), 4 (cố định) giữ nguyên
        $opt = app(RouteService::class)->optimize(array_map(fn($i) => ['key' => (string) $i, 'lat' => $d1[$i]['lat'], 'lng' => $d1[$i]['lng'],
            'anchor' => in_array($i, [0, 2, 4], true)], array_keys($d1)));
        $this->assertSame(['0', '2', '4'], [$opt['order'][0], $opt['order'][2], $opt['order'][4]]);
    }

    // ------------------------------------------------------------------ 4-5. Giờ mở cửa

    public function test_closed_place_is_never_resolved_by_unfixing_and_hours_are_hard_errors(): void
    {
        $museum = $this->place('Bảo tàng', 21.0405, 105.7985, 'Tu-Su 08:30-17:30; Mo off');
        $cafe = $this->place('Cà phê', 21.03, 105.85, 'Mo-Su 07:00-12:00');
        $id = $this->trip(2);
        $this->save($id, [1 => [['type' => 'place', 'refId' => $museum, 'startMin' => 600]], 2 => [['type' => 'place', 'refId' => $cafe, 'startMin' => 600]]]);
        $h = $this->health($id);
        $closed = collect($h['checks'])->firstWhere('code', 'hours.closed');
        $this->assertSame(['hard', 'bad'], [$closed['severity'], $closed['level']]);
        $this->assertNull(collect($h['checks'])->firstWhere('code', 'hours.late'));   // 10:00-11:00 trong giờ mở của quán cà phê: không báo
        $this->assertSame('bad', $h['level']);
        $this->assertStringContainsString('Cần sửa', $h['summary']['label']);
        $this->assertNotEmpty($h['recommended']);

        $res = $this->resolve($id, 'hours.closed', 1, 0);
        foreach ($res['options'] as $o) {
            $this->assertNotContains('time', array_column($o['changes'], 'op'), 'đóng cửa cả ngày thì đổi giờ là vô nghĩa');
        }
        $this->assertContains('inbox', array_column($res['options'], 'id'));

        // quá giờ đóng cửa (11:30-12:30, quán đóng 12:00) -> lỗi cứng, có phương án dời sớm hơn
        $this->save($id, [1 => [], 2 => [['type' => 'place', 'refId' => $cafe, 'startMin' => 690]]]);
        $late = collect($this->health($id)['checks'])->firstWhere('code', 'hours.late');
        $this->assertSame('hard', $late['severity']);
        $shift = collect($this->resolve($id, 'hours.late', 2, 0)['options'])->firstWhere('id', 'shift');
        $this->assertTrue($shift['resolves']);
    }

    // ------------------------------------------------------------------ 6-7. Nguồn tuyến + OSRM lỗi

    public function test_route_provider_is_transparent_and_osrm_failure_falls_back(): void
    {
        $id = $this->trip(1, null);
        $this->save($id, [1 => [$this->note('A', 21.03, 105.85), $this->note('B', 21.06, 105.80)]], [], null);
        $this->assertSame(['requested' => 'haversine', 'actual' => 'haversine', 'fallback' => false], array_intersect_key($this->health($id)['route'], array_flip(['requested', 'actual', 'fallback'])));

        config(['planner.route.provider' => 'osrm', 'planner.route.osrm_url' => 'http://osrm.test']);
        $up = false;   // một fake duy nhất, đổi trạng thái máy chủ OSRM giữa hai lần gọi
        Http::fake(['osrm.test/*' => function () use (&$up) {
            return $up ? Http::response(['code' => 'Ok', 'distances' => [[0, 7300], [7300, 0]], 'durations' => [[0, 1260], [1260, 0]]])
                : Http::response(['message' => 'down'], 500);
        }]);
        app()->forgetInstance(RouteService::class);
        $h = $this->health($id);
        $this->assertSame(['requested' => 'osrm', 'actual' => 'haversine', 'fallback' => true], array_intersect_key($h['route'], array_flip(['requested', 'actual', 'fallback'])));
        $this->assertGreaterThan(0, $h['days'][0]['travelMin']);   // vẫn tính được bằng ước tính
        $html = $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('Chưa lấy được đường bộ thật từ OSRM', $html);

        // OSRM lỗi -> cầu dao tạm ngắt OSRM (các lần tải sau dùng ước tính ngay, không chờ mạng làm treo trang)
        $this->assertTrue(\App\Services\Planner\ExternalGuard::isDown('osrm'));
        $sent = count(Http::recorded());
        $this->health($id);
        $this->assertSame($sent, count(Http::recorded()), 'đang ngắt thì không gọi lại OSRM');

        // OSRM trả lời được (hết thời gian ngắt) -> dùng số của OSRM, không dự phòng
        $up = true;
        \App\Services\Planner\ExternalGuard::reset('osrm');
        app()->forgetInstance(RouteService::class);   // lỗi lần trước không bị lưu cache nên lần này gọi lại OSRM
        $h2 = $this->health($id);
        $this->assertSame('osrm', $h2['route']['actual']);
        $this->assertFalse($h2['route']['fallback']);
        $this->assertSame(21, $h2['days'][0]['travelMin']);
        $this->assertSame(7.3, $h2['days'][0]['travelKm']);
    }

    // ------------------------------------------------------------------ 8. Xung đột khi hai người cùng sửa

    public function test_stale_revision_is_rejected_with_409(): void
    {
        $id = $this->trip(1, null);
        $body = ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'items' => [['type' => 'note', 'label' => 'A', 'day' => 1]]];
        $rev = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $body + ['rev' => 0])->assertOk()->json('rev');
        $this->assertGreaterThan(0, $rev);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $body + ['rev' => $rev])->assertOk();               // bản mới nhất: được
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Ghi đè'] + $body + ['rev' => $rev])->assertStatus(409)->assertJson(['conflict' => true]);
        $this->assertSame('Hà Nội', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }
}
