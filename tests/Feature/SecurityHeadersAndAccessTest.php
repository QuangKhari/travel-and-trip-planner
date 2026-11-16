<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt F (F7): header bảo mật (A05) và không xem / sửa được chuyến của người khác bằng cách đổi số trong URL (A01). */
class SecurityHeadersAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_on_every_response(): void
    {
        $r = $this->get('/');
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $r->headers->get('Content-Security-Policy'));
        $this->assertFalse($r->headers->has('Strict-Transport-Security'));     // chỉ gửi qua HTTPS
    }

    public function test_cannot_reach_someone_elses_trip(): void
    {
        $mk = fn($n) => DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => "$n@t.test", 'password' => 'x', 'fullName' => $n, 'isActive' => 'y']);
        [$a, $b] = [$mk('chu_a'), $mk('ke_b')];
        $trip = app(TripPlanner::class)->create($a, ['title' => 'Riêng tư', 'days' => 2, 'groupSize' => 1]);
        $this->withSession(['userId' => $b, 'username' => 'ke_b']);
        $this->get("/len-ke-hoach/$trip")->assertNotFound();
        $this->getJson("/len-ke-hoach/$trip/thong-bao")->assertNotFound();
        $this->getJson("/len-ke-hoach/$trip/phien-ban")->assertNotFound();
        $this->postJson("/len-ke-hoach/$trip/la-ban/canh-bao/giai-thich", ['card' => 'alert:hours.closed:i1'])->assertNotFound();
        $this->assertSame('Riêng tư', DB::table('tbl_trips')->where('tripId', $trip)->value('title'));
        // chủ chuyến thì vào được
        $this->withSession(['userId' => $a, 'username' => 'chu_a']);
        $this->get("/len-ke-hoach/$trip")->assertOk();
    }

    public function test_study_pages_closed_to_non_participants(): void
    {
        config(['study.enabled' => true]);
        $u = DB::table('tbl_users')->insertGetId(['username' => 'khach', 'email' => 'k@t.test', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->withSession(['userId' => $u, 'username' => 'khach']);
        $this->getJson('/buoi-thu/trang-thai')->assertNotFound();
        $this->get('/buoi-thu/danh-gia')->assertNotFound();
    }
}
