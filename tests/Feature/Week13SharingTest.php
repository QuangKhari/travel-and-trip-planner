<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripSharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 13: mời bạn đồng hành theo tài khoản (xem/sửa), khóa phiên bản khi hai người sửa, thông tin hiển thị cho thẻ địa điểm. */
class Week13SharingTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $owner;
    private int $friend;
    private int $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->owner = $this->user('chu', 'Chủ Chuyến');
        $this->friend = $this->user('ban', 'Bạn Đồng Hành');
        $this->stranger = $this->user('la', 'Người Lạ');
    }

    private function user(string $n, string $full): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => $full, 'isActive' => 'y']);
    }

    private function actAs(int $uid): self
    {
        return $this->withSession(['userId' => $uid, 'username' => 'u' . $uid]);
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->owner, ['title' => 'Chuyến chung', 'days' => 2, 'groupSize' => 2, 'destinationId' => $this->hn]);
    }

    private function body(array $over = []): array
    {
        return array_replace(['title' => 'Chuyến chung', 'days' => 2, 'groupSize' => 2, 'items' => []], $over);
    }

    // ---- mời / quyền ------------------------------------------------------------------------------------

    public function test_owner_invites_by_email_and_member_sees_trip_in_list(): void
    {
        $id = $this->trip();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien", ['email' => 'BAN@example.com', 'role' => 'edit'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('edit', DB::table('tbl_trip_members')->where(['tripId' => $id, 'userId' => $this->friend])->value('role'));

        $this->actAs($this->friend)->get('/len-ke-hoach')->assertOk()->assertSee('Được mời vào')->assertSee('Chuyến chung')->assertSee('Được chỉnh sửa');
    }

    public function test_invite_rejects_unknown_email_self_and_non_owner(): void
    {
        $id = $this->trip();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien", ['email' => 'khong-co@example.com', 'role' => 'view'])
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien", ['email' => 'chu@example.com', 'role' => 'view'])->assertStatus(422);
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien", ['email' => 'ban@example.com', 'role' => 'admin'])->assertStatus(422);
        $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/thanh-vien", ['email' => 'la@example.com', 'role' => 'view'])->assertStatus(422);
        $this->assertSame(0, DB::table('tbl_trip_members')->count());
    }

    public function test_reinviting_changes_role_without_duplicate_and_owner_can_change_and_remove(): void
    {
        $id = $this->trip();
        $sh = app(TripSharing::class);
        $sh->invite($this->owner, $id, 'ban@example.com', 'view');
        $sh->invite($this->owner, $id, 'ban@example.com', 'edit');
        $this->assertSame(1, DB::table('tbl_trip_members')->count());
        $this->assertSame('edit', DB::table('tbl_trip_members')->value('role'));

        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien/{$this->friend}", ['role' => 'view'])->assertOk();
        $this->assertSame('view', DB::table('tbl_trip_members')->value('role'));
        $this->actAs($this->stranger)->postJson("/len-ke-hoach/$id/thanh-vien/{$this->friend}", ['role' => 'edit'])->assertNotFound();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/thanh-vien/{$this->friend}/go")->assertOk();
        $this->assertSame(0, DB::table('tbl_trip_members')->count());
    }

    public function test_member_can_leave_but_not_remove_others_and_form_leave_redirects(): void
    {
        $id = $this->trip();
        $third = $this->user('ba', 'Người Ba');
        $sh = app(TripSharing::class);
        $sh->invite($this->owner, $id, 'ban@example.com', 'edit');
        $sh->invite($this->owner, $id, 'ba@example.com', 'view');

        $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/thanh-vien/$third/go")->assertNotFound();
        $this->assertSame(2, DB::table('tbl_trip_members')->count());
        $this->actAs($this->friend)->post("/len-ke-hoach/$id/thanh-vien/{$this->friend}/go")->assertRedirect('/len-ke-hoach');
        $this->assertSame(1, DB::table('tbl_trip_members')->count());
    }

    public function test_member_cap(): void
    {
        $id = $this->trip();
        $sh = app(TripSharing::class);
        for ($i = 0; $i < TripSharing::MAX_MEMBERS; $i++) {
            $this->user("m$i", "M$i");
            $this->assertTrue($sh->invite($this->owner, $id, "m$i@example.com", 'view')['ok']);
        }
        $this->assertFalse($sh->invite($this->owner, $id, 'ban@example.com', 'view')['ok']);
        $this->assertTrue($sh->invite($this->owner, $id, 'm0@example.com', 'edit')['ok'], 'đổi quyền người đã có vẫn được');
    }

    // ---- quyền truy cập ---------------------------------------------------------------------------------

    public function test_stranger_cannot_open_or_edit(): void
    {
        $id = $this->trip();
        $this->actAs($this->stranger)->get("/len-ke-hoach/$id")->assertNotFound();
        $this->actAs($this->stranger)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertForbidden();
        $this->actAs($this->stranger)->getJson("/len-ke-hoach/$id/phien-ban")->assertNotFound();
        $this->actAs($this->stranger)->getJson("/len-ke-hoach/$id/thanh-vien")->assertNotFound();
    }

    public function test_view_member_can_open_but_not_save_or_search(): void
    {
        $id = $this->trip();
        app(TripSharing::class)->invite($this->owner, $id, 'ban@example.com', 'view');

        $this->actAs($this->friend)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Chỉ xem');
        $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Bị sửa']))->assertForbidden();
        $this->assertSame('Chuyến chung', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
        $this->actAs($this->friend)->getJson("/len-ke-hoach/$id/tim?q=ha")->assertNotFound();
        $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/chia-se", ['on' => 1])->assertNotFound();
        $this->actAs($this->friend)->post("/len-ke-hoach/$id/xoa")->assertNotFound();
        $this->assertTrue(DB::table('tbl_trips')->where('tripId', $id)->exists());
    }

    public function test_edit_member_can_save_and_revision_and_last_editor_update(): void
    {
        $id = $this->trip();
        app(TripSharing::class)->invite($this->owner, $id, 'ban@example.com', 'edit');

        $r = $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Bạn sửa', 'rev' => 0]))->assertOk();
        $this->assertSame(1, $r->json('rev'));
        $this->assertSame('Bạn sửa', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
        $this->assertSame($this->friend, (int) DB::table('tbl_trips')->where('tripId', $id)->value('lastEditorId'));

        $v = $this->actAs($this->owner)->getJson("/len-ke-hoach/$id/phien-ban")->assertOk()->json();
        $this->assertSame(1, $v['rev']);
        $this->assertSame('Bạn Đồng Hành', $v['by']);
        $this->assertSame('owner', $v['role']);
    }

    public function test_stale_revision_returns_409_and_does_not_overwrite(): void
    {
        $id = $this->trip();
        app(TripSharing::class)->invite($this->owner, $id, 'ban@example.com', 'edit');
        $this->actAs($this->friend)->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Bản của bạn', 'rev' => 0]))->assertOk();

        // chủ chuyến còn giữ rev=0 (đã tải trước đó) rồi lưu: bị chặn
        $r = $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Bản của chủ', 'rev' => 0]))->assertStatus(409);
        $this->assertTrue($r->json('conflict'));
        $this->assertSame('Bạn Đồng Hành', $r->json('by'));
        $this->assertSame('Bản của bạn', DB::table('tbl_trips')->where('tripId', $id)->value('title'));

        // tải lại (rev=1) rồi lưu thì được
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body(['title' => 'Bản của chủ', 'rev' => 1]))->assertOk();
        $this->assertSame('Bản của chủ', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }

    public function test_save_without_rev_still_works_for_old_clients_and_bumps_rev(): void
    {
        $id = $this->trip();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertOk();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body())->assertOk();
        $this->assertSame(2, (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev'));
    }

    public function test_members_list_hides_emails_from_non_owners(): void
    {
        $id = $this->trip();
        app(TripSharing::class)->invite($this->owner, $id, 'ban@example.com', 'view');

        $o = $this->actAs($this->owner)->getJson("/len-ke-hoach/$id/thanh-vien")->assertOk()->json();
        $this->assertSame('owner', $o['role']);
        $this->assertSame(['chu@example.com', 'ban@example.com'], array_column($o['members'], 'email'));

        $m = $this->actAs($this->friend)->getJson("/len-ke-hoach/$id/thanh-vien")->assertOk()->json();
        $this->assertSame('view', $m['role']);
        $this->assertSame([null, null], array_column($m['members'], 'email'));
    }

    public function test_deleting_trip_removes_memberships(): void
    {
        $id = $this->trip();
        app(TripSharing::class)->invite($this->owner, $id, 'ban@example.com', 'view');
        $this->actAs($this->owner)->post("/len-ke-hoach/$id/xoa")->assertRedirect();
        $this->assertSame(0, DB::table('tbl_trip_members')->count());
    }

    // ---- dữ liệu cho thẻ địa điểm -----------------------------------------------------------------------

    public function test_item_payload_carries_address_website_and_blurb_for_places(): void
    {
        $pid = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'overpass', 'externalId' => 'node/1', 'name' => 'Hồ Gươm',
            'category' => 'see', 'kind' => 'attraction', 'address' => '1 Đinh Tiên Hoàng', 'website' => 'https://example.com/ho-guom', 'description' => '<p>Hồ giữa lòng Hà Nội.</p>',
            'latitude' => 21.0288, 'longitude' => 105.852, 'rank' => 5, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        $id = $this->trip();
        $this->actAs($this->owner)->postJson("/len-ke-hoach/$id/luu", $this->body(['items' => [['type' => 'place', 'refId' => $pid, 'day' => 1]]]))->assertOk();

        $p = app(TripPlanner::class)->payload(app(TripSharing::class)->access($this->owner, $id)['trip']);
        $it = $p['days'][0]['items'][0];
        $this->assertSame('1 Đinh Tiên Hoàng', $it['address']);
        $this->assertSame('https://example.com/ho-guom', $it['website']);
        $this->assertSame('Hồ giữa lòng Hà Nội.', $it['blurb']);
        $this->assertNull($it['image'], 'không có ảnh thì null, không bịa');

        $client = TripPlanner::itemForClient($it);
        $this->assertArrayHasKey('blurb', $client);
    }

    public function test_edit_page_embeds_role_rev_and_map_config(): void
    {
        config(['services.google_maps.key' => 'TESTKEY']);
        $id = $this->trip();
        $html = $this->actAs($this->owner)->get("/len-ke-hoach/$id")->assertOk()->assertSee('Mời bạn đồng hành')->getContent();
        $this->assertStringContainsString('"role":"owner"', $html);
        $this->assertStringContainsString('"rev":0', $html);
        $this->assertStringContainsString('"googleMapsKey":"TESTKEY"', $html);
    }
}
