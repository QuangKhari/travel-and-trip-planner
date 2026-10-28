<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 6: tạo chuyến tự động từ form, My Trip (chủ sở hữu, trạng thái), tìm kiếm theo ý định. */
class Week6PlannerUiTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $cd;
    private int $uid;
    private int $other;
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
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U1', 'isActive' => 'y']);
        $this->other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'U2', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    /** Giống Week45PlannerTest::fillHanoi: đưa Hà Nội tới mức Planner-ready. */
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
            DB::table('tbl_foods')->insert(['slug' => "f$i", 'name' => "Món $i", 'destinationId' => $this->hn, 'category' => 'bún phở', 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        }
        for ($i = 0; $i < 4; $i++) {
            DB::table('tbl_experiences')->insert(['slug' => "e$i", 'name' => "Trải nghiệm $i", 'destinationId' => $this->hn, 'category' => 'Văn hóa & biểu diễn', 'description' => $this->long, 'license' => 'Tự viết',
                'difficulty' => 'easy', 'durationMinutes' => 90, 'indoor' => 1, 'priceMin' => 100000, 'priceMax' => 150000, 'reviewStatus' => 'published']);
        }
        DB::table('tbl_events')->insert(['slug' => 'le-hoi', 'name' => 'Lễ hội tháng sáu', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'solarMonth' => 6, 'solarDay' => 15, 'reviewStatus' => 'published']);
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => 'hn-1', 'title' => 'Hà Nội 1 ngày', 'days' => 1, 'summary' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => DB::table('tbl_foods')->value('foodId'), 'label' => 'Món 0']);
    }

    private function form(array $over = []): array
    {
        return array_replace(['destinationId' => $this->hn, 'month' => 6, 'days' => 2, 'groupSize' => 2, 'interests' => ['culture'], 'dislikes' => ['trekking']], $over);
    }

    public function test_generate_creates_saved_trip_and_opens_it(): void
    {
        $this->fillHanoi();
        $res = $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form());
        $trip = DB::table('tbl_trips')->where('userId', $this->uid)->first();
        $this->assertNotNull($trip);
        $res->assertRedirect(route('planner.edit', ['id' => $trip->tripId]));
        $this->assertGreaterThan(0, DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->count());
        $this->withSession($this->s)->get('/len-ke-hoach/' . $trip->tripId)->assertOk();
    }

    public function test_generated_items_are_only_published_existing_entities(): void
    {
        $this->fillHanoi();
        DB::table('tbl_foods')->insert(['slug' => 'nhap', 'name' => 'Món nháp bí mật', 'destinationId' => $this->hn, 'description' => $this->long, 'license' => 'Tự viết', 'reviewStatus' => 'draft']);
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form());
        $trip = DB::table('tbl_trips')->where('userId', $this->uid)->first();
        $this->withSession($this->s)->get('/len-ke-hoach/' . $trip->tripId)->assertOk()->assertDontSee('Món nháp bí mật');
        foreach (DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->where('refType', 'food')->pluck('refId') as $id) {
            $this->assertSame('published', DB::table('tbl_foods')->where('foodId', $id)->value('reviewStatus'));
        }
    }

    public function test_destination_not_planner_ready_is_blocked_on_web(): void
    {
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form(['destinationId' => $this->cd]))->assertSessionHasErrors('generate');
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->fillHanoi();
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form(['month' => 13]))->assertSessionHasErrors('month');
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form(['days' => 99]))->assertSessionHasErrors('days');
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form(['interests' => ['khong-co']]))->assertSessionHasErrors('interests.0');
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->count());
    }

    public function test_generate_requires_login(): void
    {
        $this->fillHanoi();
        $this->post('/len-ke-hoach/tao-tu-dong', $this->form())->assertRedirect();
        $this->assertSame(0, DB::table('tbl_trips')->count());
    }

    public function test_other_user_gets_404_on_trip_and_cannot_change_status_or_delete(): void
    {
        $this->fillHanoi();
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form());
        $id = (int) DB::table('tbl_trips')->where('userId', $this->uid)->value('tripId');
        $them = ['userId' => $this->other, 'username' => 'u2'];

        $this->withSession($them)->get("/len-ke-hoach/$id")->assertNotFound();
        $this->withSession($them)->post("/len-ke-hoach/$id/trang-thai", ['status' => 'done'])->assertNotFound();
        $this->withSession($them)->post("/len-ke-hoach/$id/xoa")->assertNotFound();
        $this->assertSame('planned', DB::table('tbl_trips')->where('tripId', $id)->value('status'));
    }

    public function test_owner_can_change_status_and_invalid_status_is_rejected(): void
    {
        $this->fillHanoi();
        $this->withSession($this->s)->post('/len-ke-hoach/tao-tu-dong', $this->form());
        $id = (int) DB::table('tbl_trips')->where('userId', $this->uid)->value('tripId');
        $this->withSession($this->s)->post("/len-ke-hoach/$id/trang-thai", ['status' => 'done'])->assertRedirect(route('planner'));
        $this->assertSame('done', DB::table('tbl_trips')->where('tripId', $id)->value('status'));
        $this->withSession($this->s)->post("/len-ke-hoach/$id/trang-thai", ['status' => 'hack'])->assertSessionHasErrors('status');
        $this->assertSame('done', DB::table('tbl_trips')->where('tripId', $id)->value('status'));
    }

    public function test_planner_page_prefills_from_natural_sentence(): void
    {
        $this->fillHanoi();
        $html = $this->withSession($this->s)->get('/len-ke-hoach/de-xuat?q=' . urlencode('Hà Nội 3 ngày văn hóa'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="days"[^>]*value="3"/', $html);
        $this->assertMatchesRegularExpression('/value="culture"[^>]*checked/', $html);
    }

    public function test_search_shows_quick_card_and_planner_link_for_natural_query(): void
    {
        $this->fillHanoi();
        $html = $this->get('/tim-kiem?q=' . urlencode('Hà Nội tháng 6 3 ngày'))->assertOk()->assertSee('Trời đẹp')->assertSee('Lập chuyến đi này')->getContent();
        $this->assertStringContainsString('diem-den=ha-noi', $html);
        $this->assertStringContainsString('ngay=3', $html);
    }

    public function test_search_long_sentence_still_returns_results(): void
    {
        $this->fillHanoi();
        $this->get('/tim-kiem?q=' . urlencode('cho tôi biết Hà Nội có gì'))->assertOk()->assertSee('Hà Nội');
    }
}
