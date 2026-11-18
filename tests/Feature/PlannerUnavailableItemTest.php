<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mục lịch của người dùng KHÔNG bị xóa ngầm khi nội dung nguồn ngừng công khai (admin chuyển nháp / tắt điểm đến).
 * Trước đây: tripItems() bỏ qua mục không giải được → lần lưu tiếp theo xóa hẳn khỏi tbl_trip_items.
 */
class PlannerUnavailableItemTest extends TestCase
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
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'ua', 'email' => 'ua@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'ua'];
    }

    private function place(string $name, float $lat, float $lng): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'see', 'kind' => 'museum', 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function save(int $trip, array $items, int $days = 2): void
    {
        $this->withSession($this->s)->postJson("/len-ke-hoach/$trip/luu", ['title' => 'Hà Nội', 'days' => $days, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'items' => $items])->assertOk();
    }

    private function names(int $trip): array
    {
        return array_column(app(TripPlanner::class)->tripItems($trip), 'name');
    }

    public function test_unpublished_place_is_kept_as_unavailable_and_survives_later_saves(): void
    {
        $a = $this->place('Bảo tàng A', 21.031, 105.851);
        $b = $this->place('Văn Miếu B', 21.029, 105.836);
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $this->save($trip, [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]]);

        // admin đưa A về nháp
        DB::table('tbl_external_places')->where('placeId', $a)->update(['reviewStatus' => 'draft']);

        $items = app(TripPlanner::class)->tripItems($trip);
        $this->assertCount(2, $items, 'mục A vẫn còn khi đọc');
        $gone = collect($items)->firstWhere('refId', $a);
        $this->assertTrue($gone['unavailable']);
        $this->assertSame('Bảo tàng A', $gone['name']);                   // tên đã chụp lúc lưu
        $this->assertNull($gone['url']);                                  // không dẫn tới nội dung đã ẩn
        $this->assertEqualsWithDelta(21.031, $gone['lat'], 0.0001);

        // người dùng mở chuyến, chuyển A sang ngày 2 rồi lưu (giao diện gửi lại đúng các mục đang thấy)
        $this->save($trip, [['type' => 'place', 'refId' => $b, 'day' => 1], ['type' => 'place', 'refId' => $a, 'day' => 2]]);
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $trip)->count(), 'không xóa ngầm khi lưu');
        $row = DB::table('tbl_trip_items')->where('tripId', $trip)->where('refId', $a)->first();
        $this->assertSame(2, (int) $row->dayNo);
        $this->assertSame('Bảo tàng A', $row->label);

        // TripHealth cảnh báo, revision vẫn ghi đủ hai mục
        $health = $this->withSession($this->s)->getJson("/len-ke-hoach/$trip/suc-khoe")->assertOk()->json();
        $this->assertStringContainsString('Bảo tàng A', json_encode($health, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('content.unavailable', json_encode($health));
        $this->assertSame(2, (int) DB::table('tbl_trip_revisions')->where('tripId', $trip)->orderByDesc('revisionId')->value('itemCount'));

        // người dùng chủ động xóa: lúc đó mới mất
        $this->save($trip, [['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $this->assertSame(['Văn Miếu B'], $this->names($trip));

        // admin mở lại A: thêm lại bình thường, không còn nhãn
        DB::table('tbl_external_places')->where('placeId', $a)->update(['reviewStatus' => 'published']);
        $this->save($trip, [['type' => 'place', 'refId' => $b, 'day' => 1], ['type' => 'place', 'refId' => $a, 'day' => 1]]);
        $this->assertArrayNotHasKey('unavailable', collect(app(TripPlanner::class)->tripItems($trip))->firstWhere('refId', $a));
    }

    public function test_new_item_pointing_to_hidden_content_is_still_rejected(): void
    {
        $a = $this->place('Bảo tàng A', 21.031, 105.851);
        $hidden = $this->place('Nơi nháp', 21.04, 105.86);
        DB::table('tbl_external_places')->where('placeId', $hidden)->update(['reviewStatus' => 'draft']);
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $this->save($trip, [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $hidden, 'day' => 1]], 1);
        $this->assertSame(['Bảo tàng A'], $this->names($trip), 'không cho THÊM MỚI nội dung chưa công khai');
    }
}
