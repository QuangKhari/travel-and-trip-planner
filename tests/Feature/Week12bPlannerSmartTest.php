<?php

namespace Tests\Feature;

use App\Services\Planner\ContentRequests;
use App\Services\Planner\TourFit;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\UnknownPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tuần 12: chọn điểm đến theo tháng/cảm hứng, phương án mưa, tour khớp chuyến, xử lý nơi chưa có dữ liệu. */
class Week12bPlannerSmartTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $hue;
    private int $uid;
    private int $other;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = $this->dest('ha-noi', 'Hà Nội', 21.0285, 105.8542);
        $this->hue = $this->dest('hue', 'Huế', 16.4637, 107.5909);
        $this->uid = $this->user('u1');
        $this->other = $this->user('u2');
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function dest(string $slug, string $name, float $lat, float $lng): int
    {
        return DB::table('tbl_destinations')->insertGetId(['slug' => $slug, 'name' => $name, 'province' => $name, 'domain' => 'b',
            'description' => 'mô tả ' . $name, 'latitude' => $lat, 'longitude' => $lng, 'isActive' => 1]);
    }

    private function user(string $n): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    private function indoorExp(string $slug, string $name, int $dest, int $indoor = 1): int
    {
        return DB::table('tbl_experiences')->insertGetId(['slug' => $slug, 'name' => $name, 'destinationId' => $dest, 'category' => 'Văn hóa & biểu diễn',
            'description' => str_repeat('Mô tả đủ dài. ', 6), 'license' => 'Tự viết', 'difficulty' => 'easy', 'durationMinutes' => 90, 'indoor' => $indoor,
            'priceMin' => 100000, 'priceMax' => 150000, 'reviewStatus' => 'published']);
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'destinationId' => $this->hn]);
    }

    private function body(array $items = [], array $over = []): array
    {
        return array_replace(['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'items' => $items], $over);
    }

    private function tour(string $title, string $start, int $qty = 10): int
    {
        $cols = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('tbl_tours'));
        $row = ['title' => $title, 'destinationId' => $this->hn, 'startDate' => $start, 'endDate' => date('Y-m-d', strtotime($start . ' +2 days')),
            'priceAdult' => 2000000, 'priceChild' => 1000000, 'quantity' => $qty, 'availability' => 1, 'time' => '3 ngày', 'description' => 'd', 'domain' => 'b', 'destination' => 'Hà Nội'];

        return DB::table('tbl_tours')->insertGetId(array_intersect_key($row, $cols));
    }

    // ---- 5. bắt đầu từ cảm hứng -----------------------------------------------------------------------------

    public function test_discover_ranks_by_month_and_inspiration_with_reasons(): void
    {
        $ins = DB::table('tbl_inspirations')->insertGetId(['slug' => 'van-hoa', 'name' => 'Văn hóa', 'tagline' => 'Di sản', 'headline' => 'h', 'icon' => 'fa-landmark', 'displayOrder' => 1, 'isActive' => 1]);
        DB::table('tbl_destination_inspiration')->insert([['destinationId' => $this->hn, 'inspirationId' => $ins, 'score' => 95], ['destinationId' => $this->hue, 'inspirationId' => $ins, 'score' => 30]]);
        DB::table('tbl_destination_months')->insert([['destinationId' => $this->hn, 'month' => 6, 'level' => 'best', 'note' => 'Trời đẹp', 'source' => 'manual'],
            ['destinationId' => $this->hue, 'month' => 6, 'level' => 'avoid', 'note' => 'Bão', 'source' => 'manual']]);

        $r = $this->withSession($this->s)->get('/len-ke-hoach/chon-diem-den?thang=6&cam-hung[]=van-hoa&nguoi=2&ngan-sach=10000000')->assertOk();
        $r->assertSee('Hà Nội')->assertSee('Huế');
        $this->assertLessThan(mb_strpos($r->getContent(), 'Huế', mb_strpos($r->getContent(), 'Hợp nhất')), mb_strpos($r->getContent(), 'Hà Nội', mb_strpos($r->getContent(), 'Hợp nhất')), 'tháng nên tránh phải đứng sau');
    }

    public function test_discover_without_month_shows_form_only(): void
    {
        $this->withSession($this->s)->get('/len-ke-hoach/chon-diem-den')->assertOk()->assertSee('Chưa biết đi đâu?')->assertDontSee('Hợp nhất cho tháng');
    }

    public function test_discover_requires_login(): void
    {
        $this->get('/len-ke-hoach/chon-diem-den')->assertRedirect();
    }

    // ---- 2. phương án mưa -----------------------------------------------------------------------------------

    public function test_rain_suggest_returns_only_published_indoor_and_skips_chosen(): void
    {
        $a = $this->indoorExp('bao-tang', 'Bảo tàng nước', $this->hn);
        $this->indoorExp('leo-nui', 'Leo núi', $this->hn, 0);
        $c = $this->indoorExp('roi-nuoc', 'Múa rối nước', $this->hn);
        $this->indoorExp('o-hue', 'Trong nhà ở Huế', $this->hue);
        $id = $this->trip();

        $names = array_column($this->withSession($this->s)->getJson("/len-ke-hoach/$id/phuong-an-mua?day=1")->assertOk()->json('items'), 'name');
        $this->assertContains('Bảo tàng nước', $names);
        $this->assertContains('Múa rối nước', $names);
        $this->assertNotContains('Leo núi', $names);
        $this->assertNotContains('Trong nhà ở Huế', $names);

        $names2 = array_column($this->withSession($this->s)->getJson("/len-ke-hoach/$id/phuong-an-mua?day=1&have[]=experience:$a")->json('items'), 'name');
        $this->assertNotContains('Bảo tàng nước', $names2);
        $this->assertContains('Múa rối nước', $names2);
    }

    public function test_rain_suggest_rejects_other_users_trip_and_bad_day(): void
    {
        $id = $this->trip();
        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->getJson("/len-ke-hoach/$id/phuong-an-mua?day=1")->assertNotFound();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/phuong-an-mua?day=99")->assertStatus(422);
    }

    public function test_rain_plan_is_saved_sanitized_kept_when_omitted_and_shown_when_shared(): void
    {
        $e = $this->indoorExp('bao-tang', 'Bảo tàng nước', $this->hn);
        $id = $this->trip();
        $plan = ['1' => [['type' => 'experience', 'refId' => $e], ['type' => 'experience', 'refId' => $e], ['type' => 'hack', 'refId' => 1]], '9' => [['type' => 'experience', 'refId' => $e]]];
        $j = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([], ['rainPlan' => $plan]))->assertOk()->json();
        $this->assertArrayHasKey('rain', $j);
        $stored = json_decode(DB::table('tbl_trips')->where('tripId', $id)->value('rainPlan'), true);
        $this->assertSame([1 => [['type' => 'experience', 'refId' => $e]]], array_combine(array_map('intval', array_keys($stored)), array_values($stored)));

        // không gửi rainPlan = giữ nguyên
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertOk();
        $this->assertNotNull(DB::table('tbl_trips')->where('tripId', $id)->value('rainPlan'));

        $url = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/chia-se", ['on' => 1])->assertOk()->json('url');
        $this->get($url)->assertOk()->assertSee('Nếu trời mưa')->assertSee('Bảo tàng nước');

        // gửi rỗng = xóa
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([], ['rainPlan' => []]))->assertOk();
        $this->assertNull(DB::table('tbl_trips')->where('tripId', $id)->value('rainPlan'));
    }

    // ---- 4. nối với tour -------------------------------------------------------------------------------------

    public function test_tour_fit_scores_date_days_seats_and_budget(): void
    {
        $cfg = ['max_day_gap' => 14];
        $trip = ['startDate' => '2027-06-10', 'month' => 6, 'days' => 3, 'groupSize' => 2, 'budget' => 5000000.0];
        $exact = TourFit::score($trip, ['startDate' => '2027-06-10', 'endDate' => '2027-06-12', 'time' => '3 ngày', 'quantity' => 5, 'priceAdult' => 2000000.0], $cfg);
        $far = TourFit::score($trip, ['startDate' => '2027-08-10', 'endDate' => '2027-08-12', 'time' => '3 ngày', 'quantity' => 1, 'priceAdult' => 4000000.0], $cfg);
        $this->assertGreaterThan($far['score'], $exact['score']);
        $this->assertSame([], $exact['warns']);
        $this->assertNotEmpty($far['warns']);
        $this->assertSame(3, TourFit::tourDays('3 ngày 2 đêm'));
        $this->assertNull(TourFit::tourDays('tuỳ chọn'));
    }

    public function test_from_tour_creates_trip_with_tour_and_rejects_closed_tour(): void
    {
        $ok = $this->tour('Tour Hà Nội', date('Y-m-d', strtotime('+30 days')));
        $r = $this->withSession($this->s)->post("/tour/$ok/len-ke-hoach", ['groupSize' => 3]);
        $tripId = (int) DB::table('tbl_trips')->where('userId', $this->uid)->where('source', 'tour')->value('tripId');
        $this->assertGreaterThan(0, $tripId);
        $r->assertRedirect("/len-ke-hoach/$tripId");
        $this->assertSame(3, (int) DB::table('tbl_trips')->where('tripId', $tripId)->value('days'));
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $tripId, 'refType' => 'tour', 'refId' => $ok])->exists());

        $past = $this->tour('Tour đã đi', date('Y-m-d', strtotime('-5 days')));
        $this->withSession($this->s)->post("/tour/$past/len-ke-hoach", [])->assertRedirect(route('tour-detail', ['id' => $past]))->assertSessionHas('planner_error');
    }

    public function test_edit_page_lists_matching_tours_with_reasons(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $this->tour('Tour hợp ngày', $start);
        $id = $this->trip(['startDate' => $start]);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Tour Travela hợp chuyến này')->assertSee('Tour hợp ngày')->assertSee('Khởi hành đúng ngày bạn chọn');
    }

    // ---- nơi chưa có dữ liệu ---------------------------------------------------------------------------------

    public function test_fuzzy_suggests_close_destination_names_only(): void
    {
        $rows = [['slug' => 'ha-noi', 'name' => 'Hà Nội', 'folded' => 'ha noi'], ['slug' => 'hue', 'name' => 'Huế', 'folded' => 'hue']];
        $this->assertSame('ha-noi', UnknownPlace::fuzzy('ha noy', $rows)[0]['slug']);
        $this->assertSame([], UnknownPlace::fuzzy('xyz abc', $rows));
        $this->assertSame([], UnknownPlace::fuzzy('ha noi', $rows), 'khớp chính xác không phải gõ sai');
    }

    public function test_nearest_respects_radius_and_orders_by_distance(): void
    {
        $d = [['id' => 1, 'slug' => 'ha-noi', 'name' => 'Hà Nội', 'lat' => 21.0285, 'lng' => 105.8542], ['id' => 2, 'slug' => 'hue', 'name' => 'Huế', 'lat' => 16.4637, 'lng' => 107.5909]];
        $n = UnknownPlace::nearest(20.9, 105.9, $d, 3, 150);
        $this->assertCount(1, $n);
        $this->assertSame('ha-noi', $n[0]['slug']);
        $this->assertSame([], UnknownPlace::nearest(10.0, 100.0, $d, 3, 150));
    }

    public function test_unknown_resolve_records_demand_once_per_call_and_counts_hits(): void
    {
        Http::fake();
        $svc = app(UnknownPlace::class);
        $r = $svc->resolve('Ha Noy', false);
        $this->assertSame('ha-noi', $r['fuzzy'][0]['slug']);
        $svc->resolve('Ha Noy', false);
        $row = DB::table('tbl_content_requests')->first();
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame('destination', $row->kind);
        $this->assertSame(1, DB::table('tbl_content_requests')->count());
    }

    public function test_content_requests_ignore_noise_and_can_be_resolved(): void
    {
        $log = app(ContentRequests::class);
        $this->assertFalse($log->record('ab'));
        $this->assertFalse($log->record('12345'));
        $this->assertTrue($log->record('Quán Gió'));
        $this->assertTrue($log->record('quan gio'));
        $this->assertSame(1, DB::table('tbl_content_requests')->count());
        $this->assertSame(2, (int) DB::table('tbl_content_requests')->value('hits'));
        $id = (int) DB::table('tbl_content_requests')->value('requestId');
        $log->resolve($id);
        $this->assertCount(0, $log->top());
        $this->assertCount(1, $log->top(true));
        $log->record('Quán Gió');   // có người tìm lại: mở lại
        $this->assertCount(1, $log->top());
    }

    public function test_saving_external_place_logs_demand_once_not_on_resave(): void
    {
        $id = $this->trip();
        $ext = ['type' => 'note', 'label' => 'Quán Cà Phê Gió', 'lat' => 21.03, 'lng' => 105.85, 'source' => 'search', 'day' => 1];
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([$ext]))->assertOk();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([$ext]))->assertOk();
        $this->assertSame(1, (int) DB::table('tbl_content_requests')->value('hits'));
        // mục tự gõ (không từ tìm kiếm) không bị ghi
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([$ext, ['type' => 'note', 'label' => 'Nhà bà ngoại', 'day' => 1]]))->assertOk();
        $this->assertSame(1, DB::table('tbl_content_requests')->count());
    }

    public function test_suggest_page_offers_way_forward_for_unknown_place(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $r = $this->withSession($this->s)->get('/len-ke-hoach/de-xuat?q=' . urlencode('Ha Noy 3 ngay'))->assertOk();
        // dù nhận diện được hay không, trang phải dùng được và không lỗi
        $r->assertSee('Để Travela đề xuất lịch trình');
    }

    // ---- save trả về dữ liệu "vì sao hợp" -----------------------------------------------------------------------

    public function test_save_response_carries_fit_map_and_rain_context(): void
    {
        $e = $this->indoorExp('bao-tang', 'Bảo tàng nước', $this->hn);
        $id = $this->trip();
        $j = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'experience', 'refId' => $e, 'day' => 1]]))->assertOk()->json();
        $this->assertArrayHasKey('fit', $j);
        $this->assertArrayHasKey('rain', $j);
        $this->assertIsArray($j['fit']);
    }
}
