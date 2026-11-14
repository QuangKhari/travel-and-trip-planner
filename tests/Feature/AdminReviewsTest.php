<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Quản trị đánh giá: lọc, xem, ẩn / hiện (không tính vào điểm), xóa có ghi nhật ký. */
class AdminReviewsTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $tour;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
        $this->user = DB::table('tbl_users')->insertGetId(['fullName' => 'Hoa Nguyễn', 'username' => 'hoa', 'password' => 'x', 'email' => 'hoa@example.com', 'isActive' => 'y']);
        $this->tour = DB::table('tbl_tours')->insertGetId(['title' => 'Tour Huế', 'time' => '2 ngày', 'description' => 'x', 'quantity' => 5, 'priceAdult' => 1, 'priceChild' => 1,
            'destination' => 'Huế', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays(5)->toDateString(), 'endDate' => now()->addDays(6)->toDateString()]);
    }

    private function review(int $rating, string $comment, ?int $user = null): int
    {
        return DB::table('tbl_reviews')->insertGetId(['tourId' => $this->tour, 'userId' => $user ?? $this->user, 'rating' => $rating, 'comment' => $comment, 'timestamp' => now(), 'isHidden' => 0]);
    }

    private function ad(): static
    {
        return $this->withSession(['adminId' => $this->admin, 'adminRole' => 'admin']);
    }

    public function test_filter_hide_and_delete(): void
    {
        $good = $this->review(5, 'Hướng dẫn viên rất nhiệt tình');
        $bad = $this->review(1, 'Spam quảng cáo', DB::table('tbl_users')->insertGetId(['fullName' => 'Bot', 'username' => 'bot', 'password' => 'x', 'email' => 'bot@example.com', 'isActive' => 'y']));

        $this->ad()->get('/admin/reviews')->assertOk()->assertSee('Hướng dẫn viên rất nhiệt tình');
        $this->ad()->get('/admin/reviews?search=Spam')->assertOk()->assertSee('Spam quảng cáo')->assertDontSee('Hướng dẫn viên rất nhiệt tình');
        $this->ad()->get('/admin/reviews?rating=5')->assertOk()->assertDontSee('Spam quảng cáo');
        $this->ad()->get('/admin/reviews?search=Hoa')->assertOk()->assertSee('Hướng dẫn viên rất nhiệt tình')->assertDontSee('Spam quảng cáo');
        $this->ad()->get("/admin/reviews/$good")->assertOk();

        $this->ad()->post("/admin/reviews/$bad/toggle")->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, (int) DB::table('tbl_reviews')->where('reviewId', $bad)->value('isHidden'));
        $this->ad()->get('/admin/reviews?visibility=hidden')->assertOk()->assertSee('Spam quảng cáo')->assertDontSee('Hướng dẫn viên rất nhiệt tình');
        $this->ad()->post("/admin/reviews/$bad/toggle");
        $this->assertSame(0, (int) DB::table('tbl_reviews')->where('reviewId', $bad)->value('isHidden'));

        $this->ad()->delete("/admin/reviews/$bad")->assertRedirect();
        $this->assertFalse(DB::table('tbl_reviews')->where('reviewId', $bad)->exists());
        $this->assertTrue(DB::table('tbl_audit_log')->where('action', 'review.delete')->exists());

        $this->flushSession();
        $this->get('/admin/reviews')->assertRedirect();
    }
}
