<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Form soạn nội dung của admin: nguồn gốc "tự viết / nguồn khác", nguồn ảnh, "Lưu và xuất bản". */
class Week10AdminContentFormTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $dest;
    private string $long = 'Bánh cuốn Cao Bằng mỏng, dai, ăn kèm nước dùng xương hầm và hành phi thơm.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'cao-bang', 'name' => 'Cao Bằng', 'province' => 'Cao Bằng', 'domain' => 'b', 'description' => 'mô tả', 'latitude' => 22.66, 'longitude' => 106.25, 'isActive' => 1]);
    }

    private function as()
    {
        return $this->withSession(['adminId' => $this->admin]);
    }

    private function body(array $over = []): array
    {
        return $over + ['name' => 'bánh cuốn Cao Bằng', 'destinationId' => $this->dest, 'category' => 'bánh cuốn', 'description' => $this->long];
    }

    private function food(array $over = []): int
    {
        return DB::table('tbl_foods')->insertGetId($over + ['slug' => 'banh-cuon', 'name' => 'Bánh cuốn', 'destinationId' => $this->dest, 'category' => 'bánh cuốn',
            'description' => $this->long, 'reviewStatus' => 'draft']);
    }

    // ------------------------------------------------------------ giao diện

    public function test_create_form_is_grouped_and_hides_raw_legal_fields(): void
    {
        $this->as()->get('/admin/content/foods/create')->assertOk()
            ->assertSee('Thông tin chính')
            ->assertSee('Travela tự viết')
            ->assertSee('Dựa theo nguồn khác')
            ->assertSee('Lưu và xuất bản')
            ->assertSee('Thông tin thêm')
            ->assertSee('name="provenance"', false)
            ->assertSee('name="imageCreditMode"', false)
            ->assertDontSee('Ghi công ảnh')
            ->assertDontSee('Giấy phép (vd CC BY-SA');
    }

    public function test_event_form_uses_calendar_radio_and_month_select(): void
    {
        $this->as()->get('/admin/content/events/create')->assertOk()
            ->assertSee('name="isLunar"', false)
            ->assertSee('Âm lịch')
            ->assertSee('Dương lịch')
            ->assertSee('Tháng 12');
    }

    public function test_food_category_suggestions_come_from_existing_values(): void
    {
        $this->food(['category' => 'đặc sản núi']);
        $this->as()->get('/admin/content/foods/create')->assertOk()->assertSee('đặc sản núi')->assertSee('id="categoryHints"', false);
    }

    // ------------------------------------------------------------ nguồn gốc

    public function test_own_content_gets_self_written_license_without_typing_anything(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'own', 'sourceUrl' => 'https://x.test', 'license' => 'rác']))->assertRedirect();
        $row = DB::table('tbl_foods')->first();
        $this->assertSame('Tự viết', $row->license);
        $this->assertNull($row->sourceUrl);
        $this->assertSame('draft', $row->reviewStatus);
    }

    public function test_other_source_requires_url_and_license(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'other']))->assertSessionHasErrors(['license', 'sourceUrl']);
        $this->assertSame(0, DB::table('tbl_foods')->count());
    }

    public function test_other_source_saves_url_and_license(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'other', 'license' => 'CC BY-SA 4.0', 'sourceUrl' => 'https://vi.wikipedia.org/wiki/B%C3%A1nh_cu%E1%BB%91n']))->assertSessionHasNoErrors();
        $row = DB::table('tbl_foods')->first();
        $this->assertSame('CC BY-SA 4.0', $row->license);
        $this->assertStringStartsWith('https://vi.wikipedia.org', $row->sourceUrl);
    }

    public function test_switching_to_other_does_not_keep_the_self_written_license(): void
    {
        $id = $this->food(['license' => 'Tự viết']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'other', 'license' => 'Tự viết', 'sourceUrl' => 'https://a.test/x']))->assertSessionHasErrors(['license']);
    }

    public function test_switching_to_own_clears_the_source_link(): void
    {
        $id = $this->food(['license' => 'CC BY-SA 4.0', 'sourceUrl' => 'https://a.test/x']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own']))->assertSessionHasNoErrors();
        $row = DB::table('tbl_foods')->where('foodId', $id)->first();
        $this->assertSame('Tự viết', $row->license);
        $this->assertNull($row->sourceUrl);
    }

    public function test_old_style_post_without_provenance_still_works(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['license' => 'Tự viết']))->assertSessionHasNoErrors();
        $this->assertSame('Tự viết', DB::table('tbl_foods')->value('license'));
    }

    // ------------------------------------------------------------ nguồn ảnh

    public function test_own_image_credit_is_filled_automatically(): void
    {
        $id = $this->food(['license' => 'Tự viết', 'coverImage' => 'discover/foods/x.webp']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own', 'imageCreditMode' => 'own', 'hasCover' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('Travela', DB::table('tbl_foods')->where('foodId', $id)->value('imageCredit'));
    }

    public function test_other_image_source_needs_a_name(): void
    {
        $id = $this->food(['license' => 'Tự viết', 'coverImage' => 'discover/foods/x.webp']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own', 'imageCreditMode' => 'other', 'hasCover' => 1]))->assertSessionHasErrors(['imageCredit']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own', 'imageCreditMode' => 'other', 'hasCover' => 1, 'imageCredit' => 'Nguyễn A / Wikimedia Commons']))->assertSessionHasNoErrors();
        $this->assertSame('Nguyễn A / Wikimedia Commons', DB::table('tbl_foods')->where('foodId', $id)->value('imageCredit'));
    }

    public function test_image_mode_is_ignored_when_there_is_no_image(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'own', 'imageCreditMode' => 'own']))->assertSessionHasNoErrors();
        $this->assertNull(DB::table('tbl_foods')->value('imageCredit'));
    }

    // ------------------------------------------------------------ lưu và xuất bản

    public function test_save_and_publish_a_complete_new_item(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'own', 'after' => 'publish']))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('published', DB::table('tbl_foods')->value('reviewStatus'));
    }

    public function test_save_and_publish_incomplete_item_stays_draft_and_explains_why(): void
    {
        $this->as()->post('/admin/content/foods', $this->body(['provenance' => 'own', 'after' => 'publish', 'description' => 'ngắn']))
            ->assertRedirect()
            ->assertSessionHas('error', fn($v) => str_contains((string) $v, 'quá ngắn'));
        $this->assertSame('draft', DB::table('tbl_foods')->value('reviewStatus'));
        $this->assertSame(1, DB::table('tbl_foods')->count());   // vẫn lưu nháp, không mất dữ liệu đã nhập
    }

    public function test_update_with_publish_flag_publishes_a_draft(): void
    {
        $id = $this->food(['license' => 'Tự viết']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own', 'after' => 'publish']))->assertSessionHas('success');
        $this->assertSame('published', DB::table('tbl_foods')->where('foodId', $id)->value('reviewStatus'));
    }

    public function test_plain_save_never_publishes(): void
    {
        $id = $this->food(['license' => 'Tự viết']);
        $this->as()->post("/admin/content/foods/$id", $this->body(['provenance' => 'own']))->assertSessionHas('success');
        $this->assertSame('draft', DB::table('tbl_foods')->where('foodId', $id)->value('reviewStatus'));
    }

    public function test_edit_page_lists_what_is_missing_in_plain_words(): void
    {
        $id = $this->food(['description' => 'ngắn']);
        $this->as()->get("/admin/content/foods/$id/edit")->assertOk()
            ->assertSee('Mô tả còn ngắn')
            ->assertSee('Chọn nguồn gốc nội dung')
            ->assertSee('Lưu và xuất bản');
    }
}
