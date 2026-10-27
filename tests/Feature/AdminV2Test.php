<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiểm tra nhanh admin v2: các route mới phải đòi đăng nhập (không lộ dữ liệu), và AdminBadges không làm sập khi bảng trống.
 * Chạy: php artisan test --filter=AdminV2Test
 */
class AdminV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_new_admin_routes_redirect_guests_to_login(): void
    {
        foreach (['admin.trips', 'admin.system', 'admin.booking.export', 'admin.dashboard'] as $name) {
            $this->get(route($name))->assertRedirect(route('admin.login'));
        }
    }

    public function test_new_admin_post_routes_reject_guests(): void
    {
        $this->post(route('admin.system.flush'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.system.views'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.trips.destroy', 1))->assertRedirect(route('admin.login'));
    }

    public function test_admin_badges_return_integers_on_empty_database(): void
    {
        $c = \App\Support\AdminBadges::counts();
        foreach (['booking', 'unpaid', 'contact', 'reviewed', 'draft'] as $k) {
            $this->assertIsInt($c[$k]);
            $this->assertSame(0, $c[$k]);
        }
    }
}
