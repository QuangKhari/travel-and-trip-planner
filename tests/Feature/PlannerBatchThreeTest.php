<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\Departures\ShortfallForecast;
use App\Services\Planner\MultiCityPlanner;
use App\Services\Planner\TripPlanner;
use App\Services\PricingService;
use App\Services\TourStops;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Đợt 3: chuyến nhiều thành phố, báo giá tour riêng từ chuyến, lịch trình tour theo giờ, hoạt động thêm khi đặt tour,
 * trang hướng dẫn viên (điểm danh, báo sự cố), dự báo sớm thiếu khách, bộ mẫu thu hoạch câu hỏi thật.
 */
class PlannerBatchThreeTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 09:00:00');
        Mail::fake();
        config(['core_destinations.slugs' => [], 'planner.weather.enabled' => false, 'ai.enabled' => false]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'c1', 'email' => 'c1@example.com', 'password' => 'x', 'fullName' => 'C1', 'isActive' => 'y']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tour(int $days = 3, int $ahead = 20): int
    {
        return DB::table('tbl_tours')->insertGetId(['title' => 'Tour đợt 3', 'time' => $days . ' ngày', 'description' => 'x', 'quantity' => 20,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Huế', 'domain' => 'b', 'availability' => 1, 'durationDays' => $days,
            'startDate' => now()->addDays($ahead)->toDateString(), 'endDate' => now()->addDays($ahead + $days - 1)->toDateString()]);
    }

    private function admin(): int
    {
        return DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Ad', 'address' => 'HN', 'role' => 'admin']);
    }

    public function test_multi_city_hop_estimates(): void
    {
        $hn = [21.0285, 105.8542];
        $hl = [20.9517, 107.0811];
        $dl = [11.9404, 108.4583];
        $near = MultiCityPlanner::hop($hn, $hl);
        $far = MultiCityPlanner::hop($hn, $dl);
        $this->assertSame('bus', $near['mode']);
        $this->assertSame('flight', $far['mode']);
        $this->assertLessThan(5 * 60, $far['min']);                  // bay nhanh hơn đi xe
        $this->assertSame('train', MultiCityPlanner::hop($hn, $dl, 'train')['mode']);
        $this->assertGreaterThan(MultiCityPlanner::hop($hn, $dl, 'flight')['min'], MultiCityPlanner::hop($hn, $dl, 'train')['min']);
        $r = app(MultiCityPlanner::class)->generate([['destinationId' => 1, 'days' => 2]], ['startDate' => '2027-03-03']);
        $this->assertFalse($r['ok']);                               // cần ít nhất 2 chặng
    }

    public function test_quote_request_answer_and_decision(): void
    {
        $dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $p = DB::table('tbl_external_places')->insertGetId(['destinationId' => $dest, 'source' => 'manual', 'externalId' => 'p', 'name' => 'Văn Miếu', 'category' => 'sight', 'kind' => 'museum',
            'latitude' => 21.03, 'longitude' => 105.84, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 6, 'startDate' => '2027-03-10', 'destinationId' => $dest]);
        $s = ['userId' => $this->uid, 'username' => 'c1'];

        // lịch trống: từ chối
        $this->withSession($s)->post("/len-ke-hoach/$id/bao-gia-tour", ['note' => 'x'])->assertRedirect();
        $this->assertSame(0, DB::table('tbl_tour_quotes')->count());

        $this->withSession($s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 6, 'startDate' => '2027-03-10',
            'items' => [['type' => 'place', 'refId' => $p, 'day' => 1]], 'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();
        $this->withSession($s)->post("/len-ke-hoach/$id/bao-gia-tour", ['note' => 'Có người lớn tuổi'])->assertRedirect();
        $q = DB::table('tbl_tour_quotes')->first();
        $this->assertSame('open', $q->status);
        $this->assertSame(6, (int) $q->groupSize);
        $this->assertStringContainsString('Văn Miếu', $q->snapshot);
        // gửi lần hai khi còn mở: không tạo thêm
        $this->withSession($s)->post("/len-ke-hoach/$id/bao-gia-tour", [])->assertRedirect();
        $this->assertSame(1, DB::table('tbl_tour_quotes')->count());
        // người khác không xem được
        $other = DB::table('tbl_users')->insertGetId(['username' => 'c2', 'email' => 'c2@example.com', 'password' => 'x', 'fullName' => 'C2', 'isActive' => 'y']);
        $this->withSession(['userId' => $other])->get('/bao-gia-tour/' . $q->quoteId)->assertNotFound();

        $this->withSession(['adminId' => $this->admin()])->post('/admin/tour-quotes/' . $q->quoteId, ['pricePerPerson' => 2500000, 'validUntil' => '2027-03-05', 'includes' => 'Xe, HDV'])->assertRedirect();
        $q = DB::table('tbl_tour_quotes')->first();
        $this->assertSame('quoted', $q->status);
        $this->assertEquals(15000000, (float) $q->totalPrice);
        Mail::assertQueued(\App\Mail\TemplateMail::class);

        $this->withSession($s)->get('/bao-gia-tour/' . $q->quoteId)->assertOk()->assertSee('2.500.000');
        Carbon::setTestNow('2027-03-06 09:00:00');                  // hết hạn giữ giá
        $this->withSession($s)->post('/bao-gia-tour/' . $q->quoteId . '/tra-loi', ['decision' => 'accept'])->assertSessionHas('error');
        Carbon::setTestNow('2027-03-04 09:00:00');
        $this->withSession($s)->post('/bao-gia-tour/' . $q->quoteId . '/tra-loi', ['decision' => 'accept'])->assertSessionHas('success');
        $this->assertSame('accepted', DB::table('tbl_tour_quotes')->value('status'));
    }

    public function test_tour_stops_save_sorts_and_check_finds_problems(): void
    {
        $t = $this->tour(2);
        DB::table('tbl_timeline')->insert([['tourId' => $t, 'title' => 'N1', 'description' => 'x'], ['tourId' => $t, 'title' => 'N2', 'description' => 'x'], ['tourId' => $t, 'title' => 'N3', 'description' => 'x']]);
        $ad = $this->admin();
        $this->withSession(['adminId' => $ad])->post("/admin/tours/$t/lich-trinh-gio", ['stops' => [
            ['dayNo' => 1, 'start' => '10:00', 'end' => '11:30', 'kind' => 'visit', 'title' => 'Đại Nội'],
            ['dayNo' => 1, 'start' => '07:30', 'kind' => 'meet', 'title' => 'Đón khách'],
            ['dayNo' => 1, 'start' => '11:00', 'end' => '12:00', 'kind' => 'meal', 'title' => 'Ăn trưa'],
        ]])->assertRedirect();
        $stops = app(TourStops::class)->forTour($t);
        $this->assertSame(['Đón khách', 'Đại Nội', 'Ăn trưa'], collect($stops[1])->pluck('title')->all());
        $issues = implode(' | ', app(TourStops::class)->check(DB::table('tbl_tours')->where('tourId', $t)->first()));
        $this->assertStringContainsString('3 ngày', $issues);        // lịch chữ 3 ngày, tour 2 ngày
        $this->assertStringContainsString('Ngày 2', $issues);        // ngày 2 trống
        // ngày vượt quá số ngày tour: từ chối; giờ kết thúc trước giờ bắt đầu: từ chối
        $this->withSession(['adminId' => $ad])->post("/admin/tours/$t/lich-trinh-gio", ['stops' => [['dayNo' => 3, 'kind' => 'visit', 'title' => 'X']]])->assertSessionHasErrors();
        $this->withSession(['adminId' => $ad])->post("/admin/tours/$t/lich-trinh-gio", ['stops' => [['dayNo' => 1, 'start' => '10:00', 'end' => '09:00', 'kind' => 'visit', 'title' => 'X']]])->assertSessionHasErrors();
        $this->assertCount(3, app(TourStops::class)->forTour($t)[1]);
        $this->artisan('tours:check-itinerary', ['--tour' => $t])->assertSuccessful();
    }

    public function test_addons_priced_on_server_and_kept_on_booking(): void
    {
        $t = $this->tour();
        $kayak = DB::table('tbl_tour_addons')->insertGetId(['tourId' => $t, 'name' => 'Chèo kayak', 'price' => 200000, 'unit' => 'person', 'isActive' => 1, 'createdAt' => now()], 'addonId');
        $boat = DB::table('tbl_tour_addons')->insertGetId(['tourId' => $t, 'name' => 'Thuyền riêng', 'price' => 900000, 'unit' => 'group', 'isActive' => 1, 'createdAt' => now()], 'addonId');
        $off = DB::table('tbl_tour_addons')->insertGetId(['tourId' => $t, 'name' => 'Tắt', 'price' => 1, 'unit' => 'group', 'isActive' => 0, 'createdAt' => now()], 'addonId');
        $otherTour = $this->tour();
        $foreign = DB::table('tbl_tour_addons')->insertGetId(['tourId' => $otherTour, 'name' => 'Tour khác', 'price' => 5, 'unit' => 'group', 'isActive' => 1, 'createdAt' => now()], 'addonId');

        $a = app(PricingService::class)->addons($t, [$kayak, $boat, $off, $foreign, $kayak], 2, 1);
        $this->assertSame(200000 * 3 + 900000, $a['sum']);          // kayak theo người (3), thuyền theo nhóm; bỏ mục tắt / của tour khác / trùng

        $r = app(BookingService::class)->create($this->uid, ['tourId' => $t, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => 2, 'numChildren' => 1, 'couponCode' => '', 'addons' => [$kayak, $boat]]);
        $this->assertSame(2500000 + 1500000, $r['totalPrice']);
        $lines = DB::table('tbl_booking_addons')->where('bookingId', $r['bookingId'])->orderBy('addonId')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(3, (int) $lines[0]->qty);
        // báo giá qua HTTP (middleware lọc tham số mảng phải cho qua addons[])
        $this->getJson('/dat-tour/bao-gia?tourId=' . $t . '&adults=2&children=1&addons[]=' . $kayak)->assertOk()->assertJsonPath('subtotal', 2500000 + 600000)->assertJsonCount(1, 'addons');
        // đổi giá sau khi đặt: đơn cũ giữ giá lúc đặt
        DB::table('tbl_tour_addons')->where('addonId', $kayak)->update(['price' => 999999]);
        $this->assertSame(600000, (int) DB::table('tbl_booking_addons')->where('bookingId', $r['bookingId'])->where('addonId', $kayak)->value('amount'));
    }

    public function test_guide_link_roster_checkin_and_incident(): void
    {
        $t = $this->tour(2, 1);
        app(\App\Services\DepartureService::class)->ensureForTour($t);
        $aid = (int) DB::table('tbl_tour_availability')->where('tourId', $t)->value('availabilityId');
        $bid = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt(['tourId' => $t, 'userId' => $this->uid, 'availabilityId' => $aid, 'departureDate' => now()->addDay()->toDateString(),
            'fullName' => 'Nguyễn Văn Khách', 'email' => 'k@example.com', 'phoneNumber' => '0912345678', 'address' => 'HN', 'numAdults' => 2, 'numChildren' => 1,
            'totalPrice' => 1, 'bookingStatus' => 'y', 'bookingCode' => 'G1']));
        $this->withSession(['adminId' => $this->admin()])->post("/admin/departures/$aid/hdv", ['guideName' => 'Anh Tùng'])->assertRedirect();
        $link = DB::table('tbl_guide_links')->first();
        $this->assertSame(40, strlen($link->token));

        $this->get('/hdv/' . $link->token)->assertOk()->assertSee('Nguyễn Văn Khách')->assertSee('0912345678');
        $this->get('/hdv/' . str_repeat('a', 40))->assertNotFound();

        $this->postJson('/hdv/' . $link->token . '/diem-danh', ['bookingId' => $bid, 'dayNo' => 1, 'present' => 9])->assertOk()->assertJson(['present' => 3]); // không vượt số khách
        $this->assertSame(3, (int) DB::table('tbl_departure_checkins')->value('present'));
        $otherBooking = $bid + 1000;
        $this->postJson('/hdv/' . $link->token . '/diem-danh', ['bookingId' => $otherBooking, 'dayNo' => 1, 'present' => 1])->assertNotFound();

        $this->post('/hdv/' . $link->token . '/su-co', ['type' => 'vehicle', 'severity' => 'high', 'title' => 'Xe hỏng ở đèo, trễ 2 giờ'])->assertRedirect();
        $inc = DB::table('tbl_tour_incidents')->first();
        $this->assertSame($aid, (int) $inc->availabilityId);
        $this->assertSame('open', $inc->status);
        $this->assertStringContainsString('Anh Tùng', $inc->description);

        Carbon::setTestNow(now()->addDays(10));                     // hết hạn
        $this->get('/hdv/' . $link->token)->assertNotFound();
    }

    public function test_shortfall_forecast_uses_booking_pace(): void
    {
        // 5 lịch đã chạy: mỗi lịch 10 khách, một nửa đặt trước 30 ngày, nửa còn lại trong 7 ngày cuối
        $t = $this->tour(2, 40);
        foreach (range(1, 5) as $i) {
            $dep = now()->subDays(10 * $i)->toDateString();
            $aid = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $t, 'departureDate' => $dep, 'totalSlots' => 20, 'bookedSlots' => 10, 'heldSlots' => 0, 'minParticipants' => 8, 'status' => 'open']);
            foreach ([45, 3] as $before) {
                DB::table('tbl_booking')->insert(['tourId' => $t, 'availabilityId' => $aid, 'departureDate' => $dep, 'userId' => $this->uid, 'fullName' => 'K', 'email' => 'k@x.vn', 'phoneNumber' => '0',
                    'address' => 'x', 'numAdults' => 5, 'numChildren' => 0, 'totalPrice' => 1, 'bookingStatus' => 'f', 'bookingCode' => 'H' . $i . $before,
                    'bookingDate' => Carbon::parse($dep)->subDays($before)->toDateTimeString()]);
            }
        }
        $c = app(ShortfallForecast::class)->curve($t);
        $this->assertSame(5, $c['n']);
        $this->assertEqualsWithDelta(0.5, $c['curve'][30], 0.001);
        $this->assertEqualsWithDelta(1.0, ShortfallForecast::fractionAt($c['curve'], 0), 0.001);

        // còn 30 ngày: lịch A có 2 khách (dự báo 4 < 8) -> nguy cơ; lịch B có 5 khách (dự báo 10) -> không
        $a = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $t, 'departureDate' => now()->addDays(30)->toDateString(), 'totalSlots' => 20, 'bookedSlots' => 2, 'heldSlots' => 0, 'minParticipants' => 8, 'status' => 'open']);
        $b = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $t, 'departureDate' => now()->addDays(31)->toDateString(), 'totalSlots' => 20, 'bookedSlots' => 5, 'heldSlots' => 0, 'minParticipants' => 8, 'status' => 'open']);
        $risk = app(ShortfallForecast::class)->atRisk();
        $this->assertSame([$a], $risk->pluck('availabilityId')->map(fn($x) => (int) $x)->values()->all());
        $this->assertSame(4, $risk->first()->forecast);
        $this->assertSame('tour', $risk->first()->scope);
    }

    public function test_tour_with_several_departures_still_shows_guest_picker(): void
    {
        $t = $this->tour(3, 20);
        foreach ([30, 40] as $d) {
            DB::table('tbl_tour_availability')->insert(['tourId' => $t, 'departureDate' => now()->addDays($d)->toDateString(), 'totalSlots' => 10, 'bookedSlots' => 0, 'heldSlots' => 0, 'status' => 'open']);
        }
        DB::table('tbl_tour_availability')->insert(['tourId' => $t, 'departureDate' => now()->addDays(50)->toDateString(), 'totalSlots' => 2, 'bookedSlots' => 2, 'heldSlots' => 0, 'status' => 'open']);
        $this->get('/tour-detail/' . $t)->assertOk()->assertSee('id="tdAdults"', false)->assertDontSee('Tour đã đóng đặt chỗ');
    }

    public function test_harvest_scrubs_personal_data(): void
    {
        $this->assertSame('gọi [sđt] hoặc [email] mã [số]', \App\Console\Commands\AiEvalHarvest::scrub('gọi 0912 345 678 hoặc a.b@gmail.com mã 12345678'));
    }
}
