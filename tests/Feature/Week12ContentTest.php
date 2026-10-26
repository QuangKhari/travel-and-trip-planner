<?php

namespace Tests\Feature;

use App\Services\Content\SearchCatalog;
use App\Services\Content\TripTray;
use App\Services\Rag\ChunkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 1-2: Trải nghiệm vào Search, RAG, sitemap, khay; trang /phai-thu; điểm đến hiện Trải nghiệm và Phải thử. */
class Week12ContentTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả Hà Nội', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
    }

    private function exp(string $slug, string $status, array $o = []): int
    {
        return DB::table('tbl_experiences')->insertGetId($o + ['slug' => $slug, 'name' => 'Đi thuyền ' . $slug, 'destinationId' => $this->dest,
            'category' => 'Thiên nhiên & thuyền', 'duration' => 'Nửa ngày', 'description' => 'Mô tả đủ dài cho trải nghiệm ' . $slug,
            'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    private function food(string $slug, string $status, int $must = 0): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => $slug, 'name' => 'Món ' . $slug, 'destinationId' => $this->dest, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món ' . $slug, 'license' => 'Tự viết', 'reviewStatus' => $status, 'isMustTry' => $must]);
    }

    public function test_search_finds_published_experience_only(): void
    {
        $this->exp('song-hong', 'published');
        $this->exp('nhap', 'draft', ['name' => 'Đi thuyền nháp']);
        $res = app(SearchCatalog::class)->search('thuyen');

        $this->assertArrayHasKey('experience', $res);
        $this->assertSame(1, $res['experience']['total']);
        $this->assertSame('song-hong', $res['experience']['items'][0]->slug);
    }

    public function test_rag_chunk_for_published_experience_includes_planner_facts(): void
    {
        $this->exp('song-hong', 'published', ['difficulty' => 'easy', 'durationMinutes' => 180, 'indoor' => 0, 'monthFrom' => 10, 'monthTo' => 3]);
        $this->exp('nhap', 'draft');
        $rows = ChunkBuilder::make()->build(['experience']);

        $this->assertCount(1, $rows);
        $this->assertSame('experience', $rows[0]['refType']);
        $this->assertStringContainsString('Độ khó: dễ', $rows[0]['text']);
        $this->assertStringContainsString('Phù hợp từ tháng 10 đến tháng 3', $rows[0]['text']);
        $this->assertStringContainsString('/trai-nghiem/song-hong', $rows[0]['url']);
    }

    public function test_sitemap_lists_experiences_and_must_do(): void
    {
        $this->exp('song-hong', 'published');
        $this->exp('nhap', 'draft');
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/trai-nghiem/song-hong', $xml);
        $this->assertStringNotContainsString('/trai-nghiem/nhap', $xml);
        $this->assertStringContainsString('/phai-thu', $xml);
    }

    public function test_tray_accepts_published_experience(): void
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->exp('song-hong', 'published');
        $out = (new TripTray())->sync($uid, [['type' => 'experience', 'slug' => 'song-hong']]);

        $this->assertCount(1, $out);
        $this->assertSame('experience', $out[0]['type']);
        $this->assertStringContainsString('/trai-nghiem/song-hong', $out[0]['url']);
    }

    public function test_must_do_page_shows_only_marked_published_items(): void
    {
        $this->food('pho-bo', 'published', 1);
        $this->food('bun-cha', 'published', 0);
        $this->food('nhap', 'draft', 1);
        $this->exp('song-hong', 'published', ['isMustTry' => 1]);
        $r = $this->get('/phai-thu')->assertOk();

        $r->assertSee('Món pho-bo');
        $r->assertSee('Đi thuyền song-hong');
        $r->assertDontSee('Món bun-cha');
        $r->assertDontSee('Món nhap');
    }

    public function test_must_do_event_needs_upcoming_date(): void
    {
        $soon = strtotime('+20 days');
        DB::table('tbl_events')->insert(['slug' => 'le-sap-toi', 'name' => 'Lễ sắp tới', 'destinationId' => $this->dest, 'isLunar' => 0,
            'solarMonth' => (int) date('n', $soon), 'solarDay' => (int) date('j', $soon), 'durationDays' => 1,
            'description' => 'Mô tả lễ hội sắp tới', 'license' => 'Tự viết', 'reviewStatus' => 'published', 'isMustTry' => 1]);
        DB::table('tbl_events')->insert(['slug' => 'le-khong-danh-dau', 'name' => 'Lễ không đánh dấu', 'destinationId' => $this->dest, 'isLunar' => 0,
            'solarMonth' => (int) date('n', $soon), 'solarDay' => (int) date('j', $soon), 'durationDays' => 1,
            'description' => 'Mô tả lễ hội', 'license' => 'Tự viết', 'reviewStatus' => 'published', 'isMustTry' => 0]);
        $r = $this->get('/phai-thu')->assertOk();

        $r->assertSee('Lễ sắp tới');
        $r->assertDontSee('Lễ không đánh dấu');
    }

    public function test_must_do_filters_by_region_and_destination(): void
    {
        $this->food('pho-bo', 'published', 1);
        $this->get('/phai-thu?mien=n')->assertOk()->assertDontSee('Món pho-bo');
        $this->get('/phai-thu?mien=b')->assertOk()->assertSee('Món pho-bo');
        $this->get('/phai-thu?diemden=ha-noi')->assertOk()->assertSee('Món pho-bo');
        $this->get('/phai-thu?diemden=khong-ton-tai')->assertOk()->assertDontSee('Món pho-bo');
    }

    public function test_destination_page_shows_experience_and_must_blocks(): void
    {
        $this->exp('song-hong', 'published', ['isMustTry' => 1, 'difficulty' => 'easy']);
        $r = $this->get('/diem-den/ha-noi')->assertOk();

        $r->assertSee('id="trai-nghiem"', false);
        $r->assertSee('id="phai-thu"', false);
        $r->assertSee('id="nguon"', false);
        $r->assertSee('Dễ');
    }

    public function test_place_must_do_links_to_destination_nearby_block(): void
    {
        DB::table('tbl_external_places')->insert(['destinationId' => $this->dest, 'source' => 'overpass', 'externalId' => 'node/1', 'name' => 'Hồ Gươm',
            'category' => 'see', 'kind' => 'attraction', 'latitude' => 21.0285, 'longitude' => 105.8522, 'rank' => 5, 'syncedAt' => now(),
            'reviewStatus' => 'published', 'isMustTry' => 1]);
        $r = $this->get('/phai-thu')->assertOk();

        $r->assertSee('Hồ Gươm');
        $r->assertSee('/diem-den/ha-noi#gan-day', false);
    }
}
