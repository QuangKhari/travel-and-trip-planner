<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt D mục D5: demo:reset dựng lại đúng trạng thái, chạy lại nhiều lần như nhau, không đụng dữ liệu người khác. */
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_is_idempotent_and_leaves_other_users_alone(): void
    {
        $d = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'x', 'domain' => 'b', 'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.45, 'isActive' => 1]);
        foreach (['Chợ Đà Lạt', 'Hồ Xuân Hương', 'Thác Datanla', 'Ga Đà Lạt', 'Đồi chè Cầu Đất'] as $i => $n) {
            DB::table('tbl_external_places')->insert(['destinationId' => $d, 'source' => 'import', 'externalId' => "x$i", 'name' => $n, 'category' => 'sight',
                'latitude' => 11.94 + $i / 100, 'longitude' => 108.45, 'rank' => 5, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        }
        $other = DB::table('tbl_users')->insertGetId(['username' => 'nguoi_that', 'email' => 'that@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'T', 'isActive' => 'y']);
        $keep = app(TripPlanner::class)->create($other, ['title' => 'Chuyến thật', 'days' => 2, 'groupSize' => 2]);

        $this->artisan('demo:reset', ['--force' => true])->assertExitCode(0);
        $snap = fn() => [
            DB::table('tbl_users')->where('username', 'like', 'demo_%')->count(),
            DB::table('tbl_admin')->where('userName', 'like', 'demo_%')->count(),
            DB::table('tbl_trips')->join('tbl_users', 'tbl_users.userId', '=', 'tbl_trips.userId')->where('tbl_users.username', 'demo_khach')->count(),
            DB::table('tbl_tour_quotes')->count(),
            DB::table('tbl_import_batches')->where('note', config('demo.import_note'))->count(),
        ];
        $first = $snap();
        $this->assertSame([2, 3, 3, 1, 1], $first);
        $t1 = DB::table('tbl_trips')->where('title', 'Đà Lạt 3 ngày')->first();
        $this->assertSame(1, (int) date('N', strtotime($t1->startDate)));   // bắt đầu thứ Hai
        $this->assertGreaterThan(0, DB::table('tbl_trip_items')->where('tripId', $t1->tripId)->count());

        $this->artisan('demo:reset', ['--force' => true])->assertExitCode(0);
        $this->assertSame($first, $snap());
        $this->assertTrue(DB::table('tbl_trips')->where('tripId', $keep)->exists());

        // đăng nhập được bằng mật khẩu demo
        $this->post('/login', ['username' => 'demo_khach', 'password' => config('demo.password')]);
        $this->assertNotNull(session('userId'));
        $this->artisan('demo:check')->expectsOutputToContain('demo_khach');
    }
}
