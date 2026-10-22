<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Giai đoạn B: trang danh sách/chi tiết của lớp Khám phá, đánh dấu và khay chuyến đi. */
class GuidePagesTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $hue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = $this->dest('ha-noi', 'Hà Nội', 'b', 21.0285, 105.8542);
        $this->hue = $this->dest('hue', 'Huế', 't', 16.4637, 107.5909);
    }

    private function dest(string $slug, string $name, string $domain, float $lat, float $lng): int
    {
        return DB::table('tbl_destinations')->insertGetId(['slug' => $slug, 'name' => $name, 'province' => $name, 'domain' => $domain,
            'description' => 'mô tả ' . $name, 'latitude' => $lat, 'longitude' => $lng, 'isActive' => 1]);
    }

    private function food(string $slug, string $status, ?int $dest = null, string $cat = 'bún phở'): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => $slug, 'name' => 'Món ' . $slug, 'destinationId' => $dest ?? $this->hn,
            'category' => $cat, 'description' => 'Mô tả đủ dài cho món ' . $slug, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function event(string $slug, string $status, array $o = []): int
    {
        return DB::table('tbl_events')->insertGetId($o + ['slug' => $slug, 'name' => 'Lễ ' . $slug, 'destinationId' => $this->hn, 'isLunar' => 1,
            'lunarMonth' => 1, 'lunarDay' => 1, 'durationDays' => 3, 'description' => 'Mô tả lễ hội ' . $slug, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function user(): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    // ---- B2/B4: chỉ published, bộ lọc, 404 cho nháp -------------------------------------------------

    public function test_danh_sach_va_chi_tiet_mon_chi_hien_published(): void
    {
        $this->food('pho', 'published');
        $this->food('nhap', 'draft');
        $this->food('hue-bun', 'published', $this->hue, 'bún phở');
        $this->food('banh', 'published', $this->hue, 'bánh');

        $r = $this->get('/am-thuc')->assertOk();
        $r->assertSee('Món pho')->assertDontSee('Món nhap');
        $this->assertStringContainsString('3 món', strip_tags($r->getContent()));

        $this->get('/am-thuc?mien=t')->assertOk()->assertSee('Món hue-bun')->assertDontSee('Món pho');
        $this->get('/am-thuc?nhom=' . urlencode('bánh'))->assertOk()->assertSee('Món banh')->assertDontSee('Món pho');
        $this->get('/am-thuc?mien=x&nhom=%25')->assertOk(); // giá trị lạ: bỏ qua, không lỗi

        $this->get('/am-thuc/pho')->assertOk()->assertSee('Món pho')->assertSee('Tôi đã thử');
        $this->get('/am-thuc/nhap')->assertNotFound();
        $this->get('/am-thuc/khong-co')->assertNotFound();
    }

    public function test_le_hoi_ngay_duong_lich_thang_va_json_ld(): void
    {
        $this->event('tet', 'published', ['lunarMonth' => 1, 'lunarDay' => 1, 'durationDays' => 5]);
        $this->event('ban-nhap', 'draft');
        $this->event('khong-ro-ngay', 'published', ['isLunar' => 0, 'lunarMonth' => null, 'lunarDay' => null, 'solarMonth' => 10, 'solarDay' => null]);

        $this->get('/le-hoi')->assertOk()->assertSee('Lễ tet')->assertDontSee('Lễ ban-nhap');
        $this->get('/le-hoi?loai=duong')->assertOk()->assertSee('Lễ khong-ro-ngay')->assertDontSee('Lễ tet');

        $r = $this->get('/le-hoi/tet')->assertOk();
        $r->assertSee('Ngày 1 tháng 1 âm lịch')->assertSee('application/ld+json', false)->assertSee('"@type":"Event"', false);
        $this->assertGreaterThanOrEqual(3, substr_count($r->getContent(), '<tr><td>')); // 3 đợt dương lịch
        $this->get('/le-hoi/ban-nhap')->assertNotFound();

        $this->get('/le-hoi/lich?thang=10&nam=2026')->assertOk()->assertSee('Lịch lễ hội tháng 10/2026')->assertSee('Lễ khong-ro-ngay');
        $this->get('/le-hoi/lich?thang=99&nam=abc')->assertOk(); // tháng/năm sai: dùng mặc định
    }

    public function test_lich_trinh_theo_ngay_va_diem_dung_co_toa_do(): void
    {
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => 'ha-noi-1', 'title' => 'Hà Nội 1 ngày', 'days' => 1, 'region' => 'b',
            'summary' => 'Một ngày quanh hồ Gươm đủ ba mươi ký tự.', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        $place = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => 'node/1', 'name' => 'Chùa A',
            'category' => 'see', 'latitude' => 21.03, 'longitude' => 105.85, 'rank' => 3, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        DB::table('tbl_itinerary_items')->insert([
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 0, 'refType' => 'destination', 'refId' => $this->hn, 'label' => 'Hà Nội', 'note' => null],
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'place', 'refId' => $place, 'label' => 'Chùa A', 'note' => 'Buổi sáng'],
        ]);
        DB::table('tbl_itineraries')->insert(['slug' => 'nhap', 'title' => 'Bản nháp', 'days' => 2, 'reviewStatus' => 'draft']);

        $this->get('/lich-trinh')->assertOk()->assertSee('Hà Nội 1 ngày')->assertDontSee('Bản nháp');
        $this->get('/lich-trinh?songay=2')->assertOk()->assertDontSee('Hà Nội 1 ngày');
        $r = $this->get('/lich-trinh/ha-noi-1')->assertOk();
        $r->assertSee('Ngày 1')->assertSee('Buổi sáng')->assertSee('gxPoints', false);
        $this->get('/lich-trinh/nhap')->assertNotFound();
    }

    public function test_danh_sach_diem_den_loc_theo_mien_va_thang_dep(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hue, 'month' => 4, 'level' => 'best', 'source' => 'manual']);
        $this->get('/diem-den/tat-ca')->assertOk()->assertSee('Hà Nội')->assertSee('Huế');
        $this->get('/diem-den/tat-ca?mien=b')->assertOk()->assertSee('Hà Nội')->assertDontSee('2 điểm đến');
        $r = $this->get('/diem-den/tat-ca?thang=4')->assertOk();
        $this->assertStringContainsString('1 điểm đến', strip_tags($r->getContent()));
        $r->assertSee('Huế');
    }

    // ---- B3: chi tiết điểm đến ------------------------------------------------------------------------

    public function test_chi_tiet_diem_den_co_12_thang_dia_diem_gan_va_chi_published(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 10, 'level' => 'best', 'source' => 'rule']);
        DB::table('tbl_external_places')->insert([
            ['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => 'node/2', 'name' => 'Đã duyệt', 'category' => 'see', 'latitude' => 21.03, 'longitude' => 105.85, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()],
            ['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => 'node/3', 'name' => 'Chưa duyệt', 'category' => 'see', 'latitude' => 21.03, 'longitude' => 105.85, 'rank' => 9, 'reviewStatus' => 'draft', 'syncedAt' => now()],
            ['destinationId' => $this->hue, 'source' => 'overpass', 'externalId' => 'node/4', 'name' => 'Quá xa', 'category' => 'see', 'latitude' => 16.4, 'longitude' => 107.6, 'rank' => 9, 'reviewStatus' => 'published', 'syncedAt' => now()],
        ]);
        $this->food('pho', 'published');

        $r = $this->get('/diem-den/ha-noi')->assertOk();
        $this->assertSame(12, substr_count($r->getContent(), 'class="gx-m gx-m--'));
        $r->assertSee('Đã duyệt')->assertDontSee('Chưa duyệt')->assertDontSee('Quá xa')->assertSee('Món pho');
        $this->assertMatchesRegularExpression('/\d+,\d km/', $r->getContent());
        $r->assertSee('data-trip-add', false)->assertSee('Nên đi tháng nào');

        $this->get('/diem-den/khong-co')->assertNotFound();
    }

    public function test_duong_dan_cu_destination_chuyen_vinh_vien_sang_diem_den(): void
    {
        $this->get('/destination')->assertStatus(301)->assertRedirect('/diem-den');
        $this->get('/destination/ha-noi')->assertStatus(301)->assertRedirect('/diem-den/ha-noi');
    }

    // ---- B6: đánh dấu và khay ---------------------------------------------------------------------------

    public function test_danh_dau_can_dang_nhap_va_bat_tat(): void
    {
        $this->food('pho', 'published');
        $this->postJson('/danh-dau', ['type' => 'tried', 'refType' => 'food', 'slug' => 'pho'])->assertStatus(401);

        $uid = $this->user();
        $s = ['userId' => $uid, 'username' => 'u1'];
        $this->withSession($s)->postJson('/danh-dau', ['type' => 'tried', 'refType' => 'food', 'slug' => 'pho'])
            ->assertOk()->assertJson(['marked' => true, 'counts' => ['tried' => 1]]);
        $this->assertSame(1, DB::table('tbl_user_marks')->where('userId', $uid)->count());
        $this->withSession($s)->get('/am-thuc/pho')->assertSee('✓ Đã thử');
        $this->withSession($s)->postJson('/danh-dau', ['type' => 'tried', 'refType' => 'food', 'slug' => 'pho'])->assertOk()->assertJson(['marked' => false]);
        $this->assertSame(0, DB::table('tbl_user_marks')->where('userId', $uid)->count());

        // loại không hợp với mục, mục nháp, dữ liệu sai
        $this->withSession($s)->postJson('/danh-dau', ['type' => 'visited', 'refType' => 'food', 'slug' => 'pho'])->assertStatus(422);
        $this->food('nhap', 'draft');
        $this->withSession($s)->postJson('/danh-dau', ['type' => 'tried', 'refType' => 'food', 'slug' => 'nhap'])->assertNotFound();
        $this->withSession($s)->postJson('/danh-dau', ['type' => 'x', 'refType' => 'food', 'slug' => 'pho'])->assertStatus(422);
    }

    public function test_khay_chuyen_di_dong_bo_gop_va_bo_qua_muc_khong_hop_le(): void
    {
        $this->food('pho', 'published');
        $this->food('nhap', 'draft');
        $uid = $this->user();
        $s = ['userId' => $uid, 'username' => 'u1'];
        $this->postJson('/khay-chuyen-di/dong-bo', ['items' => []])->assertStatus(401);

        $items = [['type' => 'destination', 'slug' => 'ha-noi'], ['type' => 'food', 'slug' => 'pho'], ['type' => 'food', 'slug' => 'nhap'],
            ['type' => 'place', 'slug' => 'x'], ['type' => 'destination', 'slug' => 'ha-noi']];
        $r = $this->withSession($s)->postJson('/khay-chuyen-di/dong-bo', ['items' => $items])->assertOk();
        $this->assertSame(['destination:ha-noi', 'food:pho'], array_map(fn($i) => $i['type'] . ':' . $i['slug'], $r->json('items')));

        // gửi lại không nhân đôi; bỏ một mục
        $r = $this->withSession($s)->postJson('/khay-chuyen-di/dong-bo', ['items' => $items, 'removed' => [['type' => 'food', 'slug' => 'pho']]])->assertOk();
        $this->assertSame(['destination:ha-noi', 'food:pho'], array_map(fn($i) => $i['type'] . ':' . $i['slug'], $r->json('items'))); // items gửi kèm đã thêm lại pho
        $r = $this->withSession($s)->postJson('/khay-chuyen-di/dong-bo', ['items' => [], 'removed' => [['type' => 'food', 'slug' => 'pho']]])->assertOk();
        $this->assertSame(['destination:ha-noi'], array_map(fn($i) => $i['type'] . ':' . $i['slug'], $r->json('items')));
        $this->assertSame(1, DB::table('tbl_trips')->where('userId', $uid)->count());
    }

    public function test_trang_chu_va_trang_con_nap_khay_chuyen_di(): void
    {
        $this->get('/am-thuc')->assertOk()->assertSee('travela-trip.js', false)->assertSee('TRAVELA_TRIP', false);
    }
}
