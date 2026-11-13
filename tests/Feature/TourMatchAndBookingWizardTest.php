<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\Planner\TourMatch;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A-01 Tour Match (4 điểm thành phần, Top-N), A-05 banner "có tour giống lịch trình này" (Match ≥ 0.6, điền sẵn ngày / số người,
 * gắn tripId vào đơn), C-05 wizard đặt tour 3 bước.
 */
class TourMatchAndBookingWizardTest extends TestCase
{
    use RefreshDatabase;

    private int $dl;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->dl = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'n',
            'description' => 'Đà Lạt', 'latitude' => 11.94, 'longitude' => 108.45, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'tm1', 'email' => 'tm1@example.com', 'password' => 'x', 'fullName' => 'Khách TM', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'tm1'];
    }

    private function tour(string $title, string $start, int $qty = 10, float $price = 2000000, string $desc = 'Tham quan'): int
    {
        $cols = array_flip(Schema::getColumnListing('tbl_tours'));
        $row = ['title' => $title, 'destinationId' => $this->dl, 'startDate' => $start, 'endDate' => date('Y-m-d', strtotime($start . ' +2 days')),
            'priceAdult' => $price, 'priceChild' => $price / 2, 'quantity' => $qty, 'availability' => 1, 'time' => '3 ngày 2 đêm', 'description' => $desc,
            'domain' => 'n', 'destination' => 'Đà Lạt', 'durationDays' => 3];

        return DB::table('tbl_tours')->insertGetId(array_intersect_key($row, $cols));
    }

    private function want(array $o = []): array
    {
        return $o + ['startDate' => null, 'month' => null, 'days' => 3, 'adults' => 2, 'children' => 0, 'budget' => null, 'interests' => [], 'placeNames' => []];
    }

    // ---------------------------------------------------------------- A-01: công thức

    public function test_score_is_weighted_sum_of_four_components(): void
    {
        $tour = ['startDate' => '2027-03-10', 'days' => 3, 'remaining' => 10, 'priceAdult' => 2000000.0, 'priceChild' => 1000000.0,
            'text' => 'Tour Đà Lạt săn mây, đồi chè Cầu Đất, thác Datanla'];
        $r = TourMatch::score($this->want(['startDate' => '2027-03-10', 'budget' => 5000000.0, 'interests' => ['nature'], 'placeNames' => ['Thác Datanla', 'Chợ Đà Lạt']]), $tour);

        $c = $r['components'];
        $this->assertSame(1.0, $c['budget']['value']);            // 4tr ≤ 5tr
        $this->assertSame(1.0, $c['travel']['value']);            // đúng ngày, đúng số ngày
        $this->assertSame(1.0, $c['group']['value']);             // còn 10 chỗ cho 2 người
        $this->assertEqualsWithDelta(0.8, $c['interest']['value'], 0.001);   // 0.6×1 (thiên nhiên) + 0.4×0.5 (1/2 địa điểm)
        $this->assertEqualsWithDelta(0.35 + 0.30 * 0.8 + 0.20 + 0.15, $r['score'], 0.001);
        $this->assertSame(['Thác Datanla'], $r['matchedPlaces']);
        $this->assertSame(0.35, $c['budget']['weight']);
        $this->assertTrue($c['interest']['known']);
    }

    public function test_components_degrade_and_unknown_is_neutral(): void
    {
        $tour = ['startDate' => '2027-03-24', 'days' => 5, 'remaining' => 1, 'priceAdult' => 3000000.0, 'priceChild' => null, 'text' => 'Nghỉ dưỡng'];
        $r = TourMatch::score($this->want(['startDate' => '2027-03-10', 'budget' => 4000000.0]), $tour, null, 14);
        $c = $r['components'];
        $this->assertSame(0.0, $c['budget']['value']);          // 6tr / 4tr = 1.5 => 0
        $this->assertSame(0.5, $c['group']['value']);           // 1 chỗ / 2 người
        $this->assertEqualsWithDelta((0 + (1 - 2 / 3)) / 2, $c['travel']['value'], 0.001);   // lệch 14 ngày = 0; 5 so với 3 ngày
        $this->assertSame(0.5, $c['interest']['value']);
        $this->assertFalse($c['interest']['known']);

        $noBudget = TourMatch::score($this->want(), $tour);
        $this->assertSame(0.5, $noBudget['components']['budget']['value']);
        $this->assertFalse($noBudget['components']['budget']['known']);
    }

    public function test_rank_returns_top_n_sorted_and_skips_unbookable_tours(): void
    {
        $start = date('Y-m-d', strtotime('+40 days'));
        $good = $this->tour('Tour Đà Lạt thiên nhiên', $start, 10, 2000000, 'Săn mây, thác, rừng thông');
        $mid = $this->tour('Tour Đà Lạt muộn', date('Y-m-d', strtotime('+60 days')), 10, 2000000);
        $this->tour('Tour Đà Lạt đã đầy', $start, 0);
        $this->tour('Tour Đà Lạt đã đi', date('Y-m-d', strtotime('-10 days')));

        $r = app(TourMatch::class)->rank($this->want(['startDate' => $start, 'budget' => 5000000.0, 'interests' => ['nature']]), [$this->dl], 5);
        $this->assertSame([$good, $mid], array_column($r, 'tourId'));
        $this->assertGreaterThan($r[1]['score'], $r[0]['score']);
        // tour cũ chưa có bảng ngày khởi hành: không có availabilityId, trang đặt tự lấy ngày của tour
        $this->assertStringNotContainsString('availabilityId=', $r[0]['bookUrl']);
        $this->assertStringContainsString('adults=2', $r[0]['bookUrl']);
        // có bảng ngày khởi hành: chọn ngày gần ngày đi nhất còn đủ chỗ
        app(\App\Services\DepartureService::class)->ensureForTour($good);
        $r = app(TourMatch::class)->rank($this->want(['startDate' => $start]), [$this->dl], 5);
        $avail = (int) DB::table('tbl_tour_availability')->where('tourId', $good)->value('availabilityId');
        $this->assertStringContainsString('availabilityId=' . $avail, $r[0]['bookUrl']);
    }

    public function test_api_returns_components_and_validates(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $this->tour('Tour Đà Lạt 3N', $start);
        $j = $this->getJson('/api/v1/tour-match?destination=da-lat&days=3&adults=2&budget=6000000&startDate=' . $start . '&interests[]=nature')->assertOk()->json();
        $this->assertSame(0.6, $j['threshold']);
        $this->assertSame(['budget', 'interest', 'travel', 'group'], array_keys($j['results'][0]['components']));
        $this->assertEqualsWithDelta(1.0, array_sum($j['weights']), 0.0001);
        $this->getJson('/api/v1/tour-match?destination=khong-co&days=3')->assertNotFound();
        $this->getJson('/api/v1/tour-match?destination=da-lat')->assertStatus(422);
    }

    // ---------------------------------------------------------------- A-05: banner trong trang sửa chuyến (6 mục nghiệm thu)

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Đà Lạt cuối năm', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->dl]);
    }

    public function test_banner_shows_when_match_reaches_threshold_with_prefilled_link(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $tour = $this->tour('Tour Đà Lạt 3N2Đ', $start);
        $id = $this->trip(['startDate' => $start, 'budget' => 6000000]);
        app(\App\Services\DepartureService::class)->ensureForTour($tour);

        // (1) Match ≥ 0.6 và còn đủ chỗ → có banner  (2) có lý do + điểm thành phần
        $html = $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('Có tour giống lịch trình này')->assertSee('Tour Travela hợp chuyến này')->assertSee('Thời gian di chuyển')
            ->assertSee('Khởi hành đúng ngày bạn chọn')->getContent();
        // (3) liên kết đặt tour điền sẵn ngày khởi hành, số người và tripId
        $avail = (int) DB::table('tbl_tour_availability')->where('tourId', $tour)->value('availabilityId');
        $this->assertGreaterThan(0, $avail);
        $this->assertStringContainsString('availabilityId=' . $avail, $html);
        $this->assertStringContainsString('tripId=' . $id, $html);
    }

    public function test_banner_hidden_below_threshold_or_without_seats(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        // (4) giá gấp đôi ngân sách + lệch 13 ngày → dưới ngưỡng
        $this->tour('Tour Đà Lạt đắt', date('Y-m-d', strtotime($start . ' +13 days')), 10, 9000000);
        $id = $this->trip(['startDate' => $start, 'budget' => 6000000]);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertDontSee('Có tour giống lịch trình này');

        // (5) đủ điểm nhưng không đủ chỗ cho cả nhóm → không có banner (bấm vào cũng không đặt được)
        $this->tour('Tour Đà Lạt còn 1 chỗ', $start, 1);
        $r = app(TourMatch::class)->rank(TourMatch::wantFromTrip(DB::table('tbl_trips')->where('tripId', $id)->first()), [$this->dl], 5);
        $this->assertNull(TourMatch::banner($r));
    }

    public function test_banner_requires_similar_dates_and_itinerary_not_just_a_high_score(): void
    {
        $base = ['tourId' => 1, 'enoughSeats' => true, 'matchedPlaces' => [], 'components' => ['interest' => ['value' => 0.5]]];
        // Match 0.75 nhờ giá rẻ nhưng khởi hành lệch quá 14 ngày: không gọi là "giống lịch trình"
        $this->assertNull(TourMatch::banner([$base + ['score' => 0.75, 'dateFit' => 0.0, 'hasPlaces' => false]]));
        // chuyến đã xếp địa điểm mà lộ trình tour không nhắc nơi nào, sở thích dưới 0.5: không có banner
        $this->assertNull(TourMatch::banner([['components' => ['interest' => ['value' => 0.2]]] + $base + ['score' => 0.7, 'dateFit' => 1.0, 'hasPlaces' => true]]));
        // trùng ít nhất một địa điểm: có banner
        $this->assertNotNull(TourMatch::banner([['matchedPlaces' => ['Thác Datanla'], 'components' => ['interest' => ['value' => 0.2]]] + $base + ['score' => 0.7, 'dateFit' => 1.0, 'hasPlaces' => true]]));

        // dữ liệu thật: ghi chú có vị trí ("Địa điểm của tôi") được so với lộ trình tour
        $start = date('Y-m-d', strtotime('+30 days'));
        $tour = $this->tour('Tour Đà Lạt ngàn hoa', $start, 10, 2000000, 'Ngày 1: Hồ Xuân Hương, Thác Datanla');
        $id = $this->trip(['startDate' => $start, 'budget' => 6000000]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Đà Lạt', 'days' => 3, 'groupSize' => 2, 'startDate' => $start, 'budget' => 6000000,
            'items' => [['type' => 'note', 'label' => 'Thác Datanla', 'lat' => 11.90, 'lng' => 108.45, 'source' => 'link', 'day' => 1]]])->assertOk();
        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $items = array_merge(...array_map(fn($d) => $d['items'], app(TripPlanner::class)->payload($trip)['days']));
        $r = app(TourMatch::class)->rank(TourMatch::wantFromTrip($trip, $items), [$this->dl]);
        $this->assertSame($tour, $r[0]['tourId']);
        $this->assertSame(['Thác Datanla'], $r[0]['matchedPlaces']);
        $this->assertNotNull(TourMatch::banner($r));
    }

    public function test_booking_from_banner_prefills_and_links_trip(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $tour = $this->tour('Tour Đà Lạt 3N2Đ', $start);
        $id = $this->trip(['startDate' => $start, 'budget' => 6000000, 'groupSize' => 3]);
        app(\App\Services\DepartureService::class)->ensureForTour($tour);
        $avail = (int) DB::table('tbl_tour_availability')->where('tourId', $tour)->value('availabilityId');

        // trang đặt tour nhận tripId, điền sẵn số khách
        $this->withSession($this->s)->get("/booking/$tour?availabilityId=$avail&adults=3&tripId=$id")->assertOk()
            ->assertSee('Đặt từ chuyến đi')->assertSee('name="tripId" value="' . $id . '"', false)->assertSee('id="bkAdults" value="3"', false);

        // (6) đơn gắn tripId + điểm Match lúc đặt
        $r = app(BookingService::class)->create($this->uid, ['tourId' => $tour, 'availabilityId' => $avail, 'fullName' => 'K', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'Đà Lạt', 'numAdults' => 3, 'numChildren' => 0, 'couponCode' => '', 'tripId' => $id]);
        $b = DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->first();
        $this->assertSame($id, (int) $b->tripId);
        $this->assertGreaterThanOrEqual(0.6, (float) $b->matchScore);
    }

    public function test_foreign_trip_is_not_linked_to_booking(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $tour = $this->tour('Tour Đà Lạt', $start);
        $other = DB::table('tbl_users')->insertGetId(['username' => 'tm2', 'email' => 'tm2@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $foreign = app(TripPlanner::class)->create($other, ['title' => 'Của người khác', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->dl]);

        $this->withSession($this->s)->get("/booking/$tour?tripId=$foreign")->assertOk()->assertDontSee('Đặt từ chuyến đi');
        $r = app(BookingService::class)->create($this->uid, ['tourId' => $tour, 'fullName' => 'K', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'Đà Lạt', 'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '', 'tripId' => $foreign]);
        $this->assertNull(DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('tripId'));
    }

    // ---------------------------------------------------------------- C-05: wizard 3 bước

    public function test_wizard_page_has_three_steps_with_dates_and_quote_url(): void
    {
        $start = date('Y-m-d', strtotime('+30 days'));
        $tour = $this->tour('Tour Đà Lạt', $start);
        $dep = app(\App\Services\DepartureService::class);
        $dep->ensureForTour($tour);
        DB::table('tbl_tour_availability')->insert(['tourId' => $tour, 'departureDate' => date('Y-m-d', strtotime('+45 days')), 'totalSlots' => 8,
            'bookedSlots' => 0, 'heldSlots' => 0, 'status' => 'open']);

        $this->withSession($this->s)->get("/booking/$tour")->assertOk()
            ->assertSee('data-wizard', false)->assertSee('data-panel="1"', false)->assertSee('data-panel="2"', false)->assertSee('data-panel="3"', false)
            ->assertSee('Ngày &amp; số khách', false)->assertSee('Liên hệ &amp; mã giảm giá', false)->assertSee('Xác nhận &amp; thanh toán', false)
            ->assertSee('name="availabilityId"', false)->assertSee('còn 8 chỗ')->assertSee(route('booking.quote'), false)
            ->assertSee('booking-wizard.js', false);
    }

    public function test_server_error_reopens_the_step_that_holds_the_field(): void
    {
        $tour = $this->tour('Tour Đà Lạt', date('Y-m-d', strtotime('+30 days')));
        $this->withSession($this->s)->from("/booking/$tour")->post('/create-booking', ['tourId' => $tour, 'fullName' => '', 'email' => 'sai', 'tel' => '1',
            'address' => '', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'office-payment'])->assertRedirect();
        $errors = session('errors');
        $this->assertTrue($errors->has('fullName'));
        $this->withSession($this->s + ['errors' => $errors])->get("/booking/$tour")->assertOk()->assertSee('data-step="2"', false);
    }

    public function test_quote_endpoint_used_by_wizard_reports_seats_and_coupon(): void
    {
        $tour = $this->tour('Tour Đà Lạt', date('Y-m-d', strtotime('+30 days')), 3, 1000000);
        $j = $this->getJson("/dat-tour/bao-gia?tourId=$tour&adults=2&children=2")->assertOk()->json();
        $this->assertSame(3000000, $j['subtotal']);
        $this->assertFalse($j['enough']);
        $this->assertSame(3, $j['remaining']);
        $bad = $this->getJson("/dat-tour/bao-gia?tourId=$tour&adults=1&coupon=KHONGCO")->assertOk()->json();
        $this->assertNotNull($bad['couponError']);
    }
}
