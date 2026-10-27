<?php

namespace Tests\Feature;

use App\Services\Content\ContentIssues;
use App\Services\Content\CoverageHistory;
use App\Services\Content\CoverageScorer;
use App\Services\Content\ItineraryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 3: CoverageScorer, lịch sử, lỗi dữ liệu, trang Chất lượng nội dung; lịch trình có khung giờ, thời lượng, di chuyển. */
class Week3QualityTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $cd;
    private string $long;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->long = str_repeat('Mô tả đủ dài cho nội dung kiểm thử. ', 4);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => $this->long, 'latitude' => 21.03, 'longitude' => 105.85, 'coverImage' => 'discover/a.webp', 'imageCredit' => 'Tác giả', 'isActive' => 1]);
        $this->cd = DB::table('tbl_destinations')->insertGetId(['slug' => 'con-dao', 'name' => 'Côn Đảo', 'province' => 'BRVT', 'domain' => 'n',
            'description' => 'ngắn', 'latitude' => 8.68, 'longitude' => 106.6, 'isActive' => 1]);
    }

    private function admin(): int
    {
        return DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
    }

    /** Đưa Hà Nội tới mức Planner-ready. */
    private function fillHanoi(): void
    {
        for ($m = 1; $m <= 12; $m++) {
            DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => $m, 'level' => 'ok', 'source' => 'manual']);
            DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => $m, 'years' => 10, 'periodFrom' => 2014, 'periodTo' => 2024]);
        }
        for ($i = 0; $i < 20; $i++) {
            DB::table('tbl_external_places')->insert(['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => "node/$i", 'name' => "P$i", 'category' => $i < 5 ? 'eat' : 'see',
                'latitude' => 21 + $i / 1000, 'longitude' => 105.8, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        }
        for ($i = 0; $i < 6; $i++) {
            DB::table('tbl_foods')->insert(['slug' => "f$i", 'name' => "Món $i", 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        }
        for ($i = 0; $i < 4; $i++) {
            DB::table('tbl_experiences')->insert(['slug' => "e$i", 'name' => "Trải nghiệm $i", 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết',
                'difficulty' => 'easy', 'durationMinutes' => 90, 'reviewStatus' => 'published']);
        }
        DB::table('tbl_events')->insert(['slug' => 'le', 'name' => 'Lễ', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết',
            'solarMonth' => 10, 'solarDay' => 10, 'reviewStatus' => 'published']);
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => 'hn-1', 'title' => 'Hà Nội 1 ngày', 'days' => 1, 'summary' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => DB::table('tbl_foods')->value('foodId'), 'label' => 'Món 0']);
    }

    public function test_scorer_counts_only_published_and_valid_coordinates(): void
    {
        $this->fillHanoi();
        DB::table('tbl_foods')->insert(['slug' => 'nhap', 'name' => 'Nháp', 'destinationId' => $this->hn, 'reviewStatus' => 'draft']);
        DB::table('tbl_external_places')->insert(['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => 'node/zero', 'name' => 'Zero', 'category' => 'eat',
            'latitude' => 0, 'longitude' => 0, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()]);

        $s = app(CoverageScorer::class)->score($this->hn);
        $this->assertSame(6, $s['counts']['foods']);                 // nháp không tính
        $this->assertSame(21, $s['counts']['places']);               // có (0,0) vẫn là địa điểm đã duyệt
        $this->assertSame(20, $s['counts']['placesCoords']);         // nhưng không phải tọa độ hợp lệ
        $this->assertSame(5, $s['counts']['placesEat']);
        $this->assertTrue($s['plannerReady'], implode('; ', $s['plannerMissing']));
    }

    public function test_percent_uses_one_formula_and_tour_waiver_changes_denominator(): void
    {
        $this->fillHanoi();
        $s = app(CoverageScorer::class)->score($this->hn);
        $this->assertSame(100, $s['applicable']);
        $this->assertSame(CoverageScorer::percent($s['earned'], $s['applicable']), $s['percent']);

        config(['coverage.tour_waiver' => ['ha-noi' => 'Không có tour phù hợp']]);
        $w = app(CoverageScorer::class)->score($this->hn);
        $this->assertSame(95, $w['applicable']);
        $this->assertFalse($w['criteria']['tours']['applicable']);
        $this->assertGreaterThan($s['percent'], $w['percent']);
    }

    public function test_event_waiver_needs_a_reason(): void
    {
        $this->fillHanoi();
        DB::table('tbl_events')->delete();
        $this->assertFalse(app(CoverageScorer::class)->score($this->hn)['plannerReady']);

        config(['coverage.event_waiver' => ['ha-noi' => '   ']]);
        $this->assertFalse(app(CoverageScorer::class)->score($this->hn)['plannerReady']);

        config(['coverage.event_waiver' => ['ha-noi' => 'Không có lễ hội đáng kể']]);
        $this->assertTrue(app(CoverageScorer::class)->score($this->hn)['plannerReady']);
    }

    public function test_itinerary_scope_one_destination_only(): void
    {
        $f1 = DB::table('tbl_foods')->insertGetId(['slug' => 'a', 'name' => 'A', 'destinationId' => $this->hn, 'reviewStatus' => 'published']);
        $f2 = DB::table('tbl_foods')->insertGetId(['slug' => 'b', 'name' => 'B', 'destinationId' => $this->cd, 'reviewStatus' => 'published']);
        $mk = fn(string $slug) => DB::table('tbl_itineraries')->insertGetId(['slug' => $slug, 'title' => $slug, 'days' => 1, 'reviewStatus' => 'published']);
        $one = $mk('one');
        $two = $mk('two');
        $broken = $mk('broken');
        DB::table('tbl_itinerary_items')->insert([
            ['itineraryId' => $one, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => $f1, 'label' => 'A'],
            ['itineraryId' => $one, 'dayNo' => 1, 'orderNo' => 2, 'refType' => 'transport', 'refId' => null, 'label' => 'Taxi'],
            ['itineraryId' => $two, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => $f1, 'label' => 'A'],
            ['itineraryId' => $two, 'dayNo' => 1, 'orderNo' => 2, 'refType' => 'food', 'refId' => $f2, 'label' => 'B'],
            ['itineraryId' => $broken, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => $f1, 'label' => 'A'],
            ['itineraryId' => $broken, 'dayNo' => 1, 'orderNo' => 2, 'refType' => 'food', 'refId' => 999999, 'label' => 'đã xóa'],
        ]);
        $r = (new ItineraryScope())->resolve();
        $this->assertSame($this->hn, $r[$one]['single']);
        $this->assertNull($r[$two]['single']);
        $this->assertNull($r[$broken]['single']);
        $this->assertSame(1, $r[$broken]['broken']);
    }

    public function test_history_snapshot_is_idempotent_per_day(): void
    {
        $h = app(CoverageHistory::class);
        $all = app(CoverageScorer::class)->scoreAll();
        $h->snapshot($all, '2026-11-01');
        $h->snapshot($all, '2026-11-01');
        $this->assertSame(2, DB::table('tbl_coverage_history')->count());

        $this->fillHanoi();
        $h->snapshot(app(CoverageScorer::class)->scoreAll(), '2026-11-02');
        $rec = $h->recent([$this->hn]);
        $this->assertCount(2, $rec[$this->hn]);
        $this->assertGreaterThan($rec[$this->hn][0]['percent'], $rec[$this->hn][1]['percent']);
    }

    public function test_issue_list_finds_the_listed_problems(): void
    {
        $this->fillHanoi();
        DB::table('tbl_foods')->insert(['slug' => 'ns', 'name' => 'Thiếu nguồn', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'CC BY', 'reviewStatus' => 'published']);
        DB::table('tbl_foods')->insert(['slug' => 'ac', 'name' => 'Ảnh thiếu ghi công', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'coverImage' => 'x.webp', 'reviewStatus' => 'published']);
        DB::table('tbl_experiences')->insert(['slug' => 'pg', 'name' => 'Chưa vào Planner', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_events')->insert(['slug' => 'hh', 'name' => 'Lễ hết hạn', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'isLunar' => 0, 'reviewStatus' => 'published']);
        DB::table('tbl_guides')->insert(['slug' => 'cu', 'title' => 'Bài cũ', 'body' => $this->long, 'license' => 'Tự viết', 'updatedOn' => '2020-01-01', 'reviewStatus' => 'published']);

        $c = ContentIssues::countByCode((new ContentIssues())->all());
        foreach (['no_source', 'no_credit', 'planner_gap', 'event_stale', 'guide_stale', 'dest_gap'] as $code) {
            $this->assertGreaterThanOrEqual(1, $c[$code] ?? 0, "thiếu mã $code");
        }
        // Côn Đảo thiếu dữ liệu nền: ảnh bìa, giới thiệu, tháng, khí hậu, lịch trình, tour
        $cd = array_filter((new ContentIssues())->all([$this->cd]), fn($i) => $i['code'] === 'dest_gap');
        $this->assertGreaterThanOrEqual(5, count($cd));
    }

    public function test_command_prints_scores_and_saves_history(): void
    {
        $this->fillHanoi();
        config(['core_destinations.slugs' => ['ha-noi']]);
        $this->artisan('content:coverage', ['--core' => true, '--save' => true])->assertSuccessful()->expectsOutputToContain('Planner-ready 1/1');
        $this->assertSame(1, DB::table('tbl_coverage_history')->where('destinationId', $this->hn)->count());

        config(['core_destinations.slugs' => []]);
        $this->artisan('content:coverage', ['--core' => true])->assertFailed();
    }

    public function test_quality_dashboard_requires_admin_and_shows_scores(): void
    {
        $this->fillHanoi();
        $this->get('/admin/content-quality')->assertRedirect(route('admin.login'));

        $admin = $this->admin();
        $r = $this->withSession(['adminId' => $admin])->get('/admin/content-quality')->assertOk();
        $r->assertSee('Chất lượng nội dung')->assertSee('Hà Nội')->assertSee('Sẵn sàng')->assertSee('Côn Đảo');
        $this->withSession(['adminId' => $admin])->get('/admin/content-quality?destination=con-dao&code=dest_gap')->assertOk()->assertSee('Thiếu ảnh bìa');

        $this->withSession(['adminId' => $admin])->post('/admin/content-quality/snapshot')->assertSessionHas('success');
        $this->assertSame(2, DB::table('tbl_coverage_history')->count());
    }

    public function test_fixing_data_raises_the_score(): void
    {
        $before = app(CoverageScorer::class)->score($this->cd)['percent'];
        DB::table('tbl_destinations')->where('destinationId', $this->cd)->update(['description' => $this->long, 'coverImage' => 'discover/cd.webp', 'imageCredit' => 'Tác giả']);
        $this->assertGreaterThan($before, app(CoverageScorer::class)->score($this->cd)['percent']);
    }

    // ---- Lịch trình ----------------------------------------------------------------------------------------

    private function itinerary(int $days = 2): int
    {
        return DB::table('tbl_itineraries')->insertGetId(['slug' => 'hn-' . $days, 'title' => 'Hà Nội ' . $days . ' ngày', 'days' => $days, 'region' => 'b',
            'summary' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
    }

    private function payload(array $rows): array
    {
        return ['payload' => json_encode($rows)];
    }

    public function test_admin_saves_slot_duration_and_transport_and_gets_warnings(): void
    {
        $it = $this->itinerary(2);
        $exp = DB::table('tbl_experiences')->insertGetId(['slug' => 'tn', 'name' => 'Đi thuyền', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        $admin = $this->admin();

        $rows = [
            ['dayNo' => 1, 'refType' => 'experience', 'refId' => $exp, 'label' => '', 'note' => '', 'slot' => 'morning', 'durationMin' => 120],
            ['dayNo' => 1, 'refType' => 'transport', 'refId' => null, 'label' => 'Taxi về khách sạn', 'note' => '20 phút', 'slot' => 'afternoon', 'durationMin' => 20],
            ['dayNo' => 1, 'refType' => null, 'refId' => null, 'label' => 'Dạo phố cổ', 'note' => '', 'slot' => 'evening', 'durationMin' => 90],
        ];
        $this->withSession(['adminId' => $admin])->post(route('admin.itinerary.items.save', $it), $this->payload($rows))
            ->assertSessionHas('success')->assertSessionHas('warnings');   // ngày 2 chưa có điểm dừng

        $saved = DB::table('tbl_itinerary_items')->where('itineraryId', $it)->orderBy('orderNo')->get();
        $this->assertSame(['experience', 'transport', null], $saved->pluck('refType')->all());
        $this->assertSame(['Đi thuyền', 'Taxi về khách sạn', 'Dạo phố cổ'], $saved->pluck('label')->all());
        $this->assertSame(['morning', 'afternoon', 'evening'], $saved->pluck('slot')->all());
        $this->assertSame([120, 20, 90], $saved->pluck('durationMin')->map(fn($v) => (int) $v)->all());
        $this->assertNull($saved[1]->refId);
    }

    public function test_admin_rejects_bad_slot_minutes_and_transport_ref(): void
    {
        $it = $this->itinerary(1);
        $admin = $this->admin();
        $base = ['dayNo' => 1, 'refType' => null, 'refId' => null, 'label' => 'x', 'note' => '', 'slot' => null, 'durationMin' => null];
        foreach ([['slot' => 'midnight'], ['durationMin' => 2], ['durationMin' => 99999], ['refType' => 'bus', 'refId' => 1]] as $bad) {
            $this->withSession(['adminId' => $admin])->post(route('admin.itinerary.items.save', $it), $this->payload([$bad + $base]))->assertSessionHas('error');
        }
        $this->assertSame(0, DB::table('tbl_itinerary_items')->where('itineraryId', $it)->count());
    }

    public function test_edit_page_loads_with_new_fields(): void
    {
        $it = $this->itinerary(1);
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'transport', 'refId' => null, 'label' => 'Taxi', 'slot' => 'morning', 'durationMin' => 25]);
        $this->withSession(['adminId' => $this->admin()])->get(route('admin.itinerary.items', $it))->assertOk()->assertSee('Sắp theo khung giờ', false)->assertSee('Di chuyển');
    }

    public function test_public_itinerary_groups_by_slot_and_marks_transport(): void
    {
        $it = $this->itinerary(1);
        $exp = DB::table('tbl_experiences')->insertGetId(['slug' => 'tn', 'name' => 'Đi thuyền', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert([
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => null, 'refId' => null, 'label' => 'Ăn tối phố cổ', 'slot' => 'evening', 'durationMin' => 90],
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 2, 'refType' => 'experience', 'refId' => $exp, 'label' => 'Đi thuyền', 'slot' => 'morning', 'durationMin' => 120],
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 3, 'refType' => 'transport', 'refId' => null, 'label' => 'Taxi về khách sạn', 'slot' => 'afternoon', 'durationMin' => 20],
        ]);
        $html = $this->get('/lich-trinh/hn-1')->assertOk()->assertSee('Sáng')->assertSee('Chiều')->assertSee('Tối')->assertSee('120 phút')->getContent();

        $this->assertLessThan(strpos($html, 'Ăn tối phố cổ'), strpos($html, 'Đi thuyền'));      // sáng đứng trước tối dù nhập sau
        $this->assertStringContainsString('is-transport', $html);
        $this->assertStringContainsString('2 điểm dừng', strip_tags($html));                      // di chuyển không tính là điểm dừng
        $this->assertStringContainsString('khoảng 3 giờ 50 phút', strip_tags($html));             // 120 + 20 + 90
    }

    public function test_public_itinerary_without_slots_keeps_editor_order(): void
    {
        $it = $this->itinerary(1);
        DB::table('tbl_itinerary_items')->insert([
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => null, 'refId' => null, 'label' => 'Một', 'slot' => null],
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 2, 'refType' => null, 'refId' => null, 'label' => 'Hai', 'slot' => null],
        ]);
        $r = $this->get('/lich-trinh/hn-1')->assertOk()->assertDontSee('Trong ngày');
        $html = $r->getContent();
        $this->assertLessThan(strpos($html, 'Hai'), strpos($html, 'Một'));
    }
}
