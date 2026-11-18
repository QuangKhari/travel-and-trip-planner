<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lưu chuyến theo THAY ĐỔI với định danh ổn định (tbl_trip_items.itemUid):
 * itemId không đổi qua các lần lưu, chỉ dòng thay đổi mới bị ghi, dòng bị bỏ mới bị xóa; một nơi được ghé ở nhiều ngày.
 */
class PlannerStableItemTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'Thủ đô', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'st', 'email' => 'st@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'st'];
    }

    private function place(string $name, float $lat, float $lng): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'see', 'kind' => 'museum', 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function save(int $trip, array $items): array
    {
        return $this->withSession($this->s)->postJson("/len-ke-hoach/$trip/luu", ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'items' => $items])->assertOk()->json();
    }

    public function test_item_ids_are_stable_and_only_changed_rows_are_written(): void
    {
        $a = $this->place('Văn Miếu', 21.029, 105.836);
        $b = $this->place('Hồ Gươm', 21.028, 105.852);
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);

        $j = $this->save($trip, [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1], ['type' => 'note', 'label' => 'Cà phê', 'day' => 2]]);
        $this->assertCount(3, $j['uids']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $j['uids'][0]);
        $ids = DB::table('tbl_trip_items')->where('tripId', $trip)->pluck('itemId', 'itemUid')->all();
        [$ua, $ub, $uc] = $j['uids'];

        // lần lưu sau: đổi ghi chú của A, chuyển B sang ngày 2, bỏ ghi chú cà phê, thêm một mục mới
        $writes = [];
        DB::listen(function ($e) use (&$writes) {
            if (preg_match('/^(update|insert|delete)\s.*tbl_trip_items/i', $e->sql, $m)) {
                $writes[] = strtolower($m[1]);
            }
        });
        $j2 = $this->save($trip, [
            ['type' => 'place', 'refId' => $a, 'day' => 1, 'note' => 'Mở 8h', 'itemUid' => $ua],
            ['type' => 'place', 'refId' => $b, 'day' => 2, 'itemUid' => $ub],
            ['type' => 'note', 'label' => 'Chợ đêm', 'day' => 3],
        ]);
        $this->assertSame($ua, $j2['uids'][0]);
        $this->assertSame($ub, $j2['uids'][1]);
        $this->assertNotContains($j2['uids'][2], [$ua, $ub, $uc]);

        $now = DB::table('tbl_trip_items')->where('tripId', $trip)->get()->keyBy('itemUid');
        $this->assertSame((int) $ids[$ua], (int) $now[$ua]->itemId, 'itemId của A không đổi');
        $this->assertSame((int) $ids[$ub], (int) $now[$ub]->itemId, 'itemId của B không đổi');
        $this->assertSame('Mở 8h', $now[$ua]->note);
        $this->assertSame(2, (int) $now[$ub]->dayNo);
        $this->assertArrayNotHasKey($uc, $now->all(), 'mục bị bỏ đã xóa');
        $this->assertSame(1, count(array_filter($writes, fn($w) => $w === 'delete')));   // chỉ xóa đúng một dòng bị bỏ, không xóa cả chuyến
        $this->assertSame(1, count(array_filter($writes, fn($w) => $w === 'insert')));   // chỉ thêm mục mới

        // itemUid đi kèm dữ liệu mục khi mở lại chuyến
        $this->assertEqualsCanonicalizing(array_keys($now->all()), array_column(app(TripPlanner::class)->tripItems($trip), 'itemUid'));
    }

    public function test_same_place_can_be_visited_on_two_days_as_separate_items(): void
    {
        $a = $this->place('Phố cổ', 21.034, 105.850);
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $j = $this->save($trip, [
            ['type' => 'place', 'refId' => $a, 'day' => 1, 'startMin' => 540],
            ['type' => 'place', 'refId' => $a, 'day' => 3, 'startMin' => 1200, 'note' => 'Đi lại buổi tối'],
            ['type' => 'place', 'refId' => $a, 'day' => 3],   // trùng trong cùng ngày 3: gộp
        ]);
        $this->assertNull($j['uids'][2]);
        $rows = DB::table('tbl_trip_items')->where('tripId', $trip)->orderBy('dayNo')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 3], $rows->pluck('dayNo')->map(fn($d) => (int) $d)->all());
        $this->assertSame('Đi lại buổi tối', $rows[1]->note);
        // đánh dấu đã ghé lần đầu không ảnh hưởng lần ghé sau
        $this->save($trip, [
            ['type' => 'place', 'refId' => $a, 'day' => 1, 'itemUid' => $j['uids'][0], 'visitedAt' => '2027-03-03 10:00:00'],
            ['type' => 'place', 'refId' => $a, 'day' => 3, 'itemUid' => $j['uids'][1]],
        ]);
        $v = DB::table('tbl_trip_items')->where('tripId', $trip)->orderBy('dayNo')->pluck('visitedAt')->all();
        $this->assertNotNull($v[0]);
        $this->assertNull($v[1]);
    }

    public function test_foreign_or_forged_uid_is_not_reused(): void
    {
        $a = $this->place('Lăng', 21.036, 105.834);
        $t1 = app(TripPlanner::class)->create($this->uid, ['title' => 'A', 'days' => 1, 'groupSize' => 1, 'destinationId' => $this->hn]);
        $t2 = app(TripPlanner::class)->create($this->uid, ['title' => 'B', 'days' => 1, 'groupSize' => 1, 'destinationId' => $this->hn]);
        $u1 = $this->save($t1, [['type' => 'place', 'refId' => $a, 'day' => 1]])['uids'][0];
        // gửi itemUid của chuyến khác: không được "chiếm" dòng của chuyến kia, chỉ tạo mục mới cho chuyến này
        $u2 = $this->save($t2, [['type' => 'place', 'refId' => $a, 'day' => 1, 'itemUid' => $u1]])['uids'][0];
        $this->assertNotSame($u1, $u2);
        $this->assertSame(1, DB::table('tbl_trip_items')->where('tripId', $t1)->count());
        $this->withSession($this->s)->postJson("/len-ke-hoach/$t2/luu", ['title' => 'B', 'days' => 1, 'groupSize' => 1,
            'items' => [['type' => 'place', 'refId' => $a, 'day' => 1, 'itemUid' => 'not-a-uid']]])->assertStatus(422);
    }
}
