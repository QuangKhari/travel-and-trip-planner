<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Tìm theo tiêu chí trong Planner: GET /len-ke-hoach/{id}/kham-pha (trải nghiệm có giá thật + OpenStreetMap giả lập, không gọi mạng thật). */
class PlaceBrowseTest extends TestCase
{
    use RefreshDatabase;

    private int $dn;
    private int $owner;
    private int $stranger;
    private int $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['planner.browse.overpass_urls' => ['https://overpass.test/api/interpreter'], 'planner.photon_url' => 'https://photon.test/api', 'planner.geocoder' => 'photon',
            'planner.browse.live' => 'overpass']);   // các bài cũ kiểm tra đường Overpass; bài Photon đặt lại ở dưới
        $this->dn = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-nang', 'name' => 'Đà Nẵng', 'province' => 'Đà Nẵng', 'domain' => 't',
            'description' => 'mô tả', 'latitude' => 16.0544, 'longitude' => 108.2022, 'isActive' => 1]);
        $this->owner = $this->user('chu');
        $this->stranger = $this->user('la');
        $this->trip = app(TripPlanner::class)->create($this->owner, ['title' => 'Đà Nẵng', 'days' => 2, 'groupSize' => 2, 'destinationId' => $this->dn]);
    }

    private function user(string $n): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => $n, 'isActive' => 'y']);
    }

    private function browseGet(string $qs, ?int $as = null)
    {
        return $this->withSession(['userId' => $as ?? $this->owner, 'username' => 'u'])->getJson("/len-ke-hoach/{$this->trip}/kham-pha?" . $qs);
    }

    private function experience(string $slug, string $name, array $over = []): void
    {
        $row = array_merge(['slug' => $slug, 'name' => $name, 'destinationId' => $this->dn, 'description' => 'mô tả', 'license' => 'Tự viết', 'reviewStatus' => 'published'], $over);
        DB::table('tbl_experiences')->insert(array_intersect_key($row, array_flip(Schema::getColumnListing('tbl_experiences'))));
    }

    private function osmJson(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 11, 'lat' => 16.0560, 'lon' => 108.2030, 'tags' => ['name' => 'Quán Chay Sen', 'amenity' => 'restaurant', 'diet:vegetarian' => 'yes', 'website' => 'https://sen.test']],
            ['type' => 'node', 'id' => 12, 'lat' => 16.0570, 'lon' => 108.2040, 'tags' => ['name' => 'Nhà hàng Hải Sản Biển', 'amenity' => 'restaurant', 'cuisine' => 'seafood']],
            ['type' => 'node', 'id' => 13, 'lat' => 16.0580, 'lon' => 108.2050, 'tags' => ['name' => 'Bánh Mì Nhanh', 'amenity' => 'fast_food']],
        ]];
    }

    public function test_cau_co_ten_rieng_khong_vao_che_do_tieu_chi(): void
    {
        Http::fake();
        $this->browseGet('q=' . urlencode('Hồ Gươm'))->assertOk()->assertJson(['ok' => true, 'intent' => ['specific' => true], 'filters' => null, 'browse' => null]);
        $this->browseGet('q=' . urlencode('nhà hàng Hồ Gươm'))->assertOk()->assertJson(['browse' => null]);
        Http::assertNothingSent();
    }

    public function test_nguoi_la_khong_dung_duoc(): void
    {
        Http::fake();
        $this->browseGet('q=' . urlencode('nhà hàng'), $this->stranger)->assertNotFound();
    }

    public function test_hoat_dong_trong_nha_chi_tra_muc_trong_nha(): void
    {
        $this->experience('lop-nau', 'Lớp nấu ăn', ['indoor' => 1, 'priceMin' => 350000]);
        $this->experience('di-thuyen', 'Đi thuyền thúng', ['indoor' => 0, 'priceMin' => 200000]);
        $r = $this->browseGet('q=' . urlencode('hoạt động trong nhà'))->assertOk()->assertJson(['filters' => ['cat' => 'experience', 'indoor' => true]]);
        $names = array_column($r->json('browse.results'), 'name');
        $this->assertSame(['Lớp nấu ăn'], $names);
    }

    public function test_mien_phi_chi_hien_muc_co_gia_0_va_dem_muc_chua_ro(): void
    {
        $this->experience('di-bo', 'Đi bộ bán đảo', ['priceMin' => 0]);
        $this->experience('lop-nau', 'Lớp nấu ăn', ['priceMin' => 350000]);
        $this->experience('chua-co-gia', 'Chưa có giá', []);
        $r = $this->browseGet('q=' . urlencode('trải nghiệm miễn phí'))->assertOk();
        $this->assertSame(['Đi bộ bán đảo'], array_column($r->json('browse.results'), 'name'));
        $this->assertSame(1, $r->json('browse.hidden'));   // "Chưa có giá" bị ẩn, có đếm
        $r2 = $this->browseGet('q=x&f=1&cat=experience&free=1&unknown=1');
        $this->assertContains('Chưa có giá', array_column($r2->json('browse.results'), 'name'));
    }

    public function test_nha_hang_chay_lay_du_lieu_osm_va_cache(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->browseGet('q=' . urlencode('nhà hàng chay'))->assertOk()->assertJson(['filters' => ['cat' => 'restaurant', 'veg' => true]]);
        $names = array_column($r->json('browse.results'), 'name');
        $this->assertSame(['Quán Chay Sen'], $names);
        $this->assertSame(2, $r->json('browse.hidden'));   // hai quán chưa ghi chay bị ẩn, có đếm
        $this->assertNotNull($r->json('browse.results.0.distM'));
        $this->assertSame('osm', $r->json('browse.results.0.src'));

        $this->browseGet('q=' . urlencode('nhà hàng'))->assertOk();   // cùng khu vực: dùng cache, không gọi lại
        Http::assertSentCount(1);
    }

    public function test_muc_gia_suy_tu_loai_hinh_duoc_danh_dau_uoc_tinh(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->browseGet('q=' . urlencode('quán ăn giá rẻ'))->assertOk();
        $rows = collect($r->json('browse.results'));
        $this->assertSame(['Bánh Mì Nhanh'], $rows->pluck('name')->all());
        $this->assertSame('kind', $rows->first()['priceSource']);
        $this->assertNull($rows->first()['priceVnd']);
    }

    public function test_overpass_loi_van_tra_ket_qua_co_san_va_bao_nhe_nhang(): void
    {
        Http::fake(['overpass.test/*' => Http::response('lỗi', 502)]);
        $this->experience('lop-nau', 'Lớp nấu ăn', ['priceMin' => 350000]);
        $r = $this->browseGet('q=x&f=1&cat=restaurant')->assertOk();
        $this->assertFalse($r->json('browse.osmOk'));
        $this->assertNotNull($r->json('browse.note'));
        $this->assertSame([], $r->json('browse.results'));
    }

    public function test_bo_loc_nguoi_dung_chon_duoc_dung_dung_nhu_gui(): void
    {
        Http::fake(['overpass.test/*' => Http::response(['elements' => []])]);
        $r = $this->browseGet('q=x&f=1&cat=hotel&levels=1-2&stars=3&km=5&sort=near&unknown=1')->assertOk();
        $this->assertSame('hotel', $r->json('filters.cat'));
        $this->assertSame([1, 2], $r->json('filters.levels'));
        $this->assertSame(3, $r->json('filters.stars'));
        $this->assertSame(5000, $r->json('filters.radiusM'));
        $this->assertSame('near', $r->json('filters.sort'));
        $this->assertTrue($r->json('filters.includeUnknown'));
    }

    public function test_tham_so_xau_bi_tu_choi(): void
    {
        Http::fake();
        $this->browseGet('q=x&f=1&levels=9-9')->assertStatus(422);
        $this->browseGet('q=x&f=1&cat=drop')->assertStatus(422);
        $this->browseGet('q=x&f=1&kinds=' . urlencode('a;b'))->assertStatus(422);
        $this->browseGet('q=x&f=1&km=500')->assertStatus(422);
    }

    public function test_trang_soan_chuyen_co_duong_dan_kham_pha(): void
    {
        $this->withSession(['userId' => $this->owner, 'username' => 'u'])->get("/len-ke-hoach/{$this->trip}")->assertOk()
            ->assertSee('"browseUrl"', false)->assertSee('id="tpBrowse"', false);
    }

    // ---------------- chống treo trang (Đợt 9) ----------------

    public function test_luot_1_khong_goi_mang_va_bao_con_cho_du_lieu_osm(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->browseGet('q=x&f=1&cat=restaurant&osm=0')->assertOk();
        $this->assertTrue($r->json('browse.osmPending'));
        Http::assertNothingSent();
        $r2 = $this->browseGet('q=x&f=1&cat=restaurant&osm=1')->assertOk();
        $this->assertSame('overpass', $r2->json('browse.osmFrom'));
        $this->assertCount(3, $r2->json('browse.results'));
    }

    public function test_dich_vu_loi_mot_lan_thi_tam_ngat_khong_goi_lai_moi_lan_bam(): void
    {
        Http::fake(['overpass.test/*' => Http::response('lỗi', 504), 'photon.test/*' => Http::response('lỗi', 503)]);
        $this->browseGet('q=x&f=1&cat=cafe')->assertOk()->assertJson(['browse' => ['osmOk' => false]]);
        $sent = count(Http::recorded());
        $this->assertGreaterThan(0, $sent);
        // bấm tiếp các nhóm khác: trả ngay, không gọi mạng nữa (php artisan serve 1 luồng không bị chặn)
        $this->browseGet('q=x&f=1&cat=hotel')->assertOk();
        $this->browseGet('q=x&f=1&cat=bar')->assertOk();
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertTrue(\App\Services\Planner\ExternalGuard::isDown('overpass'));
    }

    public function test_ban_chup_csdl_dung_duoc_khi_mat_mang_va_sau_khi_xoa_cache(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $this->browseGet('q=x&f=1&cat=restaurant')->assertOk();
        $this->assertSame(1, DB::table('tbl_osm_snapshots')->count());
        Cache::flush();
        Http::fake(['*' => Http::response('lỗi', 500)]);
        $r = $this->browseGet('q=x&f=1&cat=restaurant&osm=0')->assertOk();
        $this->assertSame('snapshot', $r->json('browse.osmFrom'));
        $this->assertCount(3, $r->json('browse.results'));
        $this->assertFalse($r->json('browse.osmPending'));
    }

    public function test_overpass_loi_thi_lay_tu_photon_theo_the_osm(): void
    {
        Http::fake([
            'overpass.test/*' => Http::response('lỗi', 502),
            'photon.test/*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [108.2031, 16.0551]], 'properties' => ['name' => 'Cộng Cà Phê', 'osm_type' => 'N', 'osm_id' => 77, 'osm_value' => 'cafe', 'street' => 'Bạch Đằng']],
            ]]),
        ]);
        $r = $this->browseGet('q=x&f=1&cat=cafe')->assertOk();
        $this->assertSame('photon', $r->json('browse.osmFrom'));
        $this->assertSame(['Cộng Cà Phê'], array_column($r->json('browse.results'), 'name'));
        Http::assertSent(fn($req) => str_contains($req->url(), 'photon.test') && str_contains(urldecode($req->url()), 'osm_tag=amenity:cafe'));
    }

    public function test_tat_dich_vu_ngoai_thi_khong_goi_mang(): void
    {
        config(['planner.external.enabled' => false]);
        Http::fake();
        $this->browseGet('q=x&f=1&cat=hotel')->assertOk()->assertJson(['browse' => ['osmPending' => false]]);
        Http::assertNothingSent();
    }

    public function test_warm_osm_nap_ban_chup_va_lan_sau_bo_qua(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--cat' => 'restaurant', '--pause' => 0])->expectsOutputToContain('3 mục')->assertSuccessful();
        $this->assertSame(1, DB::table('tbl_osm_snapshots')->count());
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--cat' => 'restaurant', '--pause' => 0])->expectsOutputToContain('đã có 3 mục, bỏ qua')->assertSuccessful();
        Http::assertSentCount(1);
        // trang lập kế hoạch đọc bản chụp, không gọi mạng
        Cache::flush();
        $this->browseGet('q=x&f=1&cat=restaurant&osm=0')->assertOk()->assertJsonPath('browse.osmFrom', 'snapshot');
        Http::assertSentCount(1);
    }

    public function test_warm_osm_dung_som_khi_mat_mang(): void
    {
        Http::fake(['*' => Http::response('lỗi', 504)]);
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--fail-pause' => 0, '--pause' => 0])
            ->expectsOutputToContain('máy chủ quá tải (504)')->expectsOutputToContain('Dừng sớm')->assertFailed();
    }

    public function test_warm_osm_bi_gioi_han_429_thi_cho_roi_thu_lai(): void
    {
        Http::fake(['overpass.test/*' => Http::sequence()->push('rate limited', 429, ['Retry-After' => '1'])->push($this->osmJson())]);
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--cat' => 'restaurant', '--pause' => 0])
            ->expectsOutputToContain('giới hạn số yêu cầu (429), chờ')->expectsOutputToContain('3 mục')->assertSuccessful();
        Http::assertSentCount(2);
    }

    // ---------------- nguồn trực tiếp mặc định: Photon (nhanh) ----------------

    private function photonCafes(): array
    {
        return ['features' => [
            ['geometry' => ['coordinates' => [108.2031, 16.0551]], 'properties' => ['name' => 'Cộng Cà Phê', 'osm_type' => 'N', 'osm_id' => 77, 'osm_value' => 'cafe', 'street' => 'Bạch Đằng']],
            ['geometry' => ['coordinates' => [108.2101, 16.0601]], 'properties' => ['name' => 'Highlands Coffee', 'osm_type' => 'N', 'osm_id' => 78, 'osm_value' => 'cafe']],
        ]];
    }

    public function test_mac_dinh_lay_truc_tiep_tu_photon_reverse_khong_goi_overpass(): void
    {
        config(['planner.browse.live' => 'photon']);
        Http::fake(['photon.test/reverse*' => Http::response($this->photonCafes()), 'overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->browseGet('q=x&f=1&cat=cafe&osm=1')->assertOk();
        $this->assertSame('photon', $r->json('browse.osmFrom'));
        $this->assertEqualsCanonicalizing(['Cộng Cà Phê', 'Highlands Coffee'], array_column($r->json('browse.results'), 'name'));
        Http::assertSent(fn($q) => str_contains($q->url(), 'photon.test/reverse') && str_contains(urldecode($q->url()), 'osm_tag=amenity:cafe') && str_contains($q->url(), 'radius=3'));
        Http::assertNotSent(fn($q) => str_contains($q->url(), 'overpass.test'));
        // lưu lại: lần sau (kể cả mất mạng, xóa cache) vẫn có
        $this->assertSame(1, DB::table('tbl_osm_snapshots')->count());
        Cache::flush();
        Http::fake(['*' => Http::response('lỗi', 500)]);
        $this->browseGet('q=x&f=1&cat=cafe&osm=0')->assertOk()->assertJsonPath('browse.osmFrom', 'snapshot');
    }

    public function test_photon_reverse_rong_thi_tim_theo_tu_khoa_trong_khung(): void
    {
        config(['planner.browse.live' => 'photon']);
        Http::fake(['photon.test/reverse*' => Http::response(['features' => []]), 'photon.test/api*' => Http::response($this->photonCafes())]);
        $r = $this->browseGet('q=x&f=1&cat=cafe&osm=1')->assertOk();
        $this->assertCount(2, $r->json('browse.results'));
        Http::assertSent(fn($q) => str_contains($q->url(), 'photon.test/api?q=cafe') && str_contains($q->url(), 'bbox='));
    }

    public function test_photon_khong_co_gi_thi_moi_thu_overpass(): void
    {
        config(['planner.browse.live' => 'photon']);
        Http::fake(['photon.test/*' => Http::response(['features' => []]), 'overpass.test/*' => Http::response($this->osmJson())]);
        $this->browseGet('q=x&f=1&cat=restaurant&osm=1')->assertOk()->assertJsonPath('browse.osmFrom', 'overpass');
    }

    public function test_warm_osm_photon_nap_nhanh(): void
    {
        Http::fake(['photon.test/reverse*' => Http::response($this->photonCafes())]);
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--cat' => 'cafe', '--photon' => true, '--pause' => 0])->expectsOutputToContain('2 mục')->assertSuccessful();
        $this->assertSame(1, DB::table('tbl_osm_snapshots')->where('cat', 'cafe')->count());
    }

    public function test_tam_nam_giua_vung_trong_thi_tu_noi_toi_10km(): void
    {
        config(['planner.browse.live' => 'photon']);
        // quán cách tâm ~6 km: trong 3 km không có, tự nới 10 km
        Http::fake(['photon.test/reverse*' => Http::response(['features' => [
            ['geometry' => ['coordinates' => [108.2022, 16.1084]], 'properties' => ['name' => 'Cà phê xa', 'osm_type' => 'N', 'osm_id' => 5, 'osm_value' => 'cafe']],
        ]])]);
        $r = $this->browseGet('q=x&f=1&cat=cafe&osm=1')->assertOk();
        $this->assertSame(10, $r->json('browse.widenedKm'));
        $this->assertSame(['Cà phê xa'], array_column($r->json('browse.results'), 'name'));
    }

    public function test_warm_photon_khong_co_gi_khong_tinh_la_loi(): void
    {
        Http::fake(['photon.test/*' => Http::response(['features' => []])]);
        $this->artisan('planner:warm-osm', ['--slug' => 'da-nang', '--photon' => true, '--pause' => 0, '--fail-pause' => 0])
            ->expectsOutputToContain('không có địa điểm nào trong 20 km')->doesntExpectOutputToContain('Dừng sớm');
    }
}
