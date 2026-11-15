<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sửa chung gần thời gian thực: ai đang mở chuyến, và bản mới gửi kèm để vẽ lại tại chỗ. */
class TripLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_and_snapshot_on_new_revision(): void
    {
        $mk = fn($n) => DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => ucfirst($n), 'isActive' => 'y']);
        [$a, $b, $c] = [$mk('an'), $mk('binh'), $mk('chi')];
        $trip = app(TripPlanner::class)->create($a, ['title' => 'Đà Lạt', 'days' => 2, 'groupSize' => 2, 'startDate' => null]);
        DB::table('tbl_trip_members')->insert(['tripId' => $trip, 'userId' => $b, 'role' => 'edit', 'createdAt' => now()] + (\Illuminate\Support\Facades\Schema::hasColumn('tbl_trip_members', 'status') ? ['status' => 'active'] : []));

        // An mở chuyến, đang xem một mục; Bình hỏi -> thấy An đang xem mục đó
        $this->withSession(['userId' => $a, 'username' => 'an'])->getJson("/len-ke-hoach/$trip/phien-ban?focus=place:12&editing=1")->assertOk()->assertJsonPath('present', []);
        $j = $this->withSession(['userId' => $b, 'username' => 'binh'])->getJson("/len-ke-hoach/$trip/phien-ban?since=0")->assertOk()->json();
        $this->assertSame([['userId' => $a, 'name' => 'An', 'focus' => 'place:12', 'editing' => true]], array_map(fn($p) => array_diff_key($p, ['ago' => 1]), $j['present']));
        $this->assertArrayNotHasKey('snapshot', $j, 'chưa có phiên bản mới thì không gửi lịch');

        // An lưu -> rev tăng -> Bình hỏi với since cũ nhận ngay danh sách mục mới
        $this->withSession(['userId' => $a, 'username' => 'an'])->postJson("/len-ke-hoach/$trip/luu", ['title' => 'Đà Lạt mới', 'days' => 2, 'groupSize' => 2, 'rev' => (int) $j['rev'],
            'items' => [['type' => 'note', 'label' => 'Ăn sáng', 'day' => 1]]])->assertOk();
        $j2 = $this->withSession(['userId' => $b, 'username' => 'binh'])->getJson("/len-ke-hoach/$trip/phien-ban?since=" . $j['rev'])->assertOk()->json();
        $this->assertGreaterThan($j['rev'], $j2['rev']);
        $this->assertSame('Đà Lạt mới', $j2['snapshot']['meta']['title']);
        $this->assertSame(['Ăn sáng'], array_column($j2['snapshot']['items'], 'name'));
        $this->assertSame('An', $j2['by']);

        // người ngoài chuyến: 404; tiêu điểm lạ bị bỏ
        $this->withSession(['userId' => $c, 'username' => 'chi'])->getJson("/len-ke-hoach/$trip/phien-ban")->assertNotFound();
        $this->withSession(['userId' => $a, 'username' => 'an'])->getJson("/len-ke-hoach/$trip/phien-ban?focus=<script>")->assertOk();
        $j3 = $this->withSession(['userId' => $b, 'username' => 'binh'])->getJson("/len-ke-hoach/$trip/phien-ban")->json();
        $this->assertNull($j3['present'][0]['focus']);
    }
}
