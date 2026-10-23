<?php

namespace Tests\Feature;

use App\Services\Rag\AskLimitException;
use App\Services\Rag\AskService;
use App\Services\Rag\ChunkBuilder;
use App\Services\Rag\ChunkIndexer;
use App\Services\Rag\Embedding\EmbedderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Giai đoạn G: chỉ mục, truy xuất, dữ kiện, trích nguồn, từ chối, giới hạn lượt. Dùng bộ nhúng local nên không gọi mạng. */
class RagTest extends TestCase
{
    use RefreshDatabase;

    private int $nb;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rag.embed.driver' => 'local', 'rag.llm.driver' => 'none']);
        Cache::flush();
        $this->nb = DB::table('tbl_destinations')->insertGetId(['slug' => 'ninh-binh', 'name' => 'Ninh Bình', 'province' => 'Ninh Bình', 'domain' => 'b',
            'description' => 'Ninh Bình nổi tiếng với Tràng An và Tam Cốc, được gọi là vịnh Hạ Long trên cạn.', 'latitude' => 20.2, 'longitude' => 105.9, 'isActive' => 1]);
        $cat = DB::table('tbl_guide_categories')->insertGetId(['slug' => 'di-lai', 'name' => 'Đi lại', 'displayOrder' => 1]);
        DB::table('tbl_guides')->insert(['slug' => 'thue-xe-may', 'categoryId' => $cat, 'title' => 'Thuê xe máy ở Việt Nam', 'updatedOn' => '2026-10-01',
            'body' => "## Giấy tờ cần có\nĐể thuê xe máy bạn cần hộ chiếu và bằng lái xe hợp lệ.", 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_guides')->insert(['slug' => 'nhap', 'categoryId' => $cat, 'title' => 'Bản nháp', 'body' => 'nội dung nháp về bí mật', 'reviewStatus' => 'draft']);
        DB::table('tbl_events')->insert(['slug' => 'trung-thu', 'name' => 'Tết Trung thu', 'isLunar' => 1, 'lunarMonth' => 8, 'lunarDay' => 15, 'durationDays' => 1,
            'description' => 'Tết Trung thu rằm tháng Tám với rước đèn phá cỗ.', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        DB::table('tbl_destination_months')->insert(['destinationId' => $this->nb, 'month' => 10, 'level' => 'best', 'note' => 'Lúa chín', 'source' => 'manual']);
        $this->uid = DB::table('tbl_users')->insertGetId(['fullName' => 'A', 'username' => 'a', 'password' => 'x', 'email' => 'a@example.com', 'isActive' => 'y']);
        $this->build();
    }

    private function build(): array
    {
        return app(ChunkIndexer::class)->sync(ChunkBuilder::make()->build(), app(EmbedderFactory::class)->make('local'));
    }

    private function ask(string $q, array $o = []): array
    {
        return app(AskService::class)->ask($q, $this->uid, $o);
    }

    public function test_chi_muc_hoa_noi_dung_published(): void
    {
        $this->assertSame(0, DB::table('tbl_chunks')->where('title', 'like', '%nháp%')->count());
        $this->assertGreaterThan(0, DB::table('tbl_chunks')->where('refType', 'guide')->count());
    }

    public function test_chay_lai_khong_nhung_lai_va_go_duyet_thi_xoa_doan(): void
    {
        $again = $this->build();
        $this->assertSame(0, $again['embedded']);
        DB::table('tbl_guides')->where('slug', 'thue-xe-may')->update(['reviewStatus' => 'draft']);
        $this->assertSame(1, $this->build()['deleted']);
    }

    public function test_doi_noi_dung_thi_nhung_lai_dung_doan_do(): void
    {
        DB::table('tbl_guides')->where('slug', 'thue-xe-may')->update(['body' => "## Giấy tờ cần có\nCần hộ chiếu, bằng lái và đặt cọc."]);
        $s = $this->build();
        $this->assertSame(1, $s['embedded']);
    }

    public function test_cau_hoi_cam_nang_co_nguon_va_nhac_kiem_tra_chinh_thuc(): void
    {
        $r = $this->ask('Thuê xe máy cần giấy tờ gì?');
        $this->assertFalse($r['refused']);
        $this->assertTrue($r['cited']);
        $this->assertSame('/cam-nang/di-lai/thue-xe-may', $r['sources'][0]['url']);
        $this->assertSame('2026-10-01', $r['sources'][0]['updatedOn']);
        $this->assertTrue($r['volatile']);
        $this->assertNotNull($r['notice']);
    }

    public function test_du_kien_mua_dep_va_ngay_le_hoi_lay_tu_sql(): void
    {
        $r = $this->ask('Tháng 10 đi Ninh Bình có hợp không?');
        $this->assertContains('season:ninh-binh:10', $r['factKeys']);
        $this->assertStringContainsString('mùa đẹp nhất', $r['answer']);

        $r = $this->ask('Tết Trung thu năm 2027 diễn ra ngày nào?');
        $this->assertContains('event_dates:trung-thu:2027', $r['factKeys']);
        $this->assertStringContainsString('15/09/2027', $r['answer']);   // rằm tháng 8 âm lịch 2027
    }

    public function test_thieu_du_lieu_thi_tu_choi_va_khong_goi_llm(): void
    {
        config(['rag.llm.driver' => 'anthropic', 'rag.llm.anthropic.key' => 'k']);
        Http::fake();
        $r = app(AskService::class)->compute('Giá vé vào cổng Vinpearl Safari Phú Quốc?');
        $this->assertTrue($r['refused']);
        $this->assertSame([], $r['sources']);
        Http::assertNothingSent();
    }

    public function test_ban_nhap_khong_bao_gio_lo_ra(): void
    {
        $r = $this->ask('nội dung nháp về bí mật');
        $this->assertStringNotContainsString('bí mật', $r['answer']);
    }

    public function test_go_duyet_sau_khi_lap_chi_muc_van_bi_loai_luc_truy_van(): void
    {
        DB::table('tbl_guides')->where('slug', 'thue-xe-may')->update(['reviewStatus' => 'draft']);   // chưa chạy rag:build
        $r = app(AskService::class)->compute('Thuê xe máy cần giấy tờ gì?');
        $this->assertNotContains('/cam-nang/di-lai/thue-xe-may', array_column($r['sources'], 'url'));
    }

    public function test_llm_tra_loi_co_trich_dan_va_chi_so_gia_bi_bo(): void
    {
        config(['rag.llm.driver' => 'anthropic', 'rag.llm.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Cần hộ chiếu và bằng lái [1]. Nên đặt cọc [9].']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ])]);
        $r = app(AskService::class)->compute('Thuê xe máy cần giấy tờ gì?');
        $this->assertSame('llm', $r['engine']);
        $this->assertTrue($r['cited']);
        $this->assertStringNotContainsString('[9]', $r['answer']);
        $this->assertSame(100, $r['usage']['in']);
    }

