<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Revision bắt buộc khi lưu Trip (mặc định production: planner.require_revision = true).
 * - Thiếu rev -> 422, dữ liệu không đổi.
 * - Hai tab cùng sửa từ rev N: tab lưu sau nhận 409 REVISION_CONFLICT, không ghi đè tab trước.
 * - API /api/v1/trips/{id} cùng quy tắc.
 */
class RevisionRequiredTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('planner.require_revision', true);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'rv', 'email' => 'rv@example.com',
            'password' => PasswordHasher::make('12345678'), 'fullName' => 'Rev User', 'isActive' => 'y']);
    }

    public function test_config_default_requires_revision(): void
    {
        // Đọc giá trị mặc định trong file cấu hình khi không có biến môi trường ghi đè.
        $src = file_get_contents(config_path('planner.php'));
        $this->assertMatchesRegularExpression("/'require_revision'\s*=>\s*env\('PLANNER_REQUIRE_REVISION',\s*true\)/", $src);
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Gốc', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
    }

    private function body(array $over = []): array
    {
        return array_replace(['title' => 'Gốc', 'days' => 1, 'groupSize' => 2, 'items' => []], $over);
    }

    private function web()
    {
        return $this->withSession(['userId' => $this->uid, 'username' => 'rv']);
    }

    public function test_web_save_without_rev_is_rejected(): void
    {
        $id = $this->trip();
        $this->web()->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Không rev']))
            ->assertStatus(422)->assertJsonValidationErrors('rev');
        $this->assertSame('Gốc', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }

    public function test_two_tabs_from_same_revision_second_gets_conflict(): void
    {
        $id = $this->trip();
        // Tab A và tab B cùng mở ở rev 0
        $this->web()->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Tab A', 'rev' => 0]))
            ->assertOk()->assertJsonPath('rev', 1);
        $this->web()->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Tab B', 'rev' => 0]))
            ->assertStatus(409)->assertJson(['code' => 'REVISION_CONFLICT', 'conflict' => true, 'rev' => 1]);

        $this->assertSame('Tab A', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
        $this->assertSame(1, (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev'));

        // Tab B tải lại (rev 1) rồi lưu được
        $this->web()->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Tab B', 'rev' => 1]))
            ->assertOk()->assertJsonPath('rev', 2);
    }

    public function test_api_put_requires_rev(): void
    {
        $id = $this->trip();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'rv', 'password' => '12345678'])->assertOk()->json('access_token');
        $h = ['Authorization' => "Bearer $token"];
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/trips/$id", $this->body(['title' => 'API thiếu rev']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('rev');
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/trips/$id", $this->body(['title' => 'API', 'rev' => 0]), $h)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/trips/$id", $this->body(['title' => 'API cũ', 'rev' => 0]), $h)
            ->assertStatus(409)->assertJsonPath('code', 'REVISION_CONFLICT');
        $this->assertSame('API', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }
}
