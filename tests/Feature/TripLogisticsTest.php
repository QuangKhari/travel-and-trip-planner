<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Khách sạn và di chuyển trong lịch: vị trí chỗ ở -> đường đi từ / về khách sạn mỗi ngày; vé đi / về -> giờ tới nơi, giờ phải rời đi;
 * sức khỏe chuyến báo lỗi cứng và có cách xử lý; tạo lịch tự động tôn trọng giờ tới / giờ về và ghi vé vào "Cần đặt".
 */
class TripLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'x', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'k', 'email' => 'k@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
    }

    private function me(): static
    {
        return $this->withSession(['userId' => $this->uid, 'username' => 'k']);
    }

    private function trip(): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-05-03', 'destinationId' => $this->dest]);
        $this->me()->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-05-03', 'items' => [
            ['type' => 'note', 'label' => 'Hồ Tây', 'lat' => 21.0560, 'lng' => 105.8190, 'day' => 1, 'startMin' => 540],
            ['type' => 'note', 'label' => 'Văn Miếu', 'lat' => 21.0277, 'lng' => 105.8355, 'day' => 2],
            ['type' => 'note', 'label' => 'Bảo tàng Dân tộc học', 'lat' => 21.0405, 'lng' => 105.7985, 'day' => 2],
            ['type' => 'note', 'label' => 'Phố cổ', 'lat' => 21.0340, 'lng' => 105.8520, 'day' => 3, 'startMin' => 900, 'durationMin' => 120],
        ]])->assertOk();

        return $id;
    }

    private function codes(int $id): array
    {
        return array_column($this->me()->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json('checks'), 'code');
    }

    public function test_hotel_location_adds_legs_from_and_back_to_the_hotel(): void
    {
        $id = $this->trip();
        $before = $this->me()->getJson("/len-ke-hoach/$id/suc-khoe")->json('days.1');

        // khách sạn 2 đêm, chưa có vị trí: chưa ảnh hưởng lịch
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'Khách sạn Sen', 'startAt' => '2027-05-03T14:00', 'endAt' => '2027-05-05T12:00'])->assertOk();
        $xid = DB::table('tbl_trip_external')->where('tripId', $id)->value('externalId');
        $this->assertSame($before['travelMin'], $this->me()->getJson("/len-ke-hoach/$id/suc-khoe")->json('days.1.travelMin'));

        // gắn vị trí (xa các điểm một chút): ngày 2 thêm chặng đi và về khách sạn
        $j = $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$xid/vi-tri", ['lat' => 21.0450, 'lng' => 105.8700, 'address' => '<b>Long Biên</b>'])->assertOk()->json();
        $this->assertSame(21.045, $j['logistics']['stays'][0]['lat']);
        $this->assertSame('Long Biên', DB::table('tbl_trip_external')->where('externalId', $xid)->value('address'));
        $after = $this->me()->getJson("/len-ke-hoach/$id/suc-khoe")->json('days.1');
        $this->assertGreaterThan($before['travelMin'], $after['travelMin']);
        $this->assertNotNull($after['startLeg']);
        $this->assertNotNull($after['endLeg']);
        $this->assertGreaterThan('08:00', $after['rows'][0]['start'], 'đi từ khách sạn rồi mới tới điểm đầu');
        // trang sửa nhận dữ liệu để tự tính theo ngày đang sửa
        $this->me()->get("/len-ke-hoach/$id")->assertOk()->assertSee('"logistics":{"stays":[{"id":' . $xid, false);
        // số km của ngày ở trang sửa và ở sức khỏe khớp nhau
        $payload = app(TripPlanner::class)->payload(DB::table('tbl_trips')->where('tripId', $id)->first());
        $this->assertSame($payload['days'][1]['minutes'], $after['travelMin']);

        // không phải chỗ ở / bỏ vị trí / người lạ
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'flight', 'title' => 'VN1', 'startAt' => '2027-05-03T07:00'])->assertOk();
        $fid = DB::table('tbl_trip_external')->where(['tripId' => $id, 'kind' => 'flight'])->value('externalId');
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$fid/vi-tri", ['lat' => 21, 'lng' => 105])->assertStatus(422);
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$xid/vi-tri", ['lat' => 200, 'lng' => 105])->assertStatus(422);
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$xid/vi-tri", ['clear' => true])->assertOk();
        $this->assertNull(DB::table('tbl_trip_external')->where('externalId', $xid)->value('latitude'));
        $other = DB::table('tbl_users')->insertGetId(['username' => 'o', 'email' => 'o@example.com', 'password' => 'x', 'fullName' => 'O', 'isActive' => 'y']);
        $this->withSession(['userId' => $other])->postJson("/len-ke-hoach/$id/dat-cho/$xid/vi-tri", ['lat' => 21, 'lng' => 105])->assertNotFound();
    }

    public function test_booked_hotel_with_location_puts_check_in_on_the_map(): void
    {
        $id = $this->trip();
        $this->me()->postJson("/len-ke-hoach/$id/xac-nhan", ['kind' => 'hotel', 'title' => 'Sen', 'startAt' => '2027-05-03T14:00', 'endAt' => '2027-05-05T12:00',
            'lat' => 21.03, 'lng' => 105.85, 'address' => 'Hoàn Kiếm'])->assertOk();
        $item = DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Sen'])->first();
        $this->assertEqualsWithDelta(21.03, (float) $item->latitude, 1e-6);
        $this->assertSame(1, (int) $item->dayNo);
        // gắn lại vị trí: mục nhận phòng trên lịch đi theo
        $xid = DB::table('tbl_trip_external')->where('tripId', $id)->value('externalId');
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$xid/vi-tri", ['lat' => 21.04, 'lng' => 105.86])->assertOk();
        $this->assertEqualsWithDelta(21.04, (float) DB::table('tbl_trip_items')->where('itemId', $item->itemId)->value('latitude'), 1e-6);
    }

    public function test_arrival_and_departure_make_hard_issues_with_fixes(): void
    {
        $id = $this->trip();
        $this->assertNotContains('time.arrival', $this->codes($id));

        // bay tới 10:30 ngày đầu: Hồ Tây 09:00 không kịp; tàu về 15:30 ngày cuối: Phố cổ 15:00-17:00 quá giờ
        $this->me()->postJson("/len-ke-hoach/$id/xac-nhan", ['kind' => 'flight', 'title' => 'VN123', 'startAt' => '2027-05-03T08:30', 'endAt' => '2027-05-03T10:30'])->assertOk();
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'train', 'title' => 'SE1', 'startAt' => '2027-05-05T15:30'])->assertOk();
        $h = $this->me()->getJson("/len-ke-hoach/$id/suc-khoe")->json();
        $byCode = collect($h['checks'])->keyBy('code');
        $this->assertSame('bad', $byCode['time.arrival']['level']);
        $this->assertStringContainsString('VN123 tới 10:30', $byCode['time.arrival']['text']);
        $this->assertSame('bad', $byCode['time.departure']['level']);
        $this->assertStringContainsString('14:50', $byCode['time.departure']['text']);   // 15:30 - 40 phút

        // cách xử lý: bỏ giờ cố định / chuyển ngày giữa / đưa vào "Đã lưu"
        $a = $byCode['time.arrival'];
        $r = $this->me()->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=time.arrival&day={$a['day']}&index={$a['index']}")->assertOk()->json();
        $ids = array_column($r['options'], 'id');
        $this->assertContains('unfix', $ids);
        $this->assertContains('move', $ids);
        $this->assertContains('park', $ids);
        $this->assertTrue(collect($r['options'])->firstWhere('id', 'unfix')['resolves']);

        // hủy vé: hết ràng buộc
        $fid = DB::table('tbl_trip_external')->where(['tripId' => $id, 'kind' => 'flight'])->value('externalId');
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho/$fid", ['status' => 'cancelled'])->assertOk();
        $this->assertNotContains('time.arrival', $this->codes($id));
    }

    public function test_changing_dates_keeps_booking_items_on_their_real_day_and_never_crams_the_last_day(): void
    {
        $id = $this->trip();
        $this->me()->postJson("/len-ke-hoach/$id/xac-nhan", ['kind' => 'hotel', 'title' => 'Sen', 'startAt' => '2027-05-04T14:00', 'endAt' => '2027-05-05T12:00'])->assertOk();
        $this->assertSame(2, (int) DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Sen'])->value('dayNo'));
        $items = fn() => DB::table('tbl_trip_items')->where('tripId', $id)->orderBy('dayNo')->orderBy('orderNo')->get()
            ->map(fn($r) => ['type' => $r->refType, 'label' => $r->label, 'lat' => $r->latitude, 'lng' => $r->longitude, 'day' => $r->dayNo, 'startMin' => $r->startMin])->all();

        // lùi ngày đi 1 ngày nhưng trình duyệt cũ gửi nguyên số ngày của mục: nhận phòng ngày 4/5 giờ là ngày 3
        $this->me()->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-05-02', 'items' => $items()])->assertOk();
        $this->assertSame(3, (int) DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Sen'])->value('dayNo'));
        // dời ra ngoài: vào "Đã lưu"
        $this->me()->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 3, 'groupSize' => 2, 'startDate' => '2027-05-10', 'items' => $items()])->assertOk();
        $this->assertNull(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Sen'])->value('dayNo'));
        $this->assertTrue(collect($this->me()->getJson("/len-ke-hoach/$id/dat-cho")->json('booked'))->firstWhere('title', 'Sen')['outside']);

        // bớt số ngày: mục ngày 3 vào "Đã lưu", không dồn vào ngày 2
        $this->me()->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-05-10', 'items' => $items()])->assertOk();
        $pho = DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Phố cổ'])->first();
        $this->assertNull($pho->dayNo);
        $this->assertNull($pho->startMin);
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->where('dayNo', '>', 2)->count());
    }

    public function test_booking_items_stay_private_on_community_shared_view_and_copies(): void
    {
        $id = $this->trip();
        $this->me()->postJson("/len-ke-hoach/$id/xac-nhan", ['kind' => 'flight', 'title' => 'VN7788', 'startAt' => '2027-05-03T07:00'])->assertOk();
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Khởi hành VN7788'])->exists());
        DB::table('tbl_trips')->where('tripId', $id)->update(['isPublic' => 1, 'publishedAt' => now()]);

        $this->get("/cong-dong/$id")->assertOk()->assertSee('Văn Miếu')->assertDontSee('VN7788');
        $url = $this->me()->postJson("/len-ke-hoach/$id/chia-se", ['on' => true])->json('url');
        $this->get($url)->assertOk()->assertDontSee('VN7788');
        $this->me()->postJson("/len-ke-hoach/$id/rieng-tu", ['shareBookings' => true])->assertOk();
        $this->get($url)->assertOk()->assertSee('VN7788');

        $other = DB::table('tbl_users')->insertGetId(['username' => 'o', 'email' => 'o@example.com', 'password' => 'x', 'fullName' => 'O', 'isActive' => 'y']);
        $copy = app(\App\Services\Planner\TripCommunity::class)->cloneTrip($other, $id, ['groupSize' => 2]);
        $this->assertFalse(DB::table('tbl_trip_items')->where('tripId', $copy)->where('label', 'like', '%VN7788%')->exists());
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $copy, 'label' => 'Văn Miếu'])->exists());
    }

    public function test_deleting_a_trip_removes_its_expenses_and_guests(): void
    {
        $id = $this->trip();
        $this->me()->postJson("/len-ke-hoach/$id/nguoi-di-cung", ['name' => 'Na'])->assertOk();
        $this->me()->postJson("/len-ke-hoach/$id/chi-tieu", ['title' => 'Ăn', 'amount' => 100000, 'paidBy' => 'u:' . $this->uid, 'splitMode' => 'equal', 'who' => ['u:' . $this->uid]])->assertOk();
        $this->assertTrue(app(TripPlanner::class)->delete($this->uid, $id));
        $this->assertSame(0, DB::table('tbl_trip_expenses')->count());
        $this->assertSame(0, DB::table('tbl_trip_expense_shares')->count());
        $this->assertSame(0, DB::table('tbl_trip_guests')->count());
    }

    public function test_planned_hotel_need_records_all_missing_nights(): void
    {
        $id = $this->trip();
        // giao diện gửi endAt = nhận phòng + số đêm còn thiếu: không còn "thiếu chỗ ở"
        $this->me()->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'Chỗ ở 2 đêm từ 03/05', 'startAt' => '2027-05-03T14:00', 'endAt' => '2027-05-05T12:00'])->assertOk();
        $j = $this->me()->getJson("/len-ke-hoach/$id/dat-cho")->json();
        $stay = collect($j['needs'])->firstWhere('key', 'stay');
        $this->assertNotNull($stay['plannedId'] ?? null, 'đêm còn thiếu đã được ghi vào cần đặt');
        $this->assertSame('2027-05-05', $j['logistics']['stays'][0]['out']);
    }
}
