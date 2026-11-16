<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Đợt F: giới hạn tốc độ đếm theo người đã đăng nhập, không theo IP (cả phòng thử chung một Wi-Fi). */
class ThrottlePerUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.loadtest_no_throttle' => false]);
        Route::middleware(['web', 'throttle:2,1,test-per-user'])->get('/_throttle-test', fn() => 'ok');
    }

    public function test_logged_in_users_on_same_ip_have_separate_limits(): void
    {
        foreach ([101, 102] as $uid) {
            $this->withSession(['userId' => $uid]);
            $this->get('/_throttle-test')->assertOk();
            $this->get('/_throttle-test')->assertOk();
            $this->get('/_throttle-test')->assertStatus(429);
        }
    }

    public function test_guests_still_limited_by_ip(): void
    {
        $this->get('/_throttle-test')->assertOk();
        $this->get('/_throttle-test')->assertOk();
        $this->get('/_throttle-test')->assertStatus(429);
    }
}
