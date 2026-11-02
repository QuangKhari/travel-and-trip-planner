<?php

namespace Tests\Feature;

use App\Services\Planner\TripCommunity;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cộng đồng hành trình (T-24…T-35) và khóa mềm khi cộng tác (T-40). */
class CommunityTripsTest extends TestCase
{
    use RefreshDatabase;

    private int $dl;
    private int $hn;
    private int $owner;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dl = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'n',
            'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $this->owner = $this->user('chu', 'Nguyễn Văn An');
        $this->other = $this->user('khach', 'Trần Thị Bình');
    }

    private function user(string $n, string $full): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => $full, 'isActive' => 'y']);
    }

    private function actAs(int $uid): self
    {
        return $this->withSession(['userId' => $uid, 'username' => 'u' . $uid]);
    }

    private function trip(array $o = [], int $placed = 3): int
    {
        $id = app(TripPlanner::class)->create($o['userId'] ?? $this->owner, array_merge(['title' => 'Đà Lạt 3 ngày', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->dl, 'budget' => 6000000], $o));
        for ($i = 1; $i <= $placed; $i++) {
            DB::table('tbl_trip_items')->insert(['tripId' => $id, 'refType' => 'note', 'refId' => null, 'label' => 'Điểm ' . $i, 'dayNo' => min($i, 3), 'orderNo' => $i,
                'cost' => 100000, 'costType' => 'actual']);
        }

        return $id;
    }

    private function publish(int $id, ?int $uid = null): void
    {
        $this->assertNull(app(TripCommunity::class)->publish($uid ?? $this->owner, $id, 'Lịch trình nhẹ nhàng'));
    }

    // ---- công khai ------------------------------------------------------------------------------------

    public function test_owner_publishes_from_editor_and_trip_appears_on_community(): void
    {
        $id = $this->trip();
        $this->actAs($this->owner)->post("/len-ke-hoach/$id/cong-dong", ['action' => 'publish', 'summary' => 'Đi chậm, nhiều quán cà phê'])->assertRedirect();
        $row = DB::table('tbl_trips')->where('tripId', $id)->first();
        $this->assertSame(1, (int) $row->isPublic);
        $this->assertNotNull($row->publishedAt);

        $this->get('/cong-dong')->assertOk()->assertSee('Đà Lạt 3 ngày')->assertSee('Đi chậm, nhiều quán cà phê')
            ->assertSee('N. An')->assertDontSee('Nguyễn Văn An');   // chỉ tên rút gọn
        $this->get("/cong-dong/$id")->assertOk()->assertSee('Điểm 1')->assertSee('Dùng hành trình này');
    }

    public function test_cannot_publish_empty_trip_or_someone_elses_trip(): void
    {
        $empty = $this->trip([], 1);
        $this->assertNotNull(app(TripCommunity::class)->publish($this->owner, $empty, null));
        $id = $this->trip();
        $this->assertNotNull(app(TripCommunity::class)->publish($this->other, $id, null));
        $this->actAs($this->other)->postJson("/len-ke-hoach/$id/cong-dong", ['action' => 'publish'])->assertStatus(422);
        $this->assertSame(0, (int) DB::table('tbl_trips')->where('isPublic', 1)->count());
    }

    public function test_private_trip_is_not_visible_on_community(): void
    {
        $id = $this->trip();
        $this->get("/cong-dong/$id")->assertNotFound();
        $this->get('/cong-dong')->assertOk()->assertDontSee('Đà Lạt 3 ngày');
    }

    public function test_filters_and_tabs(): void
    {
        $a = $this->trip(['title' => 'Đà Lạt ngắn', 'days' => 2, 'budget' => 2000000]);      // 1tr/người
        $b = $this->trip(['title' => 'Hà Nội dài', 'days' => 6, 'destinationId' => $this->hn, 'budget' => 16000000]);  // 8tr/người
        $this->publish($a);
        $this->publish($b);
        DB::table('tbl_trips')->where('tripId', $b)->update(['likeCount' => 9, 'publishedAt' => now()->subDays(10)]);

        $c = app(TripCommunity::class);
        $this->assertSame([$a], array_map(fn($r) => (int) $r->tripId, $c->feed(['dest' => $this->dl])['rows']));
        $this->assertSame([$b], array_map(fn($r) => (int) $r->tripId, $c->feed(['days' => '5-7'])['rows']));
        $this->assertSame([$a], array_map(fn($r) => (int) $r->tripId, $c->feed(['budget' => 'duoi-2tr'])['rows']));
        $this->assertSame([$b], array_map(fn($r) => (int) $r->tripId, $c->feed(['q' => 'hà nội'])['rows']));
        $this->assertSame($b, (int) $c->feed(['tab' => 'yeu-thich'])['rows'][0]->tripId);
        $this->assertSame($a, (int) $c->feed(['tab' => 'moi-nhat'])['rows'][0]->tripId);

        $this->get('/cong-dong?days=5-7&tab=yeu-thich')->assertOk()->assertSee('Hà Nội dài')->assertDontSee('Đà Lạt ngắn');
        $this->get('/cong-dong?days=99')->assertRedirect();   // giá trị lọc lạ bị từ chối
    }

    public function test_like_is_once_per_user_and_toggles(): void
    {
        $id = $this->trip();
        $this->publish($id);
        $this->actAs($this->other)->postJson("/cong-dong/$id/thich")->assertOk()->assertJson(['liked' => true, 'likes' => 1]);
        $this->actAs($this->owner)->postJson("/cong-dong/$id/thich")->assertOk()->assertJson(['liked' => true, 'likes' => 2]);
        $this->actAs($this->other)->postJson("/cong-dong/$id/thich")->assertOk()->assertJson(['liked' => false, 'likes' => 1]);
        $this->assertSame(1, (int) DB::table('tbl_trips')->where('tripId', $id)->value('likeCount'));
        // khách chưa đăng nhập không thích được
        $this->flushSession();
        $this->post("/cong-dong/$id/thich")->assertRedirect();
        $this->assertSame(1, DB::table('tbl_trip_likes')->count());
    }

    public function test_view_counted_once_per_session_and_not_for_owner(): void
    {
        $id = $this->trip();
        $this->publish($id);
        $this->actAs($this->owner)->get("/cong-dong/$id")->assertOk();
        $this->assertSame(0, (int) DB::table('tbl_trips')->where('tripId', $id)->value('viewCount'));
        $this->flushSession();
        $this->get("/cong-dong/$id");
        $this->get("/cong-dong/$id");
        $this->assertSame(1, (int) DB::table('tbl_trips')->where('tripId', $id)->value('viewCount'));
    }

    public function test_use_trip_clones_with_personalization_and_tracks_source(): void
    {
        $id = $this->trip();
        DB::table('tbl_trips')->where('tripId', $id)->update(['shareToken' => str_repeat('a', 32)]);
        $this->publish($id);
        $date = now()->addDays(20)->toDateString();
        $res = $this->actAs($this->other)->post("/cong-dong/$id/dung-lai", ['title' => 'Đà Lạt của Bình', 'startDate' => $date, 'groupSize' => 4, 'budget' => 12000000]);
        $new = (int) DB::table('tbl_trips')->where('userId', $this->other)->value('tripId');
        $res->assertRedirect(route('planner.edit', $new));

        $t = DB::table('tbl_trips')->where('tripId', $new)->first();
        $this->assertSame('Đà Lạt của Bình', $t->title);
        $this->assertSame($date, substr((string) $t->startDate, 0, 10));
        $this->assertSame(4, (int) $t->groupSize);
        $this->assertSame(12000000, (int) $t->budget);
        $this->assertSame('CLONED', $t->sourceType);
        $this->assertSame($id, (int) $t->sourceTripId);
        $this->assertSame(0, (int) $t->isPublic);
        $this->assertNull($t->shareToken);
        $this->assertSame(3, DB::table('tbl_trip_items')->where('tripId', $new)->count());
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $new)->where('costType', 'actual')->count());
        $this->assertSame(1, (int) DB::table('tbl_trips')->where('tripId', $id)->value('cloneCount'));
        $this->assertSame(0, DB::table('tbl_booking')->count());   // T-31: không kéo theo đơn đặt tour

        // bản sao sửa thoải mái, bản gốc giữ nguyên
        DB::table('tbl_trip_items')->where('tripId', $new)->delete();
        $this->assertSame(3, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_use_trip_validates_and_requires_public_source(): void
    {
        $id = $this->trip();
        $this->actAs($this->other)->post("/cong-dong/$id/dung-lai", ['groupSize' => 2])->assertRedirect(route('community.index'));
        $this->publish($id);
        $this->actAs($this->other)->post("/cong-dong/$id/dung-lai", ['groupSize' => 2, 'startDate' => now()->subDay()->toDateString()])->assertSessionHasErrors('startDate');
        $this->actAs($this->other)->post("/cong-dong/$id/dung-lai", ['groupSize' => 0])->assertSessionHasErrors('groupSize');
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->other)->count());
    }

    public function test_unpublish_keeps_existing_clones_and_admin_can_take_down(): void
    {
        $id = $this->trip();
        $this->publish($id);
        $new = app(TripCommunity::class)->cloneTrip($this->other, $id, ['groupSize' => 2]);
        $this->actAs($this->owner)->post("/len-ke-hoach/$id/cong-dong", ['action' => 'unpublish'])->assertRedirect();
        $this->assertSame(0, (int) DB::table('tbl_trips')->where('tripId', $id)->value('isPublic'));
        $this->assertTrue(DB::table('tbl_trips')->where('tripId', $new)->exists());
        $this->get("/cong-dong/$id")->assertNotFound();

        // quản trị gỡ: ghi nhật ký, chuyến vẫn còn
        $this->publish($id);
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'qtv', 'passWord' => 'x', 'email' => 'qtv@example.com', 'fullName' => 'QTV', 'address' => 'HN', 'role' => 'manager']);
        $this->withSession(['adminId' => $admin])
            ->post("/admin/trips/$id/unpublish", ['reason' => 'Nội dung quảng cáo'])->assertRedirect();
        $this->assertSame(0, (int) DB::table('tbl_trips')->where('tripId', $id)->value('isPublic'));
        $this->assertTrue(DB::table('tbl_audit_log')->where('action', 'trip.unpublish')->exists());
    }

    public function test_blocked_author_trips_are_hidden(): void
    {
        $id = $this->trip();
        $this->publish($id);
        DB::table('tbl_users')->where('userId', $this->owner)->update(['status' => 'b']);
        $this->get("/cong-dong/$id")->assertNotFound();
    }

    // ---- khóa mềm (T-40) -----------------------------------------------------------------------------

    private function body(): array
    {
        return ['title' => 'Đà Lạt 3 ngày', 'days' => 3, 'groupSize' => 2, 'items' => []];
    }

    public function test_solo_trip_needs_no_lock(): void
    {
        $id = $this->trip([], 0);
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/khoa")->assertOk()->assertJson(['solo' => true, 'mine' => true]);
        $this->assertSame(0, DB::table('tbl_trip_locks')->count());
    }

    public function test_collaborative_trip_lets_one_editor_at_a_time_and_lock_expires(): void
    {
        $id = $this->trip([], 0);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $this->other, 'role' => 'edit', 'createdAt' => now()]);

        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/khoa")->assertOk()->assertJson(['mine' => true, 'solo' => false]);
        $this->actAs($this->other)->postJson("/len-ke-hoach/$id/khoa")->assertOk()->assertJson(['mine' => false, 'holder' => 'Nguyễn Văn An']);
        $this->actAs($this->other)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertStatus(423)->assertJson(['locked' => true]);
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertOk();

        // người giữ khóa im lặng quá 5 phút -> người kia nhận khóa
        $this->travel(6)->minutes();
        $this->actAs($this->other)->postJson("/len-ke-hoach/$id/khoa")->assertOk()->assertJson(['mine' => true]);
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertStatus(423);

        // nhả khóa khi rời trang
        $this->actAs($this->other)->post("/len-ke-hoach/$id/khoa", ['action' => 'release'])->assertOk();
        $this->assertSame(0, DB::table('tbl_trip_locks')->count());
        $this->actAs($this->user('la', 'Người Lạ'))->postJson("/len-ke-hoach/$id/khoa")->assertNotFound();
    }
}