    public function test_llm_tu_choi_bang_the_khong_co_thong_tin(): void
    {
        config(['rag.llm.driver' => 'anthropic', 'rag.llm.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'KHONG_CO_THONG_TIN']]])]);
        $this->assertTrue(app(AskService::class)->compute('Thuê xe máy cần giấy tờ gì?')['refused']);
    }

    public function test_llm_loi_thi_ha_cap_xuong_trich_doan_va_khong_tinh_luot(): void
    {
        config(['rag.llm.driver' => 'anthropic', 'rag.llm.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response('lỗi', 500)]);
        $r = $this->ask('Thuê xe máy cần giấy tờ gì?');
        $this->assertSame('extractive', $r['engine']);
        $this->assertNotEmpty($r['sources']);
        $this->assertSame(30, $r['remaining']);   // lượt lỗi không bị trừ
    }

    public function test_cau_hoi_lap_lai_lay_tu_bo_nho_dem_khong_ton_luot(): void
    {
        $a = $this->ask('Thuê xe máy cần giấy tờ gì?');
        $b = $this->ask('thue xe may can giay to gi');
        $this->assertTrue($b['cached']);
        $this->assertSame($a['remaining'], $b['remaining']);
    }

    public function test_het_luot_trong_ngay_thi_nem_ngoai_le(): void
    {
        config(['rag.limits.daily_per_user' => 2]);
        $this->ask('Thuê xe máy cần giấy tờ gì?');
        $this->ask('Tháng 10 đi Ninh Bình có hợp không?');
        $this->expectException(AskLimitException::class);
        $this->ask('Tết Trung thu năm 2027 diễn ra ngày nào?');
    }

    public function test_api_ask_can_dang_nhap_va_kiem_tra_dau_vao(): void
    {
        $this->postJson('/api/v1/ask', ['question' => 'Thuê xe máy?'])->assertStatus(401);
    }

    public function test_trang_web_ask_can_dang_nhap_session(): void
    {
        $this->postJson('/hoi-dap/hoi', ['question' => 'Thuê xe máy cần gì?'])->assertStatus(401);

        $this->withSession(['userId' => $this->uid])
            ->postJson('/hoi-dap/hoi', ['question' => 'Thuê xe máy cần giấy tờ gì?'])
            ->assertOk()->assertJsonPath('refused', false)->assertJsonStructure(['answer', 'sources', 'remaining']);

        $this->withSession(['userId' => $this->uid])->postJson('/hoi-dap/hoi', ['question' => 'ab'])->assertStatus(422);
        $this->get('/hoi-dap')->assertOk();
    }
}
