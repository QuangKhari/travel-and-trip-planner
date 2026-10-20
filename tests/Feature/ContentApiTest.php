<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentApiTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId([
            'slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'mô tả',
            'latitude' => 21.0, 'longitude' => 105.8, 'isActive' => 1,
        ]);
    }

    private function food(string $slug, string $status, ?int $dest = null): void
    {
        DB::table('tbl_foods')->insert(['slug' => $slug, 'name' => $slug, 'destinationId' => $dest ?? $this->hn, 'description' => 'mô tả món',
            'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function event(string $slug, string $status, array $o = []): void
    {
        DB::table('tbl_events')->insert($o + ['slug' => $slug, 'name' => $slug, 'destinationId' => null, 'isLunar' => 1,
            'lunarMonth' => 1, 'lunarDay' => 1, 'durationDays' => 5, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    public function test_months_tra_du_12_thang_va_khi_hau(): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->hn, 'month' => 10, 'level' => 'best', 'note' => 'Ít mưa', 'source' => 'rule']);
        DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => 10, 'tMax' => 29.5, 'tMin' => 22.3,
            'rainMm' => 100, 'rainyDays' => 9, 'years' => 5, 'periodFrom' => 2021, 'periodTo' => 2025]);

        $d = $this->getJson('/api/v1/destinations/ha-noi/months')->assertOk()->json('data');
        $this->assertCount(12, $d);
        $this->assertSame('best', $d[9]['level']);
        $this->assertSame(29.5, $d[9]['climate']['tMax']);
        $this->assertSame('unknown', $d[0]['level']);
        $this->getJson('/api/v1/destinations/khong-co/months')->assertNotFound();
    }

    public function test_chi_tra_noi_dung_published(): void
    {
        $this->food('pho', 'published');
        $this->food('bun-cha', 'draft');
        $this->food('banh-cuon', 'reviewed');

        $slugs = array_column($this->getJson('/api/v1/destinations/ha-noi/foods')->assertOk()->json('data'), 'slug');
        $this->assertSame(['pho'], $slugs);
        $this->assertSame(['pho'], array_column($this->getJson('/api/v1/foods?destination=ha-noi')->json('data'), 'slug'));
    }

    public function test_le_hoi_kem_ngay_duong_va_le_chung(): void
    {
        $this->event('tet', 'published');                                                            // lễ chung (destinationId null)
        $this->event('hoi-rieng', 'published', ['destinationId' => $this->hn, 'lunarMonth' => 3, 'lunarDay' => 10]);
        $this->event('nhap', 'draft');

        $d = $this->getJson('/api/v1/destinations/ha-noi/events')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['tet', 'hoi-rieng'], array_column($d, 'slug'));
        $tet = collect($d)->firstWhere('slug', 'tet');
        $this->assertTrue($tet['national']);
        $this->assertCount(3, $tet['dates']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $tet['dates'][0]['start']);
    }

    public function test_events_theo_thang_duong(): void
    {
        $this->event('tet', 'published');   // 1/1 âm: 17/2/2026
        $this->event('trung-thu', 'published', ['lunarMonth' => 8, 'lunarDay' => 15, 'durationDays' => 1]); // 25/9/2026

        $feb = $this->getJson('/api/v1/events?month=2&year=2026')->assertOk()->json('data');
        $this->assertSame(['tet'], array_column($feb, 'slug'));
        $sep = $this->getJson('/api/v1/events?month=9&year=2026')->json('data');
        $this->assertSame(['trung-thu'], array_column($sep, 'slug'));
        $this->assertSame('2026-09-25', $sep[0]['dates'][0]['start']);
    }

    public function test_cam_nang_va_lich_trinh_chi_published(): void
    {
        $cat = DB::table('tbl_guide_categories')->insertGetId(['slug' => 'kinh-nghiem', 'name' => 'Kinh nghiệm', 'displayOrder' => 1]);
        DB::table('tbl_guides')->insert(['slug' => 'a', 'categoryId' => $cat, 'title' => 'A', 'body' => 'nội dung', 'reviewStatus' => 'published']);
        DB::table('tbl_guides')->insert(['slug' => 'b', 'categoryId' => $cat, 'title' => 'B', 'body' => 'nội dung', 'reviewStatus' => 'draft']);
        $this->assertSame(['a'], array_column($this->getJson('/api/v1/guides')->json('data'), 'slug'));
        $this->getJson('/api/v1/guides/b')->assertNotFound();
        $this->getJson('/api/v1/guides/a')->assertOk()->assertJsonPath('data.body', 'nội dung');

        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => '3-ngay', 'title' => '3 ngày Hà Nội', 'days' => 3, 'reviewStatus' => 'published']);
        DB::table('tbl_itinerary_items')->insert([
            ['itineraryId' => $it, 'dayNo' => 2, 'orderNo' => 1, 'label' => 'Văn Miếu'],
            ['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'label' => 'Hồ Gươm'],
        ]);
        $plan = $this->getJson('/api/v1/itineraries/3-ngay')->assertOk()->json('data.plan');
        $this->assertSame([1, 2], array_column($plan, 'day'));
        $this->assertSame('Hồ Gươm', $plan[0]['items'][0]['label']);
    }
}
