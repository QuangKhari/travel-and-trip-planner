<?php

namespace Tests\Feature;

use App\Services\Planner\PlaceSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Travela nhớ gì về bạn": xem, sửa, xóa sở thích đã học; tạm ngừng học; bộ xếp lịch dùng hồ sơ đã sửa. */
class TasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_edit_forget_and_pause(): void
    {
        $u = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
        $other = DB::table('tbl_users')->insertGetId(['username' => 'b', 'email' => 'b@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'd', 'name' => 'D', 'province' => 'P', 'domain' => 'b', 'description' => 'x', 'latitude' => 21, 'longitude' => 105, 'isActive' => 1]);
        $p = DB::table('tbl_external_places')->insertGetId(['destinationId' => $dest, 'source' => 'm', 'externalId' => 'x', 'name' => 'Bảo tàng Lịch sử', 'category' => 'sight', 'kind' => 'museum',
            'latitude' => 21, 'longitude' => 105, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        foreach (['added', 'visited', 'visited'] as $sg) {
            DB::table('tbl_place_signals')->insert(['userId' => $u, 'tripId' => 1, 'refType' => 'place', 'refId' => $p, 'grp' => 'culture', 'signal' => $sg, 'createdAt' => now()]);
        }
        DB::table('tbl_place_signals')->insert(['userId' => $u, 'tripId' => 1, 'refType' => 'place', 'refId' => $p, 'grp' => 'eat', 'signal' => 'skipped', 'createdAt' => now()]);
        $sig = app(PlaceSignals::class);
        $this->assertGreaterThan(0.5, $sig->profile($u)['culture']);
        $as = $this->withSession(['userId' => $u, 'username' => 'a']);
        $as->get('/len-ke-hoach/so-thich')->assertOk()->assertSee('Văn hóa')->assertSee('Bạn thích')->assertSee('Bảo tàng Lịch sử')->assertSee('2 lần đã ghé');

        // sửa: không thích văn hóa -> bộ xếp lịch nhận -0.8; bỏ qua ăn uống -> nhóm ăn uống không còn trong hồ sơ
        $as->post('/len-ke-hoach/so-thich', ['grp' => 'culture', 'value' => 'dislike'])->assertSessionHas('success');
        $as->post('/len-ke-hoach/so-thich', ['grp' => 'eat', 'value' => 'neutral'])->assertSessionHas('success');
        $pf = $sig->profile($u);
        $this->assertSame(-0.8, $pf['culture']);
        $this->assertArrayNotHasKey('eat', $pf);
        $as->post('/len-ke-hoach/so-thich', ['grp' => 'culture', 'value' => 'auto']);
        $this->assertGreaterThan(0.5, $sig->profile($u)['culture'], 'để Travela tự học: về lại điểm đã học');
        $as->post('/len-ke-hoach/so-thich', ['grp' => 'hack', 'value' => 'like'])->assertSessionHasErrors('grp');

        // tạm ngừng: lần lưu sau không ghi tín hiệu
        $as->post('/len-ke-hoach/so-thich/tam-dung', ['on' => 1]);
        $n = DB::table('tbl_place_signals')->count();
        $sig->recordSave($u, 1, [], [['type' => 'place', 'refId' => $p]]);
        $this->assertSame($n, DB::table('tbl_place_signals')->count());
        $as->post('/len-ke-hoach/so-thich/tam-dung', ['on' => 0]);
        $sig->recordSave($u, 1, [], [['type' => 'place', 'refId' => $p]]);
        $this->assertSame($n + 1, DB::table('tbl_place_signals')->count());

        // xóa một nhóm, rồi tất cả; chỉ của mình
        DB::table('tbl_place_signals')->insert(['userId' => $other, 'tripId' => 2, 'refType' => 'place', 'refId' => $p, 'grp' => 'culture', 'signal' => 'added', 'createdAt' => now()]);
        $as->post('/len-ke-hoach/so-thich/quen', ['grp' => 'eat']);
        $this->assertSame(0, DB::table('tbl_place_signals')->where(['userId' => $u, 'grp' => 'eat'])->count());
        $as->post('/len-ke-hoach/so-thich/quen', []);
        $this->assertSame(0, DB::table('tbl_place_signals')->where('userId', $u)->count());
        $this->assertSame(1, DB::table('tbl_place_signals')->where('userId', $other)->count());
        $this->assertSame([], $sig->profile($u));
    }
}
