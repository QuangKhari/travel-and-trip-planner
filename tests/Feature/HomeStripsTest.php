<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Giai đoạn D: dải trang chủ, lễ hội trong hero, khối Planner xem trước. */
class HomeStripsTest extends TestCase
{
    use RefreshDatabase;

    private function dest(string $slug): int
    {
        Storage::disk('public')->put("discover/destinations/{$slug}.webp", 'x');

        return DB::table('tbl_destinations')->insertGetId(['slug' => $slug, 'name' => strtoupper($slug), 'province' => 'T', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21, 'longitude' => 105, 'coverImage' => "discover/destinations/{$slug}.webp", 'isActive' => 1]);
    }

    private function food(string $slug, string $status, ?string $img = null): void
    {
        DB::table('tbl_foods')->insert(['slug' => $slug, 'name' => 'Món ' . $slug, 'category' => 'bún phở', 'description' => 'Mô tả đủ dài cho ' . $slug,
            'coverImage' => $img, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    public function test_dai_am_thuc_chi_hien_mon_published_va_uu_tien_mon_co_anh(): void
    {
        Storage::fake('public');
        foreach (['a', 'b', 'c', 'd'] as $s) {
            Storage::disk('public')->put("discover/foods/{$s}.webp", 'x');
            $this->food($s, 'published', "discover/foods/{$s}.webp");
        }
        $this->food('b-nhap', 'draft', 'discover/foods/a.webp');
        $this->food('c-khong-anh', 'published', null);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="am-thuc-nen-thu"', $html);
        $this->assertStringContainsString('Món a', $html);
        $this->assertStringNotContainsString('Món b-nhap', $html);      // bản nháp không bao giờ hiện
        $this->assertStringNotContainsString('Món c-khong-anh', $html); // đủ 4 món có ảnh thì món không ảnh nhường chỗ
    }

    public function test_dai_rong_khong_ve_tieu_de(): void
    {
        Storage::fake('public');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="am-thuc-nen-thu"', $html);
        $this->assertStringNotContainsString('id="lich-trinh-goi-y"', $html);
        $this->assertStringNotContainsString('id="cam-nang-moi"', $html);
    }

    public function test_hero_co_le_hoi_trong_30_ngay_toi_va_bo_le_hoi_xa(): void
    {
        Storage::fake('public');
        foreach (['a', 'b', 'c'] as $s) {
            $this->dest($s);
        }
        Storage::disk('public')->put('discover/events/gan.webp', 'x');
        Storage::disk('public')->put('discover/events/xa.webp', 'x');
        $near = strtotime('+10 days');
        $far = strtotime('+120 days');
        foreach ([['gan', $near], ['xa', $far]] as [$slug, $ts]) {
            DB::table('tbl_events')->insert(['slug' => $slug, 'name' => 'Lễ ' . $slug, 'isLunar' => 0, 'solarMonth' => (int) date('n', $ts),
                'solarDay' => (int) date('j', $ts), 'durationDays' => 1, 'description' => 'm', 'coverImage' => "discover/events/{$slug}.webp",
                'license' => 'Tự viết', 'reviewStatus' => 'published']);
        }

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-type="event"', $html);
        $this->assertStringContainsString('data-slug="gan"', $html);
        $this->assertStringNotContainsString('data-slug="xa"', $html);
        $this->assertStringContainsString('data-type="destination"', $html);
    }

    public function test_khoi_planner_hien_duoi_hero(): void
    {
        Storage::fake('public');
        foreach (['a', 'b', 'c'] as $s) {
            $this->dest($s);
        }
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="hpl"', $html);
        $this->assertStringContainsString('value="a"', $html);
        $this->assertLessThan(strpos($html, 'id="hpl"'), strpos($html, 'id="hero"'));
    }

    public function test_planner_preview_kiem_tra_tham_so(): void
    {
        $this->getJson('/api/v1/planner-preview?thang=13')->assertStatus(422);
        $this->getJson('/api/v1/planner-preview?so-ngay=99')->assertStatus(422);
        $this->getJson('/api/v1/planner-preview?diem-den=' . urlencode('a b;'))->assertStatus(422);
    }

    public function test_planner_preview_tra_mua_dep_lich_trinh_va_tour(): void
    {
        Storage::fake('public');
        $id = $this->dest('sa-pa');
        DB::table('tbl_destination_months')->insert(['destinationId' => $id, 'month' => 10, 'level' => 'best', 'note' => 'Lúa chín', 'source' => 'manual']);
        $iid = DB::table('tbl_itineraries')->insertGetId(['slug' => 'sa-pa-3n', 'title' => 'Sa Pa 3 ngày', 'days' => 3, 'region' => 'b',
            'summary' => 'tóm tắt', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $iid, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'destination', 'refId' => $id, 'label' => 'Sa Pa']);
        DB::table('tbl_itineraries')->insert(['slug' => 'nhap', 'title' => 'Bản nháp', 'days' => 3, 'region' => 'b', 'license' => 'Tự viết', 'reviewStatus' => 'draft']);

        $j = $this->getJson('/api/v1/planner-preview?diem-den=sa-pa&so-ngay=3&thang=10')->assertOk()->json();
        $this->assertSame('best', $j['verdict']['level']);
        $this->assertCount(1, $j['itineraries']);
        $this->assertSame('Sa Pa 3 ngày', $j['itineraries'][0]['title']);
    }

    public function test_khoi_planner_van_hien_khi_chua_du_anh_cho_hero(): void
    {
        Storage::fake('public');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="hero"', $html);
        $this->assertStringContainsString('id="hpl"', $html);
    }

    public function test_dai_am_thuc_dung_the_thuong_khi_chua_du_mon_co_anh(): void
    {
        Storage::fake('public');
        $this->food('khong-anh-1', 'published', null);
        $this->food('khong-anh-2', 'published', null);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Món khong-anh-1', $html);
        $this->assertStringContainsString('Món khong-anh-2', $html);
    }
}
