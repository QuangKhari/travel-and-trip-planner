<?php

namespace Tests\Feature;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\PublicCache;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerService;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 4-5: trang điểm đến theo tháng, lịch trình → chuyến đi, cổng Planner-ready, Planner tự đề xuất. */
class Week45PlannerTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $cd;
    private int $uid;
    private array $s;
    private string $long;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['core_destinations.slugs' => []]);
        $this->long = str_repeat('Mô tả đủ dài cho nội dung kiểm thử. ', 4);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => $this->long, 'latitude' => 21.03, 'longitude' => 105.85, 'coverImage' => 'discover/a.webp', 'imageCredit' => 'Tác giả', 'isActive' => 1]);
        $this->cd = DB::table('tbl_destinations')->insertGetId(['slug' => 'con-dao', 'name' => 'Côn Đảo', 'province' => 'BRVT', 'domain' => 'n',
            'description' => 'ngắn', 'latitude' => 8.68, 'longitude' => 106.6, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    /** Đưa Hà Nội tới mức Planner-ready và đủ ứng viên để Planner xếp 2 ngày. */
    private function fillHanoi(): void
    {
        for ($m = 1; $m <= 12; $m++) {
            DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => $m, 'level' => $m === 6 ? 'best' : 'ok', 'note' => $m === 6 ? 'Trời đẹp' : null, 'source' => 'manual']);
            DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => $m, 'tMax' => 30, 'tMin' => 22, 'rainMm' => 100, 'rainyDays' => 8, 'years' => 10, 'periodFrom' => 2014, 'periodTo' => 2024]);
        }
        for ($i = 0; $i < 24; $i++) {
            DB::table('tbl_external_places')->insert(['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => "node/$i", 'name' => "Địa điểm $i",
                'category' => $i < 6 ? 'eat' : 'see', 'kind' => $i < 6 ? 'restaurant' : ($i % 2 ? 'museum' : 'attraction'),
                'latitude' => 21.0 + $i / 500, 'longitude' => 105.8 + $i / 500, 'rank' => $i, 'isMustTry' => $i === 7 ? 1 : 0, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        }
        for ($i = 0; $i < 6; $i++) {
            DB::table('tbl_foods')->insert(['slug' => "f$i", 'name' => "Món $i", 'destinationId' => $this->hn, 'category' => 'bún phở', 'description' => $this->long, 'license' => 'Tự viết',
                'monthFrom' => $i === 0 ? 6 : null, 'monthTo' => $i === 0 ? 8 : null, 'reviewStatus' => 'published']);
        }
        for ($i = 0; $i < 4; $i++) {
            DB::table('tbl_experiences')->insert(['slug' => "e$i", 'name' => "Trải nghiệm $i", 'destinationId' => $this->hn, 'category' => 'Văn hóa & biểu diễn', 'description' => $this->long, 'license' => 'Tự viết',
                'difficulty' => 'easy', 'durationMinutes' => 90, 'indoor' => 1, 'priceMin' => 100000, 'priceMax' => 150000, 'reviewStatus' => 'published']);
        }
        DB::table('tbl_events')->insert(['slug' => 'le-hoi', 'name' => 'Lễ hội tháng sáu', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết',
            'solarMonth' => 6, 'solarDay' => 15, 'reviewStatus' => 'published']);
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => 'hn-1', 'title' => 'Hà Nội 1 ngày', 'days' => 1, 'summary' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => DB::table('tbl_foods')->value('foodId'), 'label' => 'Món 0']);
    }

    // ---- Trang theo tháng ----------------------------------------------------------------------------

    public function test_month_page_shows_season_events_and_only_matching_published_content(): void
    {
        $this->fillHanoi();
        DB::table('tbl_foods')->insert(['slug' => 'nhap', 'name' => 'Món nháp bí mật', 'destinationId' => $this->hn, 'monthFrom' => 6, 'monthTo' => 6, 'reviewStatus' => 'draft']);
        DB::table('tbl_foods')->insert(['slug' => 'mua-dong', 'name' => 'Món mùa đông', 'destinationId' => $this->hn, 'monthFrom' => 11, 'monthTo' => 12, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);

        $html = $this->get('/diem-den/ha-noi/thang-6')->assertOk()->assertSee('Hà Nội')->assertSee('Trời đẹp')->assertSee('Lễ hội tháng sáu')->assertSee('Món 0')->getContent();
        $this->assertStringNotContainsString('Món nháp bí mật', $html);
        $this->assertStringNotContainsString('Món mùa đông', $html);
    }

    public function test_month_page_rejects_bad_month_and_unknown_destination(): void
    {
        $this->get('/diem-den/ha-noi/thang-13')->assertNotFound();
        $this->get('/diem-den/ha-noi/thang-0')->assertNotFound();
        $this->get('/diem-den/khong-co/thang-6')->assertNotFound();
        DB::table('tbl_destinations')->where('destinationId', $this->cd)->update(['isActive' => 0]);
        $this->get('/diem-den/con-dao/thang-6')->assertNotFound();
    }

    public function test_month_page_without_data_says_so_instead_of_inventing(): void
    {
        $html = $this->get('/diem-den/con-dao/thang-3')->assertOk()->getContent();
        $this->assertStringContainsString('Chưa có đánh giá mùa', $html);
        $this->assertStringContainsString('Chưa có dữ liệu khí hậu', $html);
    }

    public function test_brief_is_cached_and_flush_refreshes_it(): void
    {
        $this->fillHanoi();
        $b = app(DestinationMonthBrief::class);
        $this->assertSame('Trời đẹp', $b->forSlug('ha-noi', 6, 2027)['rating']['note']);

        DB::table('tbl_destination_months')->where(['destinationId' => $this->hn, 'month' => 6])->update(['note' => 'Ghi chú mới']);
        $this->assertSame('Trời đẹp', $b->forSlug('ha-noi', 6, 2027)['rating']['note'], 'còn trong cache');
        PublicCache::flush();
        $this->assertSame('Ghi chú mới', $b->forSlug('ha-noi', 6, 2027)['rating']['note'], 'PublicCache::flush đổi thế hệ cache');
    }

    public function test_brief_in_season_handles_wrap_around_and_unknown(): void
    {
        $this->assertTrue(DestinationMonthBrief::inSeason(11, 2, 1));
        $this->assertFalse(DestinationMonthBrief::inSeason(11, 2, 6));
        $this->assertNull(DestinationMonthBrief::inSeason(11, null, 6));
        $this->assertSame(2027, DestinationMonthBrief::defaultYear(3, new \DateTimeImmutable('2026-10-27')));
        $this->assertSame(2026, DestinationMonthBrief::defaultYear(11, new \DateTimeImmutable('2026-10-27')));
    }

    public function test_sitemap_lists_month_pages_only_for_rated_months(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 6, 'level' => 'best', 'source' => 'manual']);
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/diem-den/ha-noi/thang-6', $xml);
        $this->assertStringNotContainsString('/diem-den/ha-noi/thang-7', $xml);
    }

    public function test_destination_page_links_to_month_pages(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 6, 'level' => 'best', 'source' => 'manual']);
        $this->get('/diem-den/ha-noi')->assertOk()->assertSee('/diem-den/ha-noi/thang-6', false);
    }

    // ---- Lịch trình mẫu → chuyến đi -------------------------------------------------------------------

    private function itineraryWith(array $rows, string $slug = 'hn-2n', int $days = 2): int
    {
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => $slug, 'title' => 'Hà Nội ' . $days . ' ngày', 'days' => $days, 'summary' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        foreach ($rows as $i => $r) {
            DB::table('tbl_itinerary_items')->insert($r + ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => $i + 1, 'label' => 'x']);
        }

        return $it;
    }

    public function test_itinerary_to_trip_requires_login(): void
    {
        $this->fillHanoi();
        $this->post('/lich-trinh/hn-1/dung-lam-chuyen-di')->assertRedirect();
        $this->assertSame(0, DB::table('tbl_trips')->count());
    }

    public function test_itinerary_to_trip_copies_items_slots_and_skips_transport(): void
    {
        $this->fillHanoi();
        $food = (int) DB::table('tbl_foods')->orderBy('foodId')->value('foodId');
        $place = (int) DB::table('tbl_external_places')->orderBy('placeId')->value('placeId');
        $exp = (int) DB::table('tbl_experiences')->orderBy('experienceId')->value('experienceId');
        $this->itineraryWith([
            ['refType' => 'place', 'refId' => $place, 'slot' => 'morning', 'durationMin' => 60],
            ['refType' => 'transport', 'refId' => null, 'label' => 'Taxi'],
            ['refType' => 'food', 'refId' => $food, 'slot' => 'noon'],
            ['refType' => 'experience', 'refId' => $exp, 'dayNo' => 2, 'slot' => 'afternoon'],
            ['refType' => null, 'refId' => null, 'label' => 'Tự do', 'dayNo' => 2],
        ]);

        $r = $this->withSession($this->s)->post('/lich-trinh/hn-2n/dung-lam-chuyen-di');
        $trip = DB::table('tbl_trips')->first();
        $r->assertRedirect(route('planner.edit', ['id' => $trip->tripId]));
        $this->assertSame($this->uid, (int) $trip->userId);
        $this->assertSame('trip', $trip->kind);
        $this->assertSame($this->hn, (int) $trip->destinationId);
        $this->assertSame('itinerary', $trip->source);
        $this->assertSame(2, (int) $trip->days);

        $items = DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->orderBy('dayNo')->orderBy('orderNo')->get();
        $this->assertCount(3, $items, 'transport và mục chỉ có nhãn bị bỏ qua');
        $this->assertSame(['place', 'food', 'experience'], $items->pluck('refType')->all());
        $this->assertSame(['morning', 'noon', 'afternoon'], $items->pluck('slot')->all());
        $this->assertSame([0, 1, 0], $items->pluck('orderNo')->map(fn($v) => (int) $v)->all());

        $this->withSession($this->s)->get(route('planner.edit', ['id' => $trip->tripId]))->assertOk()->assertSee('Địa điểm 0')->assertSee('Đã tạo chuyến đi từ lịch trình mẫu');
    }

    public function test_itinerary_across_two_destinations_is_refused(): void
    {
        $this->fillHanoi();
        $f1 = (int) DB::table('tbl_foods')->value('foodId');
        $f2 = DB::table('tbl_foods')->insertGetId(['slug' => 'cd', 'name' => 'Món Côn Đảo', 'destinationId' => $this->cd, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        $this->itineraryWith([['refType' => 'food', 'refId' => $f1], ['refType' => 'food', 'refId' => $f2]], 'lien-tinh');

        $this->withSession($this->s)->post('/lich-trinh/lien-tinh/dung-lam-chuyen-di')->assertRedirect(route('itineraries.show', ['slug' => 'lien-tinh']))->assertSessionHas('planner_error');
        $this->assertSame(0, DB::table('tbl_trips')->count());
    }

    public function test_itinerary_page_shows_use_as_trip_button_only_when_possible(): void
    {
        $this->fillHanoi();
        $this->get('/lich-trinh/hn-1')->assertOk()->assertSee('Đăng nhập để dùng làm chuyến đi của tôi');
        $this->withSession($this->s)->get('/lich-trinh/hn-1')->assertOk()->assertSee('Dùng làm chuyến đi của tôi')->assertSee('dung-lam-chuyen-di', false);
    }

    // ---- Cổng Planner-ready + Planner -----------------------------------------------------------------

    public function test_gate_blocks_incomplete_destination_and_passes_complete_one(): void
    {
        $this->fillHanoi();
        $gate = app(PlannerGate::class);
        $this->assertTrue($gate->isReady($this->hn), implode('; ', $gate->status($this->hn)['missing']));
        $bad = $gate->status($this->cd);
        $this->assertFalse($bad['ready']);
        $this->assertNotEmpty($bad['missing']);
        $this->assertFalse($gate->status(999999)['known']);

        $by = array_column($gate->coreStatus(), 'ready', 'slug');
        $this->assertTrue($by['ha-noi']);
        $this->assertFalse($by['con-dao']);
    }

    public function test_service_refuses_not_ready_destination_but_force_works(): void
    {
        $svc = app(PlannerService::class);
        $r = $svc->generate(['slug' => 'con-dao', 'month' => 6, 'days' => 2]);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_ready', $r['code']);
        $this->assertNotEmpty($r['missing']);

        $forced = $svc->generate(['slug' => 'con-dao', 'month' => 6, 'days' => 2], true);
        $this->assertTrue($forced['ok']);
        $this->assertContains('Không đủ dữ liệu đã duyệt để đề xuất lịch trình cho lựa chọn này.', $forced['plan']['warnings']);
    }

    public function test_service_validates_inputs(): void
    {
        $svc = app(PlannerService::class);
        $this->assertSame('no_destination', $svc->generate(['slug' => 'khong-co', 'month' => 6])['code']);
        $this->assertSame('bad_month', $svc->generate(['slug' => 'ha-noi', 'month' => 13])['code']);
    }

    public function test_service_generates_plan_with_reasons_slots_and_separate_tours(): void
    {
        $this->fillHanoi();
        DB::table('tbl_tours')->insert($this->tour('Tour Hà Nội', date('Y') + 1 . '-06-10'));
        // trải nghiệm thiếu độ khó/thời lượng: bị loại khỏi Planner và ghi rõ lý do
        DB::table('tbl_experiences')->insert(['slug' => 'thieu', 'name' => 'Thiếu dữ liệu', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);

        $r = app(PlannerService::class)->generate(['slug' => 'ha-noi', 'month' => 6, 'days' => 2, 'groupSize' => 2, 'interests' => ['culture'], 'startDate' => date('Y') + 1 . '-06-10']);
        $this->assertTrue($r['ok'], json_encode($r));
        $plan = $r['plan'];
        $this->assertCount(2, $plan['days']);
        $keys = [];
        foreach ($plan['days'] as $d) {
            $this->assertNotEmpty($d['items']);
            foreach ($d['items'] as $i) {
                $keys[] = $i['key'];
                $this->assertContains($i['slot'], ['morning', 'noon', 'afternoon', 'evening']);
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertContains('planner_incomplete', array_column($plan['filterLog'], 'code'));
        $this->assertNotContains('experience:' . DB::table('tbl_experiences')->where('slug', 'thieu')->value('experienceId'), $keys);
        $this->assertCount(1, $r['tours']);
        $this->assertSame(2 * $r['tours'][0]['priceAdult'], $r['tours'][0]['forGroup']);
        $this->assertNotNull($r['verdict']);
        $this->assertContains($plan['budget']['status'], ['UNKNOWN', 'PARTIAL', 'FULL']);
        $this->assertStringNotContainsString('vừa ngân sách', $plan['budget']['message']);
    }

    public function test_service_save_creates_trip_that_opens_in_planner_with_reasons(): void
    {
        $this->fillHanoi();
        $svc = app(PlannerService::class);
        $r = $svc->generate(['slug' => 'ha-noi', 'month' => 6, 'days' => 2, 'groupSize' => 2, 'interests' => ['culture']]);
        $id = $svc->save($this->uid, $r);

        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $this->assertSame('planner', $trip->source);
        $this->assertSame(6, (int) $trip->month);
        $this->assertSame(['culture'], json_decode($trip->interests, true));
        $n = DB::table('tbl_trip_items')->where('tripId', $id)->count();
        $this->assertSame($r['plan']['stats']['selected'], $n);
        $this->assertGreaterThan(0, DB::table('tbl_trip_items')->where('tripId', $id)->whereNotNull('reasons')->count());
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->whereNull('slot')->count());

        $this->withSession($this->s)->get(route('planner.edit', ['id' => $id]))->assertOk()->assertSee('Hợp sở thích: Văn hóa, lịch sử', false);   // lý do chọn nằm trong dữ liệu #tpData cho planner.js
    }

    public function test_manual_save_keeps_slot_and_reasons(): void
    {
        $this->fillHanoi();
        $svc = app(PlannerService::class);
        $id = $svc->save($this->uid, $svc->generate(['slug' => 'ha-noi', 'month' => 6, 'days' => 1, 'interests' => ['culture']]));
        $trip = app(TripPlanner::class)->owned($this->uid, $id);
        $items = array_map([TripPlanner::class, 'itemForClient'], app(TripPlanner::class)->tripItems($id));
        $this->assertNotEmpty(array_merge(...array_column($items, 'reasons')), 'chuyến do Planner tạo có lý do chọn');

        $payload = ['title' => $trip->title, 'startDate' => null, 'days' => 1, 'groupSize' => 2, 'budget' => null,
            'items' => array_map(fn($i) => ['type' => $i['type'], 'refId' => $i['refId'], 'day' => $i['day'], 'note' => $i['note'], 'cost' => $i['cost'], 'slot' => $i['slot'], 'reasons' => $i['reasons']], $items)];
        $this->withSession($this->s)->postJson(route('planner.save', ['id' => $id]), $payload)->assertOk();
        $after = DB::table('tbl_trip_items')->where('tripId', $id)->get();
        $this->assertCount(count($items), $after);
        $this->assertSame(0, $after->whereNull('slot')->count(), 'lưu tay không làm mất khung giờ');
        $this->assertGreaterThan(0, $after->whereNotNull('reasons')->count(), 'lưu tay không làm mất lý do chọn');
    }

    public function test_planner_save_rejects_unknown_slot(): void
    {
        $this->fillHanoi();
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'T', 'startDate' => null, 'days' => 1, 'groupSize' => 1, 'budget' => null]);
        $this->withSession($this->s)->postJson(route('planner.save', ['id' => $id]), ['title' => 'T', 'days' => 1, 'groupSize' => 1,
            'items' => [['type' => 'place', 'refId' => 1, 'day' => 1, 'slot' => 'midnight']]])->assertStatus(422);
    }

    public function test_artisan_command_prints_gate_and_plan(): void
    {
        $this->fillHanoi();
        $this->assertSame(0, Artisan::call('planner:generate', ['--gate' => true]));
        $this->assertStringContainsString('SẴN SÀNG', Artisan::output());

        $this->assertSame(0, Artisan::call('planner:generate', ['slug' => 'ha-noi', '--month' => 6, '--days' => 2, '--interests' => 'culture']));
        $out = Artisan::output();
        $this->assertStringContainsString('Ngày 1', $out);
        $this->assertStringContainsString('Ngân sách', $out);

        $this->assertSame(1, Artisan::call('planner:generate', ['slug' => 'con-dao', '--month' => 6]));
        $this->assertStringContainsString('chưa đủ dữ liệu', Artisan::output());
    }

    public function test_home_preview_uses_month_brief_and_links_to_month_page(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 10, 'level' => 'best', 'note' => 'Lúa chín', 'source' => 'manual']);
        $j = $this->getJson('/api/v1/planner-preview?diem-den=ha-noi&so-ngay=3&thang=10')->assertOk()->json();
        $this->assertSame('best', $j['verdict']['level']);
        $this->assertStringContainsString('Lúa chín', $j['verdict']['text']);
        $this->assertStringContainsString('/diem-den/ha-noi/thang-10', $j['verdict']['url']);
    }

    private function tour(string $title, string $start): array
    {
        $cols = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('tbl_tours'));
        $row = ['title' => $title, 'destinationId' => $this->hn, 'startDate' => $start, 'endDate' => date('Y-m-d', strtotime($start . ' +2 days')),
            'priceAdult' => 2000000, 'priceChild' => 1000000, 'quantity' => 10, 'availability' => 1, 'time' => '3 ngày', 'description' => 'd', 'domain' => 'b', 'destination' => 'Hà Nội'];

        return array_intersect_key($row, $cols);
    }
}
