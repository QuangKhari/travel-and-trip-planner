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
        config(['planner.browse.overpass_urls' => ['https://overpass.test/api/interpreter']]);
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

    private function get(string $qs, ?int $as = null)
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
        $this->get('q=' . urlencode('Hồ Gươm'))->assertOk()->assertJson(['ok' => true, 'intent' => ['specific' => true], 'filters' => null, 'browse' => null]);
        $this->get('q=' . urlencode('nhà hàng Hồ Gươm'))->assertOk()->assertJson(['browse' => null]);
        Http::assertNothingSent();
    }

    public function test_nguoi_la_khong_dung_duoc(): void
    {
        Http::fake();
        $this->get('q=' . urlencode('nhà hàng'), $this->stranger)->assertNotFound();
    }

    public function test_hoat_dong_trong_nha_chi_tra_muc_trong_nha(): void
    {
        $this->experience('lop-nau', 'Lớp nấu ăn', ['indoor' => 1, 'priceMin' => 350000]);
        $this->experience('di-thuyen', 'Đi thuyền thúng', ['indoor' => 0, 'priceMin' => 200000]);
        $r = $this->get('q=' . urlencode('hoạt động trong nhà'))->assertOk()->assertJson(['filters' => ['cat' => 'experience', 'indoor' => true]]);
        $names = array_column($r->json('browse.results'), 'name');
        $this->assertSame(['Lớp nấu ăn'], $names);
    }

    public function test_mien_phi_chi_hien_muc_co_gia_0_va_dem_muc_chua_ro(): void
    {
        $this->experience('di-bo', 'Đi bộ bán đảo', ['priceMin' => 0]);
        $this->experience('lop-nau', 'Lớp nấu ăn', ['priceMin' => 350000]);
        $this->experience('chua-co-gia', 'Chưa có giá', []);
        $r = $this->get('q=' . urlencode('trải nghiệm miễn phí'))->assertOk();
        $this->assertSame(['Đi bộ bán đảo'], array_column($r->json('browse.results'), 'name'));
        $this->assertSame(1, $r->json('browse.hidden'));   // "Chưa có giá" bị ẩn, có đếm
        $r2 = $this->get('q=x&f=1&cat=experience&free=1&unknown=1');
        $this->assertContains('Chưa có giá', array_column($r2->json('browse.results'), 'name'));
    }

    public function test_nha_hang_chay_lay_du_lieu_osm_va_cache(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->get('q=' . urlencode('nhà hàng chay'))->assertOk()->assertJson(['filters' => ['cat' => 'restaurant', 'veg' => true]]);
        $names = array_column($r->json('browse.results'), 'name');
        $this->assertSame(['Quán Chay Sen'], $names);
        $this->assertSame(2, $r->json('browse.hidden'));   // hai quán chưa ghi chay bị ẩn, có đếm
        $this->assertNotNull($r->json('browse.results.0.distM'));
        $this->assertSame('osm', $r->json('browse.results.0.src'));

        $this->get('q=' . urlencode('nhà hàng'))->assertOk();   // cùng khu vực: dùng cache, không gọi lại
        Http::assertSentCount(1);
    }

    public function test_muc_gia_suy_tu_loai_hinh_duoc_danh_dau_uoc_tinh(): void
    {
        Http::fake(['overpass.test/*' => Http::response($this->osmJson())]);
        $r = $this->get('q=' . urlencode('quán ăn giá rẻ'))->assertOk();
        $rows = collect($r->json('browse.results'));
        $this->assertSame(['Bánh Mì Nhanh'], $rows->pluck('name')->all());
        $this->assertSame('kind', $rows->first()['priceSource']);
        $this->assertNull($rows->first()['priceVnd']);
    }

    public function test_overpass_loi_van_tra_ket_qua_co_san_va_bao_nhe_nhang(): void
    {
        Http::fake(['overpass.test/*' => Http::response('lỗi', 502)]);
        $this->experience('lop-nau', 'Lớp nấu ăn', ['priceMin' => 350000]);
        $r = $this->get('q=x&f=1&cat=restaurant')->assertOk();
        $this->assertFalse($r->json('browse.osmOk'));
        $this->assertNotNull($r->json('browse.note'));
        $this->assertSame([], $r->json('browse.results'));
    }

    public function test_bo_loc_nguoi_dung_chon_duoc_dung_dung_nhu_gui(): void
    {
        Http::fake(['overpass.test/*' => Http::response(['elements' => []])]);
        $r = $this->get('q=x&f=1&cat=hotel&levels=1-2&stars=3&km=5&sort=near&unknown=1')->assertOk();
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
        $this->get('q=x&f=1&levels=9-9')->assertStatus(422);
        $this->get('q=x&f=1&cat=drop')->assertStatus(422);
        $this->get('q=x&f=1&kinds=' . urlencode('a;b'))->assertStatus(422);
        $this->get('q=x&f=1&km=500')->assertStatus(422);
    }

    public function test_trang_soan_chuyen_co_duong_dan_kham_pha(): void
    {
        $this->withSession(['userId' => $this->owner, 'username' => 'u'])->get("/len-ke-hoach/{$this->trip}")->assertOk()
            ->assertSee('"browseUrl"', false)->assertSee('id="tpBrowse"', false);
    }
}
