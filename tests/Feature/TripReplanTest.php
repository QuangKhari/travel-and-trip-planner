<?php

namespace Tests\Feature;

use App\Services\Planner\Health\TripResolver;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Mục C/Tầng 3 "Lập lại kế hoạch chủ động khi có biến động" (kịch bản: địa điểm trong lịch bị Travela gỡ).
 * Sự kiện PlaceUnpublished -> thông báo kèm số phương án -> người dùng bấm "Xem phương án" -> máy chủ tính trên bản mới nhất
 * 2–3 phương án đã mô phỏng bằng TripHealth (so sánh trước / sau: độ dày, km, giờ kết thúc, chi phí) -> người dùng chọn Áp dụng.
 * Lịch KHÔNG tự đổi.
 */
class TripReplanTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'Thủ đô', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'rp', 'email' => 'rp@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'rp'];
    }

    private function place(string $name, float $lat, float $lng, string $kind = 'theatre'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'see', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function setupTrip(): array
    {
        $opera = $this->place('Nhà hát Lớn', 21.024, 105.857);
        $puppet = $this->place('Nhà hát Múa rối Thăng Long', 21.029, 105.853);   // cùng loại, ~0,7 km
        $far = $this->place('Nhà hát Ở Xa', 21.20, 105.70);                       // quá 5 km: không đề xuất
        $museum = $this->place('Bảo tàng Lịch sử', 21.025, 105.859, 'museum');
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$trip/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'items' => [
            ['type' => 'place', 'refId' => $museum, 'day' => 2],
            ['type' => 'place', 'refId' => $opera, 'day' => 2, 'cost' => 300000],
        ]])->assertOk();

        return compact('opera', 'puppet', 'far', 'museum', 'trip');
    }

    public function test_unpublishing_a_place_prepares_options_and_feed_links_to_them_without_changing_the_plan(): void
    {
        $x = $this->setupTrip();
        $before = DB::table('tbl_trip_items')->where('tripId', $x['trip'])->orderBy('itemId')->get(['refId', 'dayNo', 'orderNo'])->toArray();
        $rev = (int) DB::table('tbl_trips')->where('tripId', $x['trip'])->value('rev');
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ct', 'passWord' => 'x', 'email' => 'ct@example.com', 'fullName' => 'Biên tập', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->post('/admin/content/places/' . $x['opera'] . '/status', ['status' => 'draft'])->assertRedirect();

        $ev = DB::table('tbl_trip_events')->where(['tripId' => $x['trip'], 'kind' => 'content'])->first();
        $this->assertStringContainsString('Travela đã chuẩn bị 2 phương án', $ev->text);
        $this->assertStringContainsString('chỉ đổi khi bạn bấm Áp dụng', $ev->text);
        $this->assertEquals($before, DB::table('tbl_trip_items')->where('tripId', $x['trip'])->orderBy('itemId')->get(['refId', 'dayNo', 'orderNo'])->toArray(), 'lịch không tự đổi');
        $this->assertSame($rev, (int) DB::table('tbl_trips')->where('tripId', $x['trip'])->value('rev'));

        $feed = app(TripWatch::class)->feed($x['trip'], $this->uid);
        $this->assertSame(['type' => 'resolve', 'code' => 'content.unavailable', 'refType' => 'place', 'refId' => $x['opera']], $feed['events'][0]['action']);
    }

    public function test_resolver_offers_similar_nearby_place_and_inbox_with_before_after_comparison(): void
    {
        $x = $this->setupTrip();
        DB::table('tbl_external_places')->where('placeId', $x['opera'])->update(['reviewStatus' => 'draft']);
        $trip = DB::table('tbl_trips')->where('tripId', $x['trip'])->first();

        // từ chuông: chỉ đúng mục (ngày 2, vị trí 1); từ bảng Sức khỏe: không chỉ mục -> lấy mục không còn xác thực đầu tiên
        foreach ([[2, 1], [null, null]] as [$d, $i]) {
            $r = app(TripResolver::class)->forTrip($trip, 'content.unavailable', $d, $i);
            $this->assertSame(2, $r['day']);
            $this->assertSame(1, $r['index']);
            $this->assertStringContainsString('Nhà hát Lớn', $r['problem']);
            $this->assertSame(['replace', 'inbox'], array_column($r['options'], 'id'));
        }
        [$rep, $inbox] = $r['options'];
        $this->assertSame('Thay bằng “Nhà hát Múa rối Thăng Long”', $rep['label'], 'nơi cùng loại gần nhất; nơi > 5 km và nơi khác loại không được chọn');
        $this->assertTrue($rep['resolves']);
        $this->assertTrue($inbox['resolves']);
        $this->assertSame(['op' => 'replace', 'day' => 2, 'index' => 1], array_intersect_key($rep['changes'][0], ['op' => 1, 'day' => 1, 'index' => 1]));

        // so sánh trước / sau cho người dùng duyệt: độ dày, km, giờ kết thúc, chi phí
        $fx = $inbox['effects'][0];
        $this->assertSame(2, $fx['day']);
        $this->assertSame([2, 1], [$fx['beforeCount'], $fx['afterCount']]);
        $this->assertSame([300000, 0], [$fx['beforeCost'], $fx['afterCost']], 'bỏ mục có chi phí -> chi phí ngày giảm');
        $this->assertNotNull($fx['beforeEnd']);
        $this->assertLessThan($fx['beforeEnd'], $fx['afterEnd']);
        $this->assertArrayHasKey('afterKm', $rep['effects'][0]);

        // HTTP: cùng điểm vào với nút "Xem cách xử lý"
        $j = $this->withSession($this->s)->getJson('/len-ke-hoach/' . $x['trip'] . '/suc-khoe/xu-ly?code=content.unavailable&day=2&index=1')->assertOk()->json();
        $this->assertCount(2, $j['options']);
    }

    public function test_applying_the_replacement_and_saving_clears_the_warning_and_keeps_old_item_in_inbox(): void
    {
        $x = $this->setupTrip();
        DB::table('tbl_external_places')->where('placeId', $x['opera'])->update(['reviewStatus' => 'draft']);
        $trip = DB::table('tbl_trips')->where('tripId', $x['trip'])->first();
        $rep = app(TripResolver::class)->forTrip($trip, 'content.unavailable', 2, 1)['options'][0];

        // trình duyệt áp dụng đúng thao tác rồi lưu (giống planner-resolve.js): mục mới ở ngày 2, mục cũ về Đã lưu
        $this->withSession($this->s)->postJson('/len-ke-hoach/' . $x['trip'] . '/luu', ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'rev' => (int) $trip->rev, 'items' => [
                ['type' => 'place', 'refId' => $x['museum'], 'day' => 2],
                ['type' => 'place', 'refId' => $rep['changes'][0]['with']['refId'], 'day' => 2],
                ['type' => 'place', 'refId' => $x['opera'], 'day' => null],
            ]])->assertOk();
        $after = app(TripResolver::class)->forTrip(DB::table('tbl_trips')->where('tripId', $x['trip'])->first(), 'content.unavailable', null, null);
        $this->assertSame([], $after['options'], 'không còn mục không xác thực trong các ngày');
        $this->assertSame(3, DB::table('tbl_trip_items')->where('tripId', $x['trip'])->count(), 'mục cũ vẫn còn trong Đã lưu');
    }
}
