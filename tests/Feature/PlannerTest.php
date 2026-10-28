<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 5-7: Trip Planner (web), liên kết dữ liệu và API lời khuyên thời điểm đi. */
class PlannerTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $hue;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = $this->dest('ha-noi', 'Hà Nội', 'b', 21.0285, 105.8542);
        $this->hue = $this->dest('hue', 'Huế', 't', 16.4637, 107.5909);
        $this->uid = $this->user('u1');
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function dest(string $slug, string $name, string $domain, float $lat, float $lng): int
    {
        return DB::table('tbl_destinations')->insertGetId(['slug' => $slug, 'name' => $name, 'province' => $name, 'domain' => $domain,
            'description' => 'mô tả ' . $name, 'latitude' => $lat, 'longitude' => $lng, 'isActive' => 1]);
    }

    private function user(string $name): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $name, 'email' => $name . '@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    private function food(string $slug, ?int $dest = null, string $status = 'published'): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => $slug, 'name' => 'Món ' . $slug, 'destinationId' => $dest ?? $this->hn,
            'category' => 'bún phở', 'description' => 'Mô tả đủ dài cho món ' . $slug, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function experience(string $slug, ?int $dest = null): int
    {
        return DB::table('tbl_experiences')->insertGetId(['slug' => $slug, 'name' => 'Trải nghiệm ' . $slug, 'destinationId' => $dest ?? $this->hn,
            'duration' => 'Nửa ngày', 'description' => 'Mô tả đủ dài', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
    }

    private function tour(int $dest, int $daysAhead = 10): int
    {
        return DB::table('tbl_tours')->insertGetId(['title' => 'Tour thử', 'time' => '3 ngày 2 đêm', 'description' => '<p>mô tả</p>', 'quantity' => 10,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Điểm đến', 'destinationId' => $dest, 'domain' => 'b', 'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(), 'endDate' => now()->addDays($daysAhead + 2)->toDateString()]);
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    // ---- quyền truy cập ------------------------------------------------------------------------------

    public function test_khach_chua_dang_nhap_bi_chuyen_ve_trang_dang_nhap(): void
    {
        $this->get('/len-ke-hoach')->assertRedirect(route('login'));
        $this->postJson('/len-ke-hoach/1/luu', [])->assertStatus(401);
    }

    public function test_trang_danh_sach_hien_form_va_chuyen_cua_toi(): void
    {
        $this->trip(['title' => 'Đà Lạt 3 ngày']);
        $this->withSession($this->s)->get('/len-ke-hoach')->assertOk()->assertSee('Đà Lạt 3 ngày')->assertSee('Tạo chuyến đi mới');
    }

    // ---- tạo từ khay -----------------------------------------------------------------------------

    public function test_tao_tu_khay_tu_chia_muc_vao_cac_ngay(): void
    {
        $this->food('pho');
        $this->experience('ca-tru');
        $r = $this->withSession($this->s)->postJson('/khay-chuyen-di/dong-bo', ['items' => [
            ['type' => 'destination', 'slug' => 'ha-noi'], ['type' => 'food', 'slug' => 'pho'],
            ['type' => 'experience', 'slug' => 'ca-tru'], ['type' => 'destination', 'slug' => 'hue'],
        ]])->assertOk();
        $this->assertCount(4, $r->json('items'));   // khay nhận cả trải nghiệm

        $this->withSession($this->s)->post('/len-ke-hoach', ['title' => 'Bắc - Trung', 'days' => 2, 'groupSize' => 2, 'fromTray' => 1])->assertRedirect();
        $trip = DB::table('tbl_trips')->where('title', 'Bắc - Trung')->first();
        $this->assertNotNull($trip);
        $items = DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->get();
        $this->assertCount(4, $items);
        $this->assertSame([], array_diff($items->pluck('dayNo')->all(), [1, 2]));   // mọi mục đều đã có ngày 1 hoặc 2
        $this->assertSame('planned', $trip->status);
    }

    public function test_validation_ten_trung_khay_va_so_ngay(): void
    {
        $this->withSession($this->s)->post('/len-ke-hoach', ['title' => 'Khay chuyến đi', 'days' => 2, 'groupSize' => 1])->assertSessionHasErrors('title');
        $this->withSession($this->s)->post('/len-ke-hoach', ['title' => 'A', 'days' => 30, 'groupSize' => 1])->assertSessionHasErrors('days');
    }

    // ---- sửa / lưu ----------------------------------------------------------------------------------

    public function test_trang_sua_chi_chu_chuyen_moi_mo_duoc(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Lưu thay đổi');
        $other = $this->user('u2');
        $this->withSession(['userId' => $other, 'username' => 'u2'])->get("/len-ke-hoach/$id")->assertNotFound();
        $this->withSession(['userId' => $other, 'username' => 'u2'])->postJson("/len-ke-hoach/$id/luu",
            ['title' => 'Hack', 'days' => 2, 'groupSize' => 1, 'items' => []])->assertNotFound();
        $this->assertSame('Chuyến thử', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }

    public function test_luu_bo_muc_khong_hop_le_gop_trung_va_chan_ngay_vuot_so_ngay(): void
    {
        $f = $this->food('pho');
        $x = $this->experience('ca-tru');
        $t = $this->tour($this->hn);
        $id = $this->trip(['days' => 2]);
        $body = ['title' => 'Đã sửa', 'startDate' => '2027-03-10', 'days' => 2, 'groupSize' => 3, 'budget' => 5000000, 'items' => [
            ['type' => 'destination', 'refId' => $this->hn, 'day' => 1],
            ['type' => 'food', 'refId' => $f, 'day' => 9, 'note' => 'Ăn sáng', 'cost' => 50000],   // ngày 9 > 2: chặn về 2
            ['type' => 'food', 'refId' => $f, 'day' => 1],                                         // trùng: bỏ
            ['type' => 'experience', 'refId' => $x, 'day' => null],                                // chưa xếp
            ['type' => 'tour', 'refId' => $t, 'day' => 2, 'cost' => 2000000],
            ['type' => 'food', 'refId' => 999999, 'day' => 1],                                     // không tồn tại: bỏ
        ]];
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $body)->assertOk()->assertJson(['ok' => true]);

        $rows = DB::table('tbl_trip_items')->where('tripId', $id)->get()->keyBy(fn($r) => $r->refType . ':' . $r->refId);
        $this->assertCount(4, $rows);
        $this->assertSame(2, (int) $rows["food:$f"]->dayNo);
        $this->assertSame('Ăn sáng', $rows["food:$f"]->note);
        $this->assertNull($rows["experience:$x"]->dayNo);
        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $this->assertSame('Đã sửa', $trip->title);
        $this->assertSame(3, (int) $trip->groupSize);
        $this->assertSame('2027-03-10', $trip->startDate);

        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Món pho', false);
    }

    public function test_tom_tat_tinh_quang_duong_chi_phi_va_canh_bao(): void
    {
        $id = $this->trip(['days' => 1, 'budget' => 1000000]);
        $planner = app(TripPlanner::class);
        $planner->save($this->uid, $id, ['title' => 'T', 'days' => 1, 'groupSize' => 1, 'budget' => 1000000, 'startDate' => null], [
            ['type' => 'destination', 'refId' => $this->hn, 'day' => 1, 'cost' => 800000],
            ['type' => 'destination', 'refId' => $this->hue, 'day' => 1, 'cost' => 600000],
        ]);
        $p = $planner->payload($planner->owned($this->uid, $id));
        $day = $p['days'][0];
        $this->assertCount(1, $day['legs']);
        $this->assertGreaterThan(500, $day['km']);                 // Hà Nội - Huế xa hơn 500 km theo đường bộ ước tính
        $this->assertGreaterThan(self::MIN_TRAVEL_FOR_WARN, $day['minutes']);
        $this->assertSame(1400000.0, (float) $p['totals']['cost']);
        $text = implode(' | ', $p['warnings']);
        $this->assertStringContainsString('vượt ngân sách', $text);
        $this->assertStringContainsString('di chuyển khoảng', $text);
    }

    private const MIN_TRAVEL_FOR_WARN = 300;

    public function test_canh_bao_thang_nen_can_nhac_theo_ngay_di(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 7, 'level' => 'avoid', 'note' => 'Mưa nhiều, 25–32 °C', 'source' => 'manual']);
        $id = $this->trip(['days' => 2, 'startDate' => '2027-07-10']);
        $planner = app(TripPlanner::class);
        $planner->save($this->uid, $id, ['title' => 'T', 'days' => 2, 'groupSize' => 1, 'startDate' => '2027-07-10'], [['type' => 'destination', 'refId' => $this->hn, 'day' => 1]]);
        $p = $planner->payload($planner->owned($this->uid, $id));
        $this->assertStringContainsString('Tháng 7 ở Hà Nội', implode(' ', $p['warnings']));
        $this->assertSame('avoid', $p['advice'][0]['level']);
    }

    // ---- chia sẻ / xóa ---------------------------------------------------------------------------------

    public function test_chia_se_chi_doc_bat_tat_va_xoa(): void
    {
        $id = $this->trip(['title' => 'Chuyến chia sẻ']);
        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/chia-se", ['on' => 1])->assertOk();
        $url = $r->json('url');
        $this->assertNotEmpty($url);

        $this->flushSession();
        $this->get($url)->assertOk()->assertSee('Chuyến chia sẻ');
        $this->get('/chuyen-di/' . str_repeat('a', 32))->assertNotFound();

        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/chia-se", ['on' => 0])->assertOk()->assertJson(['url' => null]);
        $this->get($url)->assertNotFound();

        $this->withSession($this->s)->post("/len-ke-hoach/$id/xoa")->assertRedirect(route('planner'));
        $this->assertDatabaseMissing('tbl_trips', ['tripId' => $id]);
    }

    // ---- liên kết dữ liệu --------------------------------------------------------------------------------

    public function test_mon_gan_nhieu_diem_den_hien_o_ca_hai_nhung_chi_khi_published(): void
    {
        $f = $this->food('com-lam', $this->hn);
        DB::table('tbl_food_destination')->insert(['foodId' => $f, 'destinationId' => $this->hue]);
        $nhap = $this->food('nhap', $this->hn, 'draft');
        DB::table('tbl_food_destination')->insert(['foodId' => $nhap, 'destinationId' => $this->hue]);

        $this->get('/diem-den/hue')->assertOk()->assertSee('Món com-lam', false)->assertDontSee('Món nhap', false);
        $this->get('/diem-den/ha-noi')->assertOk()->assertSee('Món com-lam', false);
    }

    public function test_lenh_gan_mon_vao_nhieu_diem_den(): void
    {
        $this->food('com-lam', $this->hn);
        $this->artisan('content:link-food', ['food' => 'com-lam', 'destinations' => ['hue', 'khong-co']])->assertSuccessful();
        $this->assertSame(1, DB::table('tbl_food_destination')->where('destinationId', $this->hue)->count());
        $this->artisan('content:link-food', ['food' => 'com-lam', 'destinations' => ['hue'], '--remove' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('tbl_food_destination')->where('destinationId', $this->hue)->count());
    }

    // ---- Thời điểm đi -----------------------------------------------------------------------------------

    public function test_api_loi_khuyen_thoi_diem_di_va_trang_diem_den(): void
    {
        DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => 10, 'tMax' => 29.5, 'tMin' => 22.3,
            'rainMm' => 150.0, 'rainyDays' => 9.0, 'years' => 5, 'periodFrom' => 2021, 'periodTo' => 2025]);
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 10, 'level' => 'best', 'note' => null, 'source' => 'manual']);

        $this->getJson('/api/v1/destinations/ha-noi/advice?month=10')->assertOk()
            ->assertJsonPath('advice.level', 'best')->assertJsonPath('advice.stars', 5)->assertJsonPath('advice.month', 10);
        $all = $this->getJson('/api/v1/destinations/ha-noi/advice')->assertOk();
        $this->assertSame([10], $all->json('bestMonths'));
        $this->assertCount(12, $all->json('months'));
        $this->getJson('/api/v1/destinations/ha-noi/advice?month=13')->assertStatus(422);
        $this->getJson('/api/v1/destinations/khong-co/advice')->assertNotFound();

        $this->get('/diem-den/ha-noi')->assertOk()->assertSee('id="thoi-diem-di"', false)->assertSee('Nên mang');
    }

    public function test_trang_diem_den_khong_hien_khoi_khi_chua_co_du_lieu(): void
    {
        $this->get('/diem-den/hue')->assertOk()->assertDontSee('id="thoi-diem-di"', false);
    }
}
