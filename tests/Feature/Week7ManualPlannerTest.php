<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Trip Planner tự lập: tìm nội dung để thêm, mục tự thêm (ghi chú / di chuyển), lưu không tải lại, điểm đến chính. */
class Week7ManualPlannerTest extends TestCase
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

    private function user(string $name): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $name, 'email' => $name . '@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    private function food(string $slug, string $name, int $dest, string $status = 'published'): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => $slug, 'name' => $name, 'destinationId' => $dest, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món ' . $name, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function body(array $items, array $over = []): array
    {
        return array_replace(['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'items' => $items], $over);
    }

    // ---- tìm nội dung để thêm --------------------------------------------------------------------------

    public function test_search_returns_only_published_and_respects_destination_filter(): void
    {
        $this->food('pho-bo', 'Phở bò Hà Nội', $this->hn);
        $this->food('pho-nhap', 'Phở bản nháp', $this->hn, 'draft');
        $this->food('pho-hue', 'Phở Huế', $this->hue);
        $id = $this->trip();

        $all = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?q=" . urlencode('Phở') . '&type=food')->assertOk()->json('results');
        $names = array_column($all, 'name');
        $this->assertContains('Phở bò Hà Nội', $names);
        $this->assertContains('Phở Huế', $names);
        $this->assertNotContains('Phở bản nháp', $names);

        $scoped = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?q=" . urlencode('Phở') . "&type=food&dest={$this->hn}")->assertOk()->json('results');
        $this->assertSame(['Phở bò Hà Nội'], array_column($scoped, 'name'));
        $this->assertSame('food', $scoped[0]['type']);
        $this->assertSame($this->hn, $scoped[0]['destinationId']);
    }

    public function test_search_finds_destinations_and_only_upcoming_tours_with_seats(): void
    {
        $mk = fn(string $t, int $ahead, int $qty) => DB::table('tbl_tours')->insertGetId(['title' => $t, 'time' => '3 ngày', 'description' => '<p>x</p>', 'quantity' => $qty,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Điểm đến', 'destinationId' => $this->hn, 'domain' => 'b', 'availability' => 1,
            'startDate' => now()->addDays($ahead)->toDateString(), 'endDate' => now()->addDays($ahead + 2)->toDateString()]);
        $mk('Tour Hà Nội sắp đi', 10, 5);
        $mk('Tour Hà Nội đã qua', -5, 5);
        $mk('Tour Hà Nội hết chỗ', 10, 0);
        $id = $this->trip(['groupSize' => 3]);

        $tours = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?type=tour")->assertOk()->json('results');
        $this->assertSame(['Tour Hà Nội sắp đi'], array_column($tours, 'name'));
        $this->assertEquals(3000000, $tours[0]['cost']);   // giá người lớn x số người của chuyến

        $d = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?type=destination&q=" . urlencode('Huế'))->assertOk()->json('results');
        $this->assertSame(['Huế'], array_column($d, 'name'));
    }

    public function test_search_treats_percent_and_underscore_as_plain_text(): void
    {
        $this->food('a', 'Món ngon', $this->hn);
        $id = $this->trip();
        $r = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?type=food&q=" . urlencode('%'))->assertOk()->json('results');
        $this->assertSame([], $r);   // "%" không được hiểu là ký tự thay thế
    }

    public function test_search_is_owner_only_and_validates_input(): void
    {
        $id = $this->trip();
        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->getJson("/len-ke-hoach/$id/tim?q=a")->assertNotFound();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?type=hack")->assertStatus(422);
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim?q=" . str_repeat('a', 200))->assertStatus(422);
        $this->flushSession();   // withSession() giữ phiên cho các request sau trong cùng một test; xóa đi để thử như người chưa đăng nhập
        $this->getJson("/len-ke-hoach/$id/tim?q=a")->assertStatus(401);
    }

    // ---- mục tự thêm -----------------------------------------------------------------------------------

    public function test_custom_items_are_saved_loaded_and_counted_in_cost(): void
    {
        $f = $this->food('pho', 'Phở thìn', $this->hn);
        $id = $this->trip();
        $res = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([
            ['type' => 'food', 'refId' => $f, 'day' => 1, 'cost' => 60000],
            ['type' => 'note', 'refId' => null, 'label' => 'Khách sạn Sen Vàng', 'day' => 1, 'cost' => 800000, 'note' => 'Nhận phòng 14h'],
            ['type' => 'transport', 'label' => 'Taxi sân bay', 'day' => 2, 'cost' => 200000],
            ['type' => 'note', 'label' => 'Ghi chú chưa xếp ngày', 'day' => null],
        ]))->assertOk()->assertJson(['ok' => true]);
        $this->assertArrayHasKey('contentWarnings', $res->json());
        $this->assertArrayHasKey('advice', $res->json());
        $this->assertArrayHasKey('savedAt', $res->json());

        $rows = DB::table('tbl_trip_items')->where('tripId', $id)->get();
        $this->assertCount(4, $rows);
        $hotel = $rows->firstWhere('label', 'Khách sạn Sen Vàng');
        $this->assertSame('note', $hotel->refType);
        $this->assertNull($hotel->refId);
        $this->assertSame(1, (int) $hotel->dayNo);
        $this->assertSame('Nhận phòng 14h', $hotel->note);

        $p = app(TripPlanner::class)->payload(app(TripPlanner::class)->owned($this->uid, $id));
        $this->assertEquals(1060000, $p['totals']['cost']);
        $this->assertCount(2, $p['days'][0]['items']);   // ngày 1: món + khách sạn
        $this->assertCount(1, $p['unassigned']);

        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Khách sạn Sen Vàng', false)->assertSee('Taxi sân bay', false);
    }

    public function test_custom_items_may_repeat_but_content_items_still_dedupe(): void
    {
        $f = $this->food('pho', 'Phở', $this->hn);
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([
            ['type' => 'note', 'label' => 'Ăn tối', 'day' => 1],
            ['type' => 'note', 'label' => 'Ăn tối', 'day' => 1],
            ['type' => 'food', 'refId' => $f, 'day' => 1],
            ['type' => 'food', 'refId' => $f, 'day' => 2],
        ]))->assertOk();
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $id)->where('refType', 'note')->count());
        $this->assertSame(1, DB::table('tbl_trip_items')->where('tripId', $id)->where('refType', 'food')->count());
        // thứ tự trong ngày được giữ theo thứ tự gửi lên
        $order = DB::table('tbl_trip_items')->where(['tripId' => $id, 'dayNo' => 1])->orderBy('orderNo')->pluck('refType')->all();
        $this->assertSame(['note', 'note', 'food'], $order);
    }

    public function test_custom_item_needs_label_and_content_item_needs_ref(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'note', 'label' => '   ', 'day' => 1]]))->assertStatus(422);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'note', 'day' => 1]]))->assertStatus(422);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'food', 'day' => 1]]))->assertStatus(422);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'hotel', 'label' => 'x']]))->assertStatus(422);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'note', 'label' => str_repeat('a', 121)]]))->assertStatus(422);
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());   // lỗi thì không ghi gì
    }

    public function test_failed_save_keeps_previous_items(): void
    {
        $f = $this->food('pho', 'Phở', $this->hn);
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'food', 'refId' => $f, 'day' => 1]]))->assertOk();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'note', 'label' => '']]))->assertStatus(422);
        $this->assertSame(1, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_other_user_cannot_save_custom_items(): void
    {
        $id = $this->trip();
        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->postJson("/len-ke-hoach/$id/luu", $this->body([['type' => 'note', 'label' => 'Hack']]))->assertNotFound();
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_item_limit_is_enforced(): void
    {
        $id = $this->trip();
        $items = array_map(fn($i) => ['type' => 'note', 'label' => "Mục $i", 'day' => 1], range(1, TripPlanner::MAX_ITEMS + 1));
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body($items))->assertStatus(422);
    }

    // ---- điểm đến chính ---------------------------------------------------------------------------------

    public function test_main_destination_is_saved_and_validated(): void
    {
        $this->withSession($this->s)->post('/len-ke-hoach', ['title' => 'Đi Huế', 'days' => 2, 'groupSize' => 2, 'destinationId' => $this->hue])->assertRedirect();
        $trip = DB::table('tbl_trips')->where('title', 'Đi Huế')->first();
        $this->assertSame($this->hue, (int) $trip->destinationId);

        $this->withSession($this->s)->post('/len-ke-hoach', ['title' => 'Sai', 'days' => 2, 'groupSize' => 2, 'destinationId' => 999999])->assertSessionHasErrors('destinationId');

        $this->withSession($this->s)->postJson("/len-ke-hoach/{$trip->tripId}/luu", $this->body([], ['destinationId' => $this->hn]))->assertOk();
        $this->assertSame($this->hn, (int) DB::table('tbl_trips')->where('tripId', $trip->tripId)->value('destinationId'));
        // không gửi destinationId (ví dụ API cũ): giữ nguyên
        $this->withSession($this->s)->postJson("/len-ke-hoach/{$trip->tripId}/luu", $this->body([]))->assertOk();
        $this->assertSame($this->hn, (int) DB::table('tbl_trips')->where('tripId', $trip->tripId)->value('destinationId'));
        // gửi null: bỏ chọn
        $this->withSession($this->s)->postJson("/len-ke-hoach/{$trip->tripId}/luu", $this->body([], ['destinationId' => null]))->assertOk();
        $this->assertNull(DB::table('tbl_trips')->where('tripId', $trip->tripId)->value('destinationId'));
    }

    public function test_edit_page_exposes_client_config_and_tabs(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('Lịch trình')->assertSee('Chi phí')->assertSee('Thông tin')->assertDontSee('Khám phá')
            ->assertSee('Lưu thay đổi')->assertSee('Hoàn tác')
            ->assertSee("/len-ke-hoach/$id/tim", false);
    }

    // ---- chia sẻ ----------------------------------------------------------------------------------------

    public function test_shared_page_shows_custom_items_without_links(): void
    {
        $id = $this->trip(['title' => 'Chuyến có mục tự thêm']);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body([
            ['type' => 'note', 'label' => 'Homestay bên suối', 'day' => 1],
            ['type' => 'transport', 'label' => 'Xe khách đêm', 'day' => null],
        ], ['title' => 'Chuyến có mục tự thêm']))->assertOk();
        $url = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/chia-se", ['on' => 1])->assertOk()->json('url');

        $this->flushSession();
        $this->get($url)->assertOk()->assertSee('Homestay bên suối')->assertSee('Xe khách đêm');
    }
}
