<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Lịch sử phiên bản chuyến đi: mỗi lần lưu có một bản; khôi phục tạo bản mới, kiểm tra rev, chỉ người được sửa mới khôi phục được. */
class TripRevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $viewer;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('planner.require_revision', true);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'hue', 'name' => 'Huế', 'province' => 'Huế', 'domain' => 't',
            'description' => 'mô tả', 'latitude' => 16.46, 'longitude' => 107.59, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'hs', 'email' => 'hs@example.com', 'password' => 'x', 'fullName' => 'Chủ', 'isActive' => 'y']);
        $this->viewer = DB::table('tbl_users')->insertGetId(['username' => 'vw', 'email' => 'vw@example.com', 'password' => 'x', 'fullName' => 'Xem', 'isActive' => 'y']);
    }

    private function as(int $uid)
    {
        return $this->withSession(['userId' => $uid, 'username' => 'u' . $uid]);
    }

    private function save(int $id, int $rev, string $title, array $labels): int
    {
        $items = array_map(fn($l) => ['type' => 'note', 'label' => $l, 'day' => 1], $labels);

        return $this->as($this->uid)->postJson("/len-ke-hoach/$id/luu", ['title' => $title, 'days' => 1, 'groupSize' => 2, 'items' => $items, 'rev' => $rev])
            ->assertOk()->json('rev');
    }

    public function test_each_save_creates_a_revision_and_restore_creates_a_new_one(): void
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Huế', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
        $r1 = $this->save($id, 0, 'Bản 1', ['Đại Nội', 'Chùa Thiên Mụ']);
        $r2 = $this->save($id, $r1, 'Bản 2', ['Đại Nội']);   // lỡ xóa một mục
        $this->assertSame([1, 2], [$r1, $r2]);

        $h = $this->as($this->uid)->getJson("/len-ke-hoach/$id/lich-su")->assertOk()->json();
        $this->assertSame([2, 1], array_column($h['revisions'], 'rev'));
        $this->assertSame([1, 2], array_column($h['revisions'], 'items'));

        // khôi phục bản 1 từ rev cũ -> 409, từ rev hiện tại -> bản mới 3
        $this->as($this->uid)->postJson("/len-ke-hoach/$id/lich-su/1/khoi-phuc", ['rev' => 1])->assertStatus(409)->assertJsonPath('code', 'REVISION_CONFLICT');
        $this->as($this->uid)->postJson("/len-ke-hoach/$id/lich-su/1/khoi-phuc", ['rev' => 2])->assertOk()->assertJson(['ok' => true, 'rev' => 3]);

        $this->assertSame('Bản 1', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
        $this->assertSame(['Đại Nội', 'Chùa Thiên Mụ'], DB::table('tbl_trip_items')->where('tripId', $id)->orderBy('orderNo')->pluck('label')->all());
        $this->assertSame('restore', DB::table('tbl_trip_revisions')->where(['tripId' => $id, 'revisionNo' => 3])->value('changeType'));

        // khôi phục cũng hoàn tác được: quay lại bản 2
        $this->as($this->uid)->postJson("/len-ke-hoach/$id/lich-su/2/khoi-phuc", ['rev' => 3])->assertOk()->assertJsonPath('rev', 4);
        $this->assertSame(['Đại Nội'], DB::table('tbl_trip_items')->where('tripId', $id)->pluck('label')->all());
    }

    public function test_viewer_can_read_history_but_not_restore_and_stranger_gets_404(): void
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Huế', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
        $this->save($id, 0, 'Bản 1', ['A']);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $this->viewer, 'role' => 'view', 'invitedBy' => $this->uid]);

        $this->as($this->viewer)->getJson("/len-ke-hoach/$id/lich-su")->assertOk();
        $this->as($this->viewer)->postJson("/len-ke-hoach/$id/lich-su/1/khoi-phuc", ['rev' => 1])->assertForbidden();

        $stranger = DB::table('tbl_users')->insertGetId(['username' => 'st', 'email' => 'st@example.com', 'password' => 'x', 'fullName' => 'Lạ', 'isActive' => 'y']);
        $this->as($stranger)->getJson("/len-ke-hoach/$id/lich-su")->assertNotFound();
        $this->as($stranger)->postJson("/len-ke-hoach/$id/lich-su/1/khoi-phuc", ['rev' => 1])->assertNotFound();
    }

    public function test_show_previews_content_and_diff_against_current(): void
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Huế', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
        $r1 = $this->save($id, 0, 'Bản 1', ['Đại Nội', 'Chùa Thiên Mụ']);
        $this->save($id, $r1, 'Bản 2', ['Đại Nội', 'Lăng Tự Đức']);

        $j = $this->as($this->uid)->getJson("/len-ke-hoach/$id/lich-su/1")->assertOk()->json();
        $this->assertSame(1, $j['rev']);
        $this->assertSame([['day' => 1, 'items' => ['Đại Nội', 'Chùa Thiên Mụ']]], $j['days']);
        $this->assertSame(['Chùa Thiên Mụ'], $j['diff']['add']);
        $this->assertSame(['Lăng Tự Đức'], $j['diff']['remove']);
        $this->assertSame(['Tên chuyến: Bản 2 → Bản 1'], $j['diff']['meta']);
        $this->assertFalse($j['diff']['same']);

        $cur = $this->as($this->uid)->getJson("/len-ke-hoach/$id/lich-su/2")->assertOk()->json();
        $this->assertTrue($cur['diff']['same']);
        $this->as($this->uid)->getJson("/len-ke-hoach/$id/lich-su/99")->assertNotFound();
    }

    public function test_edit_page_offers_history_dialog(): void
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Huế', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
        $this->as($this->uid)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('Lịch sử phiên bản')->assertSee('id="tpHist"', false)->assertSee('planner-history.js', false)
            ->assertSee('"historyUrl":"' . url("/len-ke-hoach/$id/lich-su") . '"', false);
    }

    public function test_history_is_capped(): void
    {
        config()->set('planner.revision_history_keep', 5);
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Huế', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->dest]);
        $rev = 0;
        for ($i = 1; $i <= 8; $i++) {
            $rev = $this->save($id, $rev, 'Bản ' . $i, ['A' . $i]);
        }
        $this->assertSame([4, 5, 6, 7, 8], DB::table('tbl_trip_revisions')->where('tripId', $id)->orderBy('revisionNo')->pluck('revisionNo')->map(fn($x) => (int) $x)->all());
    }
}
