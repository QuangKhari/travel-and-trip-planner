<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin > Cảnh báo điểm đến: thêm / sửa / tắt, kiểm tra ngày, đếm chuyến bị ảnh hưởng; nhân viên (staff) không vào được. */
class TravelAlertAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role): int
    {
        return DB::table('tbl_admin')->insertGetId(['userName' => $role . uniqid(), 'passWord' => 'x', 'email' => $role . uniqid() . '@example.com', 'fullName' => 'A', 'address' => 'HN', 'role' => $role]);
    }

    public function test_manager_creates_updates_and_toggles_alert_and_sees_affected_trips(): void
    {
        $dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'n', 'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'ta1', 'email' => 'ta1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        DB::table('tbl_trips')->insert(['userId' => $uid, 'title' => 'Đà Lạt', 'destinationId' => $dest, 'startDate' => '2026-12-20', 'days' => 3, 'createdAt' => now()]);
        DB::table('tbl_trips')->insert(['userId' => $uid, 'title' => 'Đà Lạt sau', 'destinationId' => $dest, 'startDate' => '2027-01-20', 'days' => 3, 'createdAt' => now()]);
        $s = ['adminId' => $this->admin('manager')];

        $this->withSession($s)->post('/admin/travel-alerts', ['title' => 'Đèo Prenn', 'kind' => 'traffic', 'level' => 'warn', 'startsOn' => '2026-12-22', 'endsOn' => '2026-12-21'])
            ->assertSessionHasErrors('endsOn');
        $this->withSession($s)->post('/admin/travel-alerts', ['title' => 'Đèo Prenn', 'kind' => 'traffic', 'level' => 'warn', 'startsOn' => '2026-12-22'])
            ->assertSessionHasErrors('destinationId');
        $this->withSession($s)->post('/admin/travel-alerts', ['title' => 'Đèo Prenn phân luồng', 'kind' => 'traffic', 'level' => 'warn', 'destinationId' => $dest,
            'startsOn' => '2026-12-22', 'endsOn' => '2026-12-23', 'sourceUrl' => 'https://example.com/tin'])->assertRedirect(route('admin.travel-alerts'));
        $id = (int) DB::table('tbl_travel_alerts')->value('alertId');

        $this->withSession($s)->get('/admin/travel-alerts')->assertOk()->assertSee('Đèo Prenn phân luồng')->assertSee('1 chuyến');
        $this->withSession($s)->post("/admin/travel-alerts/$id", ['title' => 'Đèo Prenn đóng', 'kind' => 'traffic', 'level' => 'bad', 'destinationId' => $dest, 'startsOn' => '2026-12-22'])
            ->assertRedirect();
        $this->assertSame('bad', DB::table('tbl_travel_alerts')->where('alertId', $id)->value('level'));
        $this->withSession($s)->get('/admin/travel-alerts')->assertSee('2 chuyến');   // không có ngày kết thúc: chạm cả chuyến tháng 1
        $this->withSession($s)->post("/admin/travel-alerts/$id/toggle")->assertSessionHas('success');
        $this->assertSame(0, (int) DB::table('tbl_travel_alerts')->where('alertId', $id)->value('isActive'));
        $this->withSession($s)->get('/admin/dashboard')->assertOk()->assertSee('Cảnh báo điểm đến đang hiệu lực');
    }

    public function test_staff_cannot_manage_alerts(): void
    {
        $this->withSession(['adminId' => $this->admin('staff')])->get('/admin/travel-alerts')->assertStatus(302);
    }
}
