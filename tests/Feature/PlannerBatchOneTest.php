<?php

namespace Tests\Feature;

use App\Services\Planner\Holidays;
use App\Services\Planner\TripCalendar;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Đợt 1: lịch .ics, lệnh cấp ngày / chuyến của La bàn, giờ mở cửa theo ngày (lễ, đóng tạm thời, theo mùa),
 * người dùng báo sai + admin duyệt, trang phân tích.
 */
class PlannerBatchOneTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 09:00:00');
        Cache::flush();
        config(['core_destinations.slugs' => [], 'planner.weather.enabled' => false, 'ai.enabled' => false]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'x', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'b1', 'email' => 'b1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'b1'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(string $name, string $kind = 'museum', float $d = 0): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->dest, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => 21.03 + $d, 'longitude' => 105.85 + $d, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()]);
    }

    /** Chuyến 3 ngày từ thứ Tư 03/03/2027: ngày 1 hai nơi, ngày 2 một nơi. */
    private function trip(array $ids, string $start = '2027-03-03'): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->dest]);
        $items = [];
        foreach ($ids as [$ref, $day]) {
            $items[] = ['type' => 'place', 'refId' => $ref, 'day' => $day];
        }
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => $start,
            'items' => $items, 'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();

        return $id;
    }

    public function test_ics_download_and_signed_feed(): void
    {
        $a = $this->place('Văn Miếu');
        $id = $this->trip([[$a, 1]]);
        $r = $this->withSession($this->s)->get("/len-ke-hoach/$id/lich.ics")->assertOk();
        $this->assertStringContainsString('text/calendar', $r->headers->get('Content-Type'));
        $body = $r->getContent();
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:Văn Miếu', $body);
        $this->assertMatchesRegularExpression('/DTSTART:20270303T\d{4}00Z/', $body);
        $this->assertStringContainsString("\r\n", $body);

        $url = TripCalendar::feedUrl($id, $this->uid);
        $this->get($url)->assertOk()->assertSee('VEVENT', false);
        $this->get(str_replace(TripCalendar::sign($id, $this->uid), str_repeat('a', 24), $url))->assertNotFound();
        $other = DB::table('tbl_users')->insertGetId(['username' => 'b2', 'email' => 'b2@example.com', 'password' => 'x', 'fullName' => 'L', 'isActive' => 'y']);
        $this->get(TripCalendar::feedUrl($id, $other))->assertNotFound();   // người không xem được chuyến
    }

    public function test_copilot_day_commands_without_llm(): void
    {
        $a = $this->place('A');
        $b = $this->place('B', 'museum', 0.001);
        $c = $this->place('C', 'museum', 0.002);
        $id = $this->trip([[$a, 1], [$b, 1], [$c, 2]]);
        $ask = fn(string $t) => $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => $t])->assertOk()->json();

        $r = $ask('bỏ ngày 1');
        $this->assertSame('proposal', $r['kind']);
        $this->assertCount(2, $r['changes']);
        $this->assertSame([null, null], array_column($r['changes'], 'toDay'));

        $r = $ask('đổi ngày 1 với ngày 2');
        $this->assertSame('proposal', $r['kind']);
        $this->assertEqualsCanonicalizing([2, 2, 1], array_column($r['changes'], 'toDay'));

        $r = $ask('rút còn 1 ngày');
        $this->assertSame('tripedit', $r['kind']);
        $this->assertSame(['days' => 1], $r['trip']);
        $this->assertStringContainsString('về Đã lưu', $r['say'][0]['t']);

        $r = $ask('dời chuyến sang 10/03');
        $this->assertSame('tripedit', $r['kind']);
        $this->assertSame(['startDate' => '2027-03-10'], $r['trip']);
        $this->assertSame('rules', DB::table('tbl_ai_runs')->orderByDesc('runId')->value('engine'));
    }

    public function test_holiday_temporary_closure_and_seasonal_hours(): void
    {
        $this->assertSame('Mùng 1 Tết', Holidays::name('2027-02-06'));
        $this->assertSame('Quốc khánh', Holidays::name('2027-09-02'));
        $a = $this->place('Bảo tàng A');
        $b = $this->place('Bảo tàng B', 'museum', 0.001);
        app(\App\Services\Planner\OpeningHours::class)->store('place', $a, 'Mo-Su 08:00-17:00; PH off');
        app(\App\Services\Planner\OpeningHours::class)->store('place', $b, 'Mo-Su 08:00-17:00');
        // chuyến 30/04/2027 (lễ): A nghỉ lễ; B đóng tạm thời theo quy tắc admin
        DB::table('tbl_hours_rules')->insert(['refType' => 'place', 'refId' => $b, 'kind' => 'closed', 'dateFrom' => '2027-04-29', 'dateTo' => '2027-05-02', 'note' => 'tu bổ mái', 'source' => 'manual']);
        $id = $this->trip([[$a, 1], [$b, 1]], '2027-04-30');
        $checks = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json('checks');
        $closed = array_values(array_filter($checks, fn($c) => $c['code'] === 'hours.closed'));
        $this->assertCount(2, $closed);
        $this->assertStringContainsString('Ngày Giải phóng miền Nam', $closed[0]['text'] . $closed[1]['text']);
        $this->assertStringContainsString('tu bổ mái', $closed[0]['text'] . $closed[1]['text']);

        // giờ theo mùa lặp hằng năm cho cả điểm đến: 09:00–16:00 trong tháng 3
        DB::table('tbl_hours_rules')->insert(['refType' => null, 'destinationId' => $this->dest, 'kind' => 'hours', 'dateFrom' => '2020-03-01', 'dateTo' => '2020-03-31',
            'yearly' => 1, 'openMin' => 540, 'closeMin' => 960, 'source' => 'manual']);
        $id2 = $this->trip([[$b, 1]], '2027-03-03');
        $p = app(TripPlanner::class)->payload(DB::table('tbl_trips')->where('tripId', $id2)->first());
        $it = $p['days'][0]['items'][0];
        $this->assertSame([[540, 960]], $it['hours']['3']);
        $this->assertStringContainsString('Giờ theo mùa 09:00–16:00', $it['hoursNote']);
    }

    public function test_user_report_and_admin_review(): void
    {
        $a = $this->place('Chùa X', 'place_of_worship');
        $this->withSession($this->s)->postJson('/dia-diem/bao-sai', ['type' => 'place', 'refId' => $a, 'kind' => 'hours', 'suggested' => 'Mo-Su 07:00-18:00', 'detail' => 'biển ở cổng'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->withSession($this->s)->postJson('/dia-diem/bao-sai', ['type' => 'place', 'refId' => 999999, 'kind' => 'hours'])->assertNotFound();
        $this->flushSession();
        $this->assertContains($this->postJson('/dia-diem/bao-sai', ['type' => 'place', 'refId' => $a, 'kind' => 'hours'])->status(), [302, 401, 403]);
        $rid = (int) DB::table('tbl_place_reports')->value('reportId');

        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'm' . uniqid(), 'passWord' => 'x', 'email' => uniqid() . '@example.com', 'fullName' => 'A', 'address' => 'HN', 'role' => 'manager']);
        $s = ['adminId' => $admin];
        $this->withSession($s)->get('/admin/place-data')->assertOk()->assertSee('Chùa X');
        $this->withSession($s)->post("/admin/place-data/reports/$rid", ['decision' => 'accept', 'suggested' => 'Mo-Su 07:00-18:00'])->assertSessionHas('success');
        $this->assertSame(420, (int) DB::table('tbl_opening_hours')->where('refType', 'place')->where('refId', $a)->where('dayOfWeek', 1)->value('openMin'));
        $this->assertSame('accepted', DB::table('tbl_place_reports')->where('reportId', $rid)->value('status'));

        $this->withSession($s)->post('/admin/place-data/rules', ['target' => 'destination', 'destinationId' => $this->dest, 'kind' => 'closed',
            'dateFrom' => '2027-02-05', 'dateTo' => '2027-02-10', 'note' => 'nghỉ Tết'])->assertRedirect();
        $this->assertSame(1, DB::table('tbl_hours_rules')->count());
        $this->withSession($s)->get('/admin/place-data?tab=rules')->assertOk()->assertSee('nghỉ Tết');
        $this->withSession($s)->get('/admin/planner-analytics')->assertOk()->assertSee('Phân tích Trip Planner');
    }
}
