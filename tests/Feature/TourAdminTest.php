<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Support\ReviewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phần tour (báo cáo rà soát v2, 01/11/2026). Mỗi lỗi mức Cao / TB có ít nhất một test (L-T-01):
 *   L-E-15 thêm / sửa tour không còn lỗi 500 · L-E-16 / L-E-18 lộ trình nhiều ngày hoàn tất được · L-E-19 gỡ ảnh tạm
 *   L-E-20 dọn ảnh tạm + tour nháp · L-E-21 validate Sửa như Thêm · L-F-11 tự hoàn tất đơn · L-B-23 nút đặt ngày khởi hành
 *   L-B-24 lý do hủy · L-F-10 ẩn đánh giá · L-C-05 phân trang đánh giá · L-D-11 lọc số ngày theo ngày thật.
 */
class TourAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media.disk', 'public'));
        ReviewScope::reset();
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-nang', 'name' => 'Đà Nẵng', 'province' => 'Đà Nẵng', 'domain' => 't',
            'description' => 'mô tả', 'latitude' => 16.05, 'longitude' => 108.2, 'isActive' => 1]);
    }

    private function as()
    {
        return $this->withSession(['adminId' => $this->admin]);
    }

    private function step1(array $o = []): array
    {
        $start = now()->addDays(10);

        return $o + [
            'name' => 'Đà Nẵng 4 ngày', 'destination' => 'Đà Nẵng', 'destinationId' => $this->dest, 'domain' => 't', 'number' => 20,
            'price_adult' => 3000000, 'price_child' => 1500000, 'start_date' => $start->format('d/m/Y'), 'end_date' => $start->copy()->addDays(3)->format('d/m/Y'),
            'description' => '<p>Biển và phố cổ</p>', 'includes' => "Xe đưa đón\nKhách sạn 3 sao", 'excludes' => 'Vé máy bay',
        ];
    }

    private function upload(int $tourId, int $seed): array
    {
        $img = UploadedFile::fake()->image("a$seed.jpg", 900 + $seed, 600);

        return $this->as()->post('/admin/add-temp-images', ['tourId' => $tourId, 'image' => $img])->assertOk()->json('data');
    }

    private function user(string $u = 'k'): int
    {
        return DB::table('tbl_users')->insertGetId(['fullName' => 'Khách ' . $u, 'username' => $u, 'password' => 'x', 'email' => $u . '@example.com', 'isActive' => 'y']);
    }

    private function tour(array $o = []): int
    {
        $s = now()->addDays(10);

        return DB::table('tbl_tours')->insertGetId($o + ['title' => 'Tour', 'time' => '3 ngày 2 đêm', 'description' => '<p>x</p>', 'quantity' => 10, 'priceAdult' => 1000000,
            'priceChild' => 500000, 'destination' => 'Đà Nẵng', 'destinationId' => $this->dest, 'domain' => 't', 'availability' => 1,
            'startDate' => $s->toDateString(), 'endDate' => $s->copy()->addDays(2)->toDateString(), 'durationDays' => 3]);
    }

    private function booking(int $tourId, int $userId, string $status, string $pay = 'n'): int
    {
        $id = DB::table('tbl_booking')->insertGetId(['tourId' => $tourId, 'userId' => $userId, 'fullName' => 'K', 'email' => 'k@example.com', 'phoneNumber' => '0900000000',
            'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'totalPrice' => 1000000, 'bookingStatus' => $status, 'bookingCode' => 'T' . uniqid()]);
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => 'office-payment', 'amount' => 1000000, 'paymentStatus' => $pay]);

        return $id;
    }

    // ------------------------------------------------------------------ L-E-15, L-E-16, L-E-18, L-E-19

    public function test_add_tour_wizard_completes_for_multi_day_tour(): void
    {
        $r = $this->as()->postJson('/admin/add-tours', $this->step1())->assertOk()->json();
        $this->assertTrue($r['success'], 'L-E-15: không còn lỗi 500 vì mảng dữ liệu sai khóa');
        $id = (int) $r['tourId'];
        $t = DB::table('tbl_tours')->where('tourId', $id)->first();
        $this->assertSame(0, (int) $t->availability);
        $this->assertSame("Xe đưa đón\nKhách sạn 3 sao", $t->includes);
        $this->assertSame('4 ngày 3 đêm', $t->time);
        $this->assertSame(4, (int) $t->durationDays);

        // Bước 1 sửa lại (nháp): L-E-15 cũng ở đây
        $this->as()->postJson('/admin/update-basic-tour', $this->step1(['tourId' => $id, 'name' => 'Đà Nẵng 4 ngày (sửa)']))->assertOk()->assertJson(['success' => true]);

        // Bước 2: 6 ảnh, gỡ 1 ảnh (L-E-19)
        $stems = [];
        for ($i = 1; $i <= 6; $i++) {
            $stems[] = $this->upload($id, $i)['filename'];
        }
        $this->as()->postJson('/admin/delete-temp-image', ['tourId' => $id, 'filename' => $stems[5]])->assertOk()->assertJson(['remaining' => 5]);
        $this->assertFalse(app(\App\Services\TourImageService::class)->stemExists($stems[5]), 'file ảnh đã gỡ bị xóa');

        // Bước 3: tour 4 ngày, form còn day-1, day-2, day-4, day-5 (đã xóa một mục giữa: L-E-18) -> máy chủ đánh số lại
        $tl = ['tourId' => $id];
        foreach ([1, 2, 4, 5] as $n) {
            $tl["day-$n"] = "Ngày $n";
            $tl["itinerary-$n"] = "<p>Lịch $n</p>";
        }
        $this->as()->postJson('/admin/add-timeline', $tl)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) DB::table('tbl_tours')->where('tourId', $id)->value('availability'));
        $this->assertSame(['Ngày 1', 'Ngày 2', 'Ngày 4', 'Ngày 5'], DB::table('tbl_timeline')->where('tourId', $id)->orderBy('timeLineId')->pluck('title')->all());
        $this->assertSame(5, DB::table('tbl_images')->where('tourId', $id)->count(), 'ảnh đã gỡ không được đăng cùng tour');
        $this->assertNotContains($stems[5], DB::table('tbl_images')->where('tourId', $id)->pluck('imageURL')->all());
        $this->assertSame(0, DB::table('tbl_temp_images')->where('tourId', $id)->count());

        // gọi lại: 409, không nhân đôi
        $this->as()->postJson('/admin/add-timeline', $tl)->assertStatus(409);
    }

    public function test_timeline_must_match_tour_length(): void
    {
        $id = (int) $this->as()->postJson('/admin/add-tours', $this->step1())->json('tourId');
        for ($i = 1; $i <= 5; $i++) {
            $this->upload($id, $i);
        }
        $this->as()->postJson('/admin/add-timeline', ['tourId' => $id, 'day-1' => 'A', 'itinerary-1' => 'a', 'day-2' => 'B', 'itinerary-2' => 'b', 'day-3' => 'C', 'itinerary-3' => 'c'])
            ->assertStatus(422)->assertJson(['message' => 'Lộ trình phải có đúng 4 ngày.']);
    }

    // ------------------------------------------------------------------ L-E-15 (Sửa), L-E-21, L-E-04

    private function editPayload(int $id, array $o = []): array
    {
        $t = DB::table('tbl_tours')->where('tourId', $id)->first();

        return $o + ['tourId' => $id, 'name' => 'Tour sửa', 'destination' => 'Đà Nẵng', 'destinationId' => $this->dest, 'domain' => 't', 'number' => $t->quantity,
            'original_number' => $t->quantity, 'price_adult' => 1200000, 'price_child' => 600000,
            'start_date' => date('d/m/Y', strtotime($t->startDate)), 'end_date' => date('d/m/Y', strtotime($t->endDate)), 'description' => '<p>mới</p>',
            'includes' => 'Ăn sáng', 'timeline' => [['title' => 'N1', 'itinerary' => 'a'], ['title' => 'N2', 'itinerary' => 'b'], ['title' => 'N3', 'itinerary' => 'c']]];
    }

    public function test_edit_published_tour_keeps_images_and_validates_like_add(): void
    {
        $id = $this->tour();
        DB::table('tbl_images')->insert(array_map(fn($i) => ['tourId' => $id, 'imageURL' => "old$i.jpg", 'description' => '', 'sortOrder' => $i], range(0, 4)));

        $this->as()->postJson('/admin/edit-tour', $this->editPayload($id))->assertOk()->assertJson(['success' => true]);
        $t = DB::table('tbl_tours')->where('tourId', $id)->first();
        $this->assertSame('Tour sửa', $t->title);
        $this->assertSame('Ăn sáng', $t->includes, 'L-E-15: cột includes ghi đúng khi Sửa');
        $this->assertSame(5, DB::table('tbl_images')->where('tourId', $id)->count(), 'thiếu images = giữ nguyên ảnh');

        // L-E-21: phải có điểm đến, tiêu đề ngày không rỗng, tour đang hiện không được dời ngày về quá khứ
        $this->as()->postJson('/admin/edit-tour', $this->editPayload($id, ['destinationId' => '']))->assertJson(['success' => false]);
        $this->as()->postJson('/admin/edit-tour', $this->editPayload($id, ['timeline' => [['title' => 'N1', 'itinerary' => 'a'], ['title' => ' ', 'itinerary' => 'b'], ['title' => 'N3', 'itinerary' => 'c']]]))
            ->assertStatus(422);
        $past = now()->subDays(3);
        $this->as()->postJson('/admin/edit-tour', $this->editPayload($id, ['start_date' => $past->format('d/m/Y'), 'end_date' => $past->copy()->addDays(2)->format('d/m/Y')]))
            ->assertStatus(422)->assertJsonFragment(['success' => false]);
    }

    public function test_edit_blocks_date_change_when_tour_has_bookings(): void
    {
        $id = $this->tour();
        $this->booking($id, $this->user(), 'n');
        $s = now()->addDays(20);
        $this->as()->postJson('/admin/edit-tour', $this->editPayload($id, ['start_date' => $s->format('d/m/Y'), 'end_date' => $s->copy()->addDays(2)->format('d/m/Y')]))
            ->assertStatus(409);
    }

    public function test_toggle_and_delete(): void
    {
        $empty = $this->tour();
        $this->as()->postJson('/admin/toggle-tour', ['tourId' => $empty])->assertJson(['success' => true]);   // đang hiện -> ẩn
        $this->as()->postJson('/admin/toggle-tour', ['tourId' => $empty])->assertJson(['success' => false]);  // hiện lại: thiếu ảnh
        $this->as()->postJson('/admin/delete-tour', ['tourId' => $empty])->assertJson(['success' => true]);
        $this->assertFalse(DB::table('tbl_tours')->where('tourId', $empty)->exists());

        $booked = $this->tour();
        $this->booking($booked, $this->user(), 'n');
        $this->as()->postJson('/admin/delete-tour', ['tourId' => $booked])->assertJson(['success' => true]);
        $this->assertSame(0, (int) DB::table('tbl_tours')->where('tourId', $booked)->value('availability'), 'có đơn: chỉ ẩn, không xóa');
    }

    // ------------------------------------------------------------------ L-E-20, L-E-08

    public function test_cleanup_removes_stale_temp_images_and_abandoned_drafts(): void
    {
        $svc = app(\App\Services\TourImageService::class);
        // nháp bỏ dở 10 ngày, có ảnh tạm cũ
        $draft = $this->tour(['availability' => 0, 'createdAt' => now()->subDays(10)]);
        $old = $svc->storeUploaded(UploadedFile::fake()->image('d.jpg', 800, 600), $draft)['stem'];
        DB::table('tbl_temp_images')->insert(['tourId' => $draft, 'imageTempURL' => $old, 'uploadDate' => now()->subDays(9)]);
        // nháp đang làm dở hôm nay: ảnh tạm mới phải giữ
        $fresh = $this->tour(['availability' => 0, 'createdAt' => now()]);
        $new = $svc->storeUploaded(UploadedFile::fake()->image('f.jpg', 801, 600), $fresh)['stem'];
        DB::table('tbl_temp_images')->insert(['tourId' => $fresh, 'imageTempURL' => $new, 'uploadDate' => now()]);
        // nháp hôm nay nhưng ảnh tạm 2 ngày trước (wizard bỏ ở bước 2): dòng tạm quá hạn bị dọn
        $stale = $svc->storeUploaded(UploadedFile::fake()->image('s.jpg', 802, 600), $fresh)['stem'];
        DB::table('tbl_temp_images')->insert(['tourId' => $fresh, 'imageTempURL' => $stale, 'uploadDate' => now()->subDays(2)]);
        // tour đã đăng: ảnh đang dùng, không bao giờ xóa
        $live = $this->tour();
        $used = $svc->storeUploaded(UploadedFile::fake()->image('l.jpg', 803, 600), $live)['stem'];
        DB::table('tbl_images')->insert(['tourId' => $live, 'imageURL' => $used, 'description' => '', 'sortOrder' => 0]);

        $this->artisan('media:cleanup-orphans', ['--min-age' => 1])->assertSuccessful();

        $this->assertFalse(DB::table('tbl_tours')->where('tourId', $draft)->exists(), 'nháp bỏ dở bị xóa');
        $this->assertTrue(DB::table('tbl_tours')->where('tourId', $fresh)->exists());
        $this->assertSame([$new], DB::table('tbl_temp_images')->pluck('imageTempURL')->all(), 'chỉ còn ảnh tạm còn mới');
        $this->assertTrue($svc->stemExists($used));
        $this->assertFalse($svc->stemExists($old));
    }

    // ------------------------------------------------------------------ L-F-11, L-B-24

    public function test_paid_bookings_of_finished_tours_are_completed_automatically(): void
    {
        $u = $this->user();
        $past = $this->tour(['startDate' => now()->subDays(6)->toDateString(), 'endDate' => now()->subDays(4)->toDateString()]);
        $paid = $this->booking($past, $u, 'y', 'y');
        $unpaid = $this->booking($past, $u, 'y', 'n');
        $future = $this->booking($this->tour(), $u, 'y', 'y');

        $this->artisan('bookings:complete-finished')->assertSuccessful();

        $this->assertSame('f', DB::table('tbl_booking')->where('bookingId', $paid)->value('bookingStatus'));
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $unpaid)->value('bookingStatus'), 'chưa thanh toán: để admin xử lý');
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $future)->value('bookingStatus'));
    }

    public function test_expired_hold_is_recorded_as_expired_not_customer_cancel(): void
    {
        $t = $this->tour();
        $b = $this->booking($t, $this->user(), 'n');
        DB::table('tbl_booking')->where('bookingId', $b)->update(['holdExpiresAt' => now()->subHour()]);

        app(BookingService::class)->expireHolds();

        $row = DB::table('tbl_booking')->where('bookingId', $b)->first();
        $this->assertSame('c', $row->bookingStatus);
        $this->assertSame('expired', $row->cancelReason);
    }

    // ------------------------------------------------------------------ L-B-23, L-F-10, L-C-05

    public function test_detail_page_has_no_book_button_on_departure_day(): void
    {
        $t = $this->tour(['startDate' => now()->toDateString(), 'endDate' => now()->addDays(2)->toDateString()]);
        DB::table('tbl_timeline')->insert(['tourId' => $t, 'title' => 'N1', 'description' => 'a']);
        $html = $this->get('/tour-detail/' . $t)->assertOk()->getContent();
        $this->assertStringContainsString('Tour đã đóng đặt chỗ', $html, 'đúng ngày khởi hành: nút đặt bị khóa (trang đặt cũng từ chối)');
        $this->assertStringNotContainsString('>Đặt ngay', $html);
    }

    public function test_hidden_reviews_are_not_shown_or_counted_and_reviews_paginate(): void
    {
        $t = $this->tour();
        DB::table('tbl_timeline')->insert(['tourId' => $t, 'title' => 'N1', 'description' => 'a']);
        for ($i = 1; $i <= 12; $i++) {
            DB::table('tbl_reviews')->insert(['tourId' => $t, 'userId' => $this->user("u$i"), 'rating' => 5, 'comment' => "Hay lắm $i", 'timestamp' => now()->subMinutes($i)]);
        }
        $bad = DB::table('tbl_reviews')->insertGetId(['tourId' => $t, 'userId' => $this->user('spam'), 'rating' => 1, 'comment' => 'SPAM quảng cáo', 'timestamp' => now()]);

        $this->as()->post("/admin/reviews/$bad/toggle")->assertRedirect();
        $this->assertSame(1, (int) DB::table('tbl_reviews')->where('reviewId', $bad)->value('isHidden'));

        $html = $this->get('/tour-detail/' . $t)->assertOk()->getContent();
        $this->assertStringNotContainsString('SPAM quảng cáo', $html);
        $this->assertStringContainsString('12 đánh giá', $html, 'đánh giá ẩn không tính');
        $this->assertStringContainsString('Xem thêm đánh giá (2)', $html);

        $more = $this->getJson('/tour-detail/' . $t . '/danh-gia?page=2')->assertOk()->json();
        $this->assertFalse($more['hasMore']);
        $this->assertSame(2, substr_count($more['html'], 'td-review"'));
        $this->assertStringNotContainsString('password', $more['html']);
    }

    // ------------------------------------------------------------------ L-D-11

    public function test_days_filter_uses_real_dates(): void
    {
        // chuỗi "time" cố tình sai: lọc phải theo ngày đi / ngày về (L-D-11)
        $this->tour(['title' => 'Bốn ngày', 'time' => '1 ngày', 'endDate' => now()->addDays(13)->toDateString(), 'durationDays' => null]);
        $this->tour(['title' => 'Ba ngày', 'time' => '4 ngày 3 đêm', 'durationDays' => null]);

        $titles = (new \App\Services\TourCatalog())->paginate(['days' => 'mid'], 20)->getCollection()->pluck('title')->all();

        $this->assertSame(['Bốn ngày'], $titles);
    }
}
