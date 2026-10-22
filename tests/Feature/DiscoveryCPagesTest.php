<?php

namespace Tests\Feature;

use App\Services\Content\GuideCatalog;
use App\Services\Content\PublishGate;
use App\Services\Content\SearchCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Giai đoạn C: cẩm nang (C1), mùa đẹp (C2), tìm kiếm (C3), SEO (C4), nguồn dữ liệu (C5). */
class DiscoveryCPagesTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $hoian;
    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = $this->dest('ha-noi', 'Hà Nội', 'b');
        $this->hoian = $this->dest('hoi-an', 'Hội An', 't');
        $this->cat = DB::table('tbl_guide_categories')->insertGetId(['slug' => 'truoc-chuyen-di', 'name' => 'Trước chuyến đi', 'displayOrder' => 1]);
    }

    private function dest(string $slug, string $name, string $domain): int
    {
        return DB::table('tbl_destinations')->insertGetId(['slug' => $slug, 'name' => $name, 'province' => $name, 'domain' => $domain,
            'description' => 'Mô tả ' . $name, 'latitude' => 16.0, 'longitude' => 108.0, 'isActive' => 1]);
    }

    private function month(int $dest, int $m, string $level): void
    {
        DB::table('tbl_destination_months')->insert(['destinationId' => $dest, 'month' => $m, 'level' => $level, 'source' => 'manual']);
    }

    private function guide(string $slug, string $status, array $o = []): int
    {
        return DB::table('tbl_guides')->insertGetId($o + ['slug' => $slug, 'categoryId' => $this->cat, 'title' => 'Bài ' . $slug,
            'body' => "Đoạn mở đầu của bài $slug.\n\n## Mục thứ nhất\n\nNội dung một.\n\n> Hộp lưu ý quan trọng\n\n## Mục thứ hai\n\nNội dung hai.",
            'license' => 'Tự viết', 'reviewStatus' => $status, 'updatedOn' => '2026-10-21']);
    }

    private function food(string $slug, string $name, string $status): void
    {
        DB::table('tbl_foods')->insert(['slug' => $slug, 'name' => $name, 'destinationId' => $this->hn, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món ' . $name, 'license' => 'Tự viết', 'reviewStatus' => $status]);
    }

    // ---- C1: cẩm nang ------------------------------------------------------------------------------

    public function test_cam_nang_chi_hien_published_va_dung_url_chuan(): void
    {
        $this->guide('hanh-ly', 'published');
        $this->guide('ban-nhap', 'draft');

        $this->get('/cam-nang')->assertOk()->assertSee('Bài hanh-ly')->assertDontSee('Bài ban-nhap');
        $this->get('/cam-nang/truoc-chuyen-di')->assertOk()->assertSee('Bài hanh-ly');
        $this->get('/cam-nang/truoc-chuyen-di/ban-nhap')->assertNotFound();
        $this->get('/cam-nang/khong-co')->assertNotFound();
        $this->get('/cam-nang/truoc-chuyen-di/hanh-ly')->assertOk();
        // sai danh mục trong URL: chuyển 301 về URL chuẩn
        $this->get('/cam-nang/khac/hanh-ly')->assertStatus(301)->assertRedirect('/cam-nang/truoc-chuyen-di/hanh-ly');
    }

    public function test_bai_co_muc_luc_hop_luu_y_ngay_cap_nhat_va_json_ld(): void
    {
        $this->guide('hanh-ly', 'published');
        $r = $this->get('/cam-nang/truoc-chuyen-di/hanh-ly')->assertOk();
        $r->assertSee('id="muc-thu-nhat"', false)->assertSee('href="#muc-thu-hai"', false)
            ->assertSee('<blockquote>', false)->assertSee('Cập nhật lần cuối')->assertSee('21/10/2026');
        $this->assertStringContainsString('"@type":"Article"', $r->getContent());
        $this->assertStringContainsString('"dateModified":"2026-10-21"', $r->getContent());
        $this->assertStringContainsString('rel="canonical" href="' . url('/cam-nang/truoc-chuyen-di/hanh-ly') . '"', $r->getContent());
    }

    public function test_noi_dung_bai_khong_cho_chay_html_hay_lien_ket_doc(): void
    {
        $this->guide('xss', 'published', ['body' => "## Mục\n\n<script>alert(1)</script> [bấm](javascript:alert(2)) ![x](http://evil.test/a.png)\n\n<img src=x onerror=alert(3)>"]);
        $html = $this->get('/cam-nang/truoc-chuyen-di/xss')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('evil.test', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_cong_duyet_bai_cam_nang_can_ngay_cap_nhat(): void
    {
        $ok = (object) ['body' => str_repeat('a', 40), 'license' => 'Tự viết', 'coverImage' => null, 'updatedOn' => '2026-10-21'];
        $this->assertSame([], PublishGate::problems('guides', $ok));
        $no = (object) ['body' => str_repeat('a', 40), 'license' => 'Tự viết', 'coverImage' => null, 'updatedOn' => null];
        $this->assertNotEmpty(PublishGate::problems('guides', $no));
    }

    public function test_tom_tat_markdown_la_chu_thuan(): void
    {
        $this->assertSame('Tiêu đề Nội dung có đậm', GuideCatalog::excerpt("## Tiêu đề\n\nNội dung **có đậm**"));
    }

    // ---- C2: mùa đẹp -------------------------------------------------------------------------------

    public function test_dang_vao_mua_theo_thang(): void
    {
        $this->month($this->hn, 10, 'best');
        $this->month($this->hoian, 10, 'avoid');
        DB::table('tbl_events')->insert(['slug' => 'trung-thu', 'name' => 'Lễ trung-thu', 'destinationId' => $this->hn, 'isLunar' => 1,
            'lunarMonth' => 8, 'lunarDay' => 15, 'durationDays' => 1, 'description' => 'Mô tả lễ hội trung thu', 'license' => 'Tự viết', 'reviewStatus' => 'published']);

        $this->get('/dang-vao-mua?thang=10')->assertOk()->assertSee('Hà Nội')->assertDontSee('Hội An')->assertSee('Tháng 10: đi đâu đẹp nhất?');
        $this->get('/dang-vao-mua?thang=99')->assertOk();   // tháng lạ: dùng tháng hiện tại, không lỗi
        $this->get('/dang-vao-mua')->assertOk();
    }

    public function test_ma_tran_nen_di_thang_nao(): void
    {
        $this->month($this->hn, 10, 'best');
        $this->month($this->hn, 7, 'avoid');
        $this->month($this->hoian, 3, 'best');

        $r = $this->get('/nen-di-thang-nao')->assertOk()->assertSee('Hà Nội')->assertSee('Hội An');
        $r->assertSee('Hà Nội, tháng 10: đẹp nhất')->assertSee('Hà Nội, tháng 7: nên tránh')->assertSee('Hà Nội, tháng 1: chưa có dữ liệu');
        $this->get('/nen-di-thang-nao/10')->assertOk()->assertSee('Đẹp nhất vào tháng 10');
        $this->get('/nen-di-thang-nao?mien=b')->assertOk()->assertSee('Hà Nội')->assertDontSee('Hội An')
            ->assertSee('<meta name="robots" content="noindex,follow">', false);   // đang lọc: không lập chỉ mục
        $this->get('/nen-di-thang-nao/13')->assertNotFound();
        $this->get('/nen-di-thang-nao/abc')->assertNotFound();
    }

    public function test_diem_den_chua_co_du_lieu_thang_khong_vao_ma_tran(): void
    {
        $this->month($this->hn, 10, 'best');   // hoi-an không có tháng nào
        $this->get('/nen-di-thang-nao')->assertOk()->assertDontSee('Hội An, tháng');
    }

    public function test_api_ma_tran_mua(): void
    {
        $this->month($this->hn, 10, 'best');
        $json = $this->getJson('/api/v1/seasons/matrix')->assertOk()->json();
        $this->assertSame('ha-noi', $json['items'][0]['slug']);
        $this->assertSame('best', $json['items'][0]['months'][10]);
        $this->assertSame('unknown', $json['items'][0]['months'][1]);
        $this->getJson('/api/v1/seasons/matrix?region=b')->assertOk();
        $this->getJson('/api/v1/seasons/matrix?region=x')->assertStatus(422);
    }

    // ---- C3: tìm kiếm ------------------------------------------------------------------------------

    public function test_fold_bo_dau(): void
    {
        $this->assertSame('bun cha da lat', SearchCatalog::fold('Bún chả Đà Lạt'));
        $this->assertSame('hoi an', SearchCatalog::fold('  HỘI   AN! '));
    }

    public function test_tim_kiem_khong_dau_va_chi_tim_published(): void
    {
        $this->food('bun-cha', 'Bún chả', 'published');
        $this->food('bun-nhap', 'Bún nháp', 'draft');
        $this->guide('hanh-ly', 'published', ['title' => 'Chuẩn bị hành lý']);

        $r = $this->get('/tim-kiem?q=bun+cha')->assertOk()->assertSee('Bún chả')->assertDontSee('Bún nháp');
        $r->assertSee('<meta name="robots" content="noindex,follow">', false);
        $this->get('/tim-kiem?q=hanh+ly')->assertOk()->assertSee('Chuẩn bị hành lý');
        $this->get('/tim-kiem?q=ha+noi')->assertOk()->assertSee('Hà Nội');
        $this->get('/tim-kiem?q=x')->assertOk()->assertSee('Không có kết quả');   // 1 ký tự: không tìm
        $this->get('/tim-kiem?q=zzzzzz')->assertOk()->assertSee('Không có kết quả');
        $this->get('/tim-kiem')->assertOk();
    }

    public function test_api_goi_y_va_tim_kiem(): void
    {
        $this->food('bun-cha', 'Bún chả', 'published');

        $s = $this->getJson('/api/v1/search/suggest?q=bun')->assertOk()->json();
        $this->assertSame('Bún chả', $s['items'][0]['title']);
        $this->assertSame('Ẩm thực', $s['items'][0]['typeLabel']);
        $this->assertSame([], $this->getJson('/api/v1/search/suggest?q=b')->assertOk()->json('items'));
        $g = $this->getJson('/api/v1/search?q=bun')->assertOk()->json();
        $this->assertSame(1, $g['groups']['food']['total']);
        $this->getJson('/api/v1/search?q=b')->assertStatus(422);
    }

    // ---- C4: SEO -----------------------------------------------------------------------------------

    public function test_robots_va_sitemap(): void
    {
        $this->guide('hanh-ly', 'published');
        $this->guide('ban-nhap', 'draft');
        $this->food('pho', 'Phở', 'published');
        $this->food('nhap', 'Nháp', 'draft');

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Sitemap: ' . url('/sitemap.xml'), $robots);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString(url('/cam-nang/truoc-chuyen-di/hanh-ly'), $xml);
        $this->assertStringContainsString(url('/am-thuc/pho'), $xml);
        $this->assertStringContainsString(url('/diem-den/ha-noi'), $xml);
        $this->assertStringNotContainsString('ban-nhap', $xml);
        $this->assertStringNotContainsString('/am-thuc/nhap', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'sitemap phải là XML hợp lệ');
    }

    public function test_meta_canonical_og_va_noindex_khi_loc(): void
    {
        $this->food('pho', 'Phở', 'published');

        $r = $this->get('/am-thuc/pho')->assertOk();
        $r->assertSee('<meta name="description" content="Mô tả đủ dài cho món Phở">', false)
            ->assertSee('<link rel="canonical" href="' . url('/am-thuc/pho') . '">', false)
            ->assertSee('<meta property="og:locale" content="vi_VN">', false)
            ->assertDontSee('name="robots"', false);

        $this->get('/am-thuc?mien=b')->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('<link rel="canonical" href="' . url('/am-thuc') . '">', false);   // bộ lọc không nằm trong URL chuẩn
        $this->get('/am-thuc')->assertOk()->assertDontSee('noindex', false);
    }

    // ---- C5: nguồn dữ liệu -------------------------------------------------------------------------

    public function test_trang_nguon_du_lieu(): void
    {
        $this->guide('hanh-ly', 'published');
        $this->get('/nguon-du-lieu')->assertOk()->assertSee('OpenStreetMap')->assertSee('CC BY-SA 4.0')->assertSee('Open-Meteo')->assertSee('bài cẩm nang');
    }
}
