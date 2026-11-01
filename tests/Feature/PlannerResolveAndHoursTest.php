<?php

namespace Tests\Feature;

use App\Services\Planner\OpeningHours;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Giờ mở cửa (dữ liệu, ràng buộc, API) và Resolution Engine (đề xuất có xem trước, không ghi CSDL). */
class PlannerResolveAndHoursTest extends TestCase
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

    private function place(string $name, float $lat, float $lng, string $kind = 'museum'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2026-11-16', 'budget' => null]);   // 16/11/2026 là Thứ Hai
    }

    private function save(int $id, array $items, string $start = '2026-11-16')
    {
        return $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => $start, 'items' => $items]);
    }

    public function test_parse_osm_opening_hours(): void
    {
        $h = OpeningHours::parseOsm('Tu-Su 08:00-11:30,13:30-17:00; Mo off');
        $this->assertSame([], $h['1']);
        $this->assertSame([[480, 690], [810, 1020]], $h['2']);
        $this->assertSame([[480, 690], [810, 1020]], $h['7']);
        $this->assertCount(7, OpeningHours::parseOsm('24/7'));
        $this->assertSame([[1080, 1440]], OpeningHours::parseOsm('Fr-Mo 18:00-02:00')['5']);   // qua nửa đêm: cắt tại 24:00, khoảng ngày vòng Fr->Mo
        $this->assertArrayHasKey('1', OpeningHours::parseOsm('Fr-Mo 18:00-02:00'));
        $this->assertSame([], OpeningHours::parseOsm('PH off; Jan 10:00-12:00'));                  // phần không hiểu bị bỏ
        $this->assertSame('closed', OpeningHours::check($h, 1, 600, 660));
        $this->assertSame('early', OpeningHours::check($h, 2, 450, 540));
        $this->assertSame('late', OpeningHours::check($h, 2, 660, 720));                         // giờ nghỉ trưa
        $this->assertNull(OpeningHours::check($h, 2, 840, 900));
        $this->assertSame(810, OpeningHours::fit($h, 2, 660, 90));                               // không đủ trước 11:30: sang ca chiều
        $this->assertNull(OpeningHours::check($h, null, 0, 10));                                 // chưa có ngày đi: không cảnh báo
    }

    public function test_hours_command_and_health_report_closed_day(): void
    {
        $museum = $this->place('Bảo tàng', 21.0405, 105.7985);
        $this->artisan('planner:hours', ['type' => 'place', 'id' => $museum, 'hours' => 'Tu-Su 08:30-17:30; Mo off'])->assertSuccessful();
        $this->assertSame(7, DB::table('tbl_opening_hours')->where('refId', $museum)->count());
        $this->artisan('planner:hours', ['type' => 'place', 'id' => $museum, 'hours' => 'khong hieu'])->assertFailed();

        $id = $this->trip();
        $this->save($id, [['type' => 'place', 'refId' => $museum, 'day' => 1]])->assertOk();   // ngày 1 = Thứ Hai: đóng cửa
        $j = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $c = collect($j['checks'])->firstWhere('code', 'hours.closed');
        $this->assertNotNull($c);
        $this->assertSame(1, $c['day']);
        $this->assertSame(0, $c['index']);
        $this->assertSame('closed', $j['days'][0]['rows'][0]['hours']);

        // trang sửa nhận giờ mở cửa của mục để trình duyệt tính cùng quy tắc
        $html = $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('"hours":{"1":[],"2":[[510,1050]]', $html);

        // ngày 2 (Thứ Ba) mở cửa: tự xếp chờ tới 08:30, không cảnh báo
        $this->save($id, [['type' => 'place', 'refId' => $museum, 'day' => 2]])->assertOk();
        $j2 = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->json();
        $this->assertSame('08:30', $j2['days'][1]['rows'][0]['start']);
        $this->assertNull(collect($j2['checks'])->firstWhere('code', 'hours.closed'));
    }

    public function test_resolver_moves_closed_place_to_open_day_without_writing(): void
    {
        $museum = $this->place('Bảo tàng', 21.0405, 105.7985);
        app(OpeningHours::class)->store('place', $museum, 'Tu-Su 08:30-17:30; Mo off');
        $id = $this->trip();
        $this->save($id, [['type' => 'place', 'refId' => $museum, 'day' => 1]])->assertOk();
        $before = DB::table('tbl_trip_items')->where('tripId', $id)->get()->toArray();

        $j = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=hours.closed&day=1&index=0")->assertOk()
            ->assertJsonStructure(['code', 'day', 'index', 'problem', 'options' => [['id', 'label', 'reason', 'changes', 'effects', 'resolves']]])->json();
        $move = collect($j['options'])->firstWhere('id', 'move');
        $this->assertSame([['op' => 'move', 'day' => 1, 'index' => 0, 'toDay' => 2]], $move['changes']);
        $this->assertTrue($move['resolves']);
        $this->assertEquals($before, DB::table('tbl_trip_items')->where('tripId', $id)->get()->toArray());   // chỉ đề xuất, không ghi
    }

    public function test_resolver_balances_overloaded_day_and_fixes_time_conflict(): void
    {
        $id = $this->trip(['startDate' => null]);
        $items = [];
        for ($i = 0; $i < 9; $i++) {
            $items[] = ['type' => 'note', 'label' => 'Điểm ' . $i, 'day' => 1, 'lat' => 21.03 + $i * 0.002, 'lng' => 105.85, 'source' => 'link', 'durationMin' => 75];
        }
        $items[] = ['type' => 'note', 'label' => 'Hồ', 'day' => 2, 'lat' => 21.00, 'lng' => 105.80, 'source' => 'link', 'startMin' => 540, 'durationMin' => 90];
        $items[] = ['type' => 'note', 'label' => 'Làng', 'day' => 2, 'lat' => 21.00, 'lng' => 105.95, 'source' => 'link', 'startMin' => 600];
        $this->save($id, $items, '')->assertOk();

        $bal = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=capacity.overload&day=1")->assertOk()->json();
        $opt = $bal['options'][0];
        $this->assertSame('balance', $opt['id']);
        $this->assertTrue($opt['resolves']);
        $d1 = collect($opt['effects'])->firstWhere('day', 1);
        $this->assertGreaterThanOrEqual(100, $d1['beforePct']);
        $this->assertLessThan(90, $d1['afterPct']);
        $this->assertNotContains(0, array_column($opt['changes'], 'index'));   // mục đầu ngày (thường là lưu trú) giữ nguyên

        $t = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=time.overlap&day=2&index=1")->assertOk()->json('options');
        $this->assertSame('shift', $t[0]['id']);
        $this->assertGreaterThanOrEqual(630, $t[0]['changes'][0]['startMin']);   // sau 10:30 + thời gian đi
        $this->assertTrue($t[0]['resolves']);

        // người không có quyền sửa: không xem được
        $other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'L', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'u2'])->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=capacity.overload&day=1")->assertNotFound();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=DROP TABLE")->assertStatus(422);
    }
}
