<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Planner\BookingReader;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt C mục 20: đọc email / ảnh vé để điền sẵn biểu mẫu xác nhận; đọc xong KHÔNG tự lưu. */
class BookingReaderTest extends TestCase
{
    use RefreshDatabase;

    private function read(string $text): array
    {
        return app(BookingReader::class)->fromText($text, '2026-12-05')['fields'];
    }

    public function test_reads_common_vietnamese_and_english_confirmations(): void
    {
        $f = $this->read("Vietnam Airlines - Xác nhận đặt chỗ\nMã đặt chỗ: KX7Q2M\nChuyến bay VN 1543 Hồ Chí Minh (SGN) - Đà Lạt (DLI)\nKhởi hành: 07:15 Thứ Bảy, 05/12/2026\nHạ cánh: 08:10 05/12/2026\nTổng tiền: 2.450.000 VND");
        $this->assertSame(['provider' => 'Vietnam Airlines', 'kind' => 'flight', 'code' => 'KX7Q2M', 'startAt' => '2026-12-05T07:15', 'endAt' => '2026-12-05T08:10', 'price' => 2450000, 'title' => 'VN 1543 SGN→DLI'], $f);

        $f = $this->read("Booking.com\nYour booking is confirmed\nDalat Palace Heritage Hotel\nCheck-in: Sat, 5 Dec 2026 (from 14:00)\nCheck-out: Mon, 7 Dec 2026 (until 12:00)\nConfirmation number: 4123.556.789\nTotal price VND 5,600,000");
        $this->assertSame('hotel', $f['kind']);
        $this->assertSame('Dalat Palace Heritage Hotel', $f['title']);
        $this->assertSame(['2026-12-05T14:00', '2026-12-07T12:00'], [$f['startAt'], $f['endAt']]);
        $this->assertSame('4123.556.789', $f['code']);
        $this->assertSame(5600000, $f['price']);

        $f = $this->read("Agoda - Xác nhận đặt phòng\nMã đặt phòng: 1234567890\nKhách sạn: Ana Mandara Villas Dalat\nNhận phòng: 05 tháng 12, 2026\nTrả phòng: 07 tháng 12, 2026\nTổng cộng: 4.200.000 ₫");
        $this->assertSame(['Agoda', 'Ana Mandara Villas Dalat', '2026-12-05T14:00', '2026-12-07T12:00'], [$f['provider'], $f['title'], $f['startAt'], $f['endAt']]);

        $f = $this->read("Đường sắt Việt Nam - Vé điện tử\nMã đặt chỗ: ABCD1234\nTàu SE1 Sài Gòn - Nha Trang\nGa đi: Sài Gòn  Giờ đi: 19:30 05/12/2026\nGa đến: Nha Trang Giờ đến: 04:50 06/12/2026\nThành tiền: 780.000đ");
        $this->assertSame(['train', 'Tàu SE1 Sài Gòn → Nha Trang', '2026-12-05T19:30', '2026-12-06T04:50', 780000], [$f['kind'], $f['title'], $f['startAt'], $f['endAt'], $f['price']]);

        // email dạng HTML (bảng), chặng From/To
        $f = $this->read('<html><table><tr><td>Vietjet Air</td></tr><tr><td>Booking reference</td><td>QZ8L4P</td></tr><tr><td>Flight</td><td>VJ 331</td></tr><tr><td>From</td><td>HAN</td><td>To</td><td>DAD</td></tr><tr><td>Departure</td><td>12/12/2026 06:00</td></tr><tr><td>Total amount</td><td>1,099,000 VND</td></tr></table></html>');
        $this->assertSame(['flight', 'VJ 331 HAN→DAD', 'QZ8L4P', '2026-12-12T06:00', 1099000], [$f['kind'], $f['title'], $f['code'], $f['startAt'], $f['price']]);

        $f = $this->read("Vexere: Đặt vé thành công\nNhà xe Phương Trang\nTuyến: Sài Gòn - Đà Lạt\nGiờ đi: 22:00 ngày 04/12/2026\nMã vé: VXR8812\nGiá vé: 290.000đ");
        $this->assertSame(['bus', 'Xe Phương Trang Sài Gòn → Đà Lạt', '2026-12-04T22:00', 'VXR8812'], [$f['kind'], $f['title'], $f['startAt'], $f['code']]);
    }

    public function test_year_is_inferred_and_prices_are_not_dates(): void
    {
        // vé chỉ ghi ngày/tháng: lấy năm của chuyến; "1.250.000" không bị hiểu là ngày 1/250
        $f = $this->read("Vé tham quan cáp treo Robin Hill\nNgày sử dụng: 06/12 lúc 09:00\nMã vé: RB55821\nTổng: 1.250.000đ");
        $this->assertSame('ticket', $f['kind']);
        $this->assertSame('2026-12-06T09:00', $f['startAt']);
        $this->assertSame(1250000, $f['price']);

        $r = app(BookingReader::class)->fromText('xin chào', null);
        $this->assertFalse($r['ok']);
    }

    public function test_endpoint_reads_but_never_saves(): void
    {
        $u = DB::table('tbl_users')->insertGetId(['username' => 'docve', 'email' => 'dv@example.com', 'password' => 'x', 'fullName' => 'Đọc Vé', 'isActive' => 'y']);
        $other = DB::table('tbl_users')->insertGetId(['username' => 'nguoila', 'email' => 'nl@example.com', 'password' => 'x', 'fullName' => 'Người Lạ', 'isActive' => 'y']);
        $trip = app(TripPlanner::class)->create($u, ['title' => 'Đà Lạt', 'days' => 3, 'groupSize' => 2, 'startDate' => '2026-12-05']);
        $before = DB::table('tbl_trip_external')->where('tripId', $trip)->count();

        $j = $this->withSession(['userId' => $u, 'username' => 'docve'])
            ->postJson("/len-ke-hoach/$trip/xac-nhan/doc", ['text' => "Mã đặt chỗ: KX7Q2M\nChuyến bay VN 1543 SGN - DLI\nKhởi hành: 07:15 05/12"])
            ->assertOk()->json();
        $this->assertTrue($j['ok']);
        $this->assertSame('2026-12-05T07:15', $j['fields']['startAt']);
        $this->assertContains('code', $j['found']);
        $this->assertSame($before, DB::table('tbl_trip_external')->where('tripId', $trip)->count());

        $this->postJson("/len-ke-hoach/$trip/xac-nhan/doc", [])->assertStatus(422);
        $this->withSession(['userId' => $other, 'username' => 'nguoila'])->postJson("/len-ke-hoach/$trip/xac-nhan/doc", ['text' => 'abc abc abc abc'])->assertNotFound();

        // ảnh khi chưa bật AI: báo dán chữ / nhập tay, không lỗi
        config(['ai.enabled' => false]);
        $img = UploadedFile::fake()->image('ve.png', 40, 40);
        $j = $this->withSession(['userId' => $u, 'username' => 'docve'])->post("/len-ke-hoach/$trip/xac-nhan/doc", ['file' => $img], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertFalse($j['ok']);
        $this->assertStringContainsString('dán nội dung email', mb_strtolower($j['message']));
    }

    public function test_image_is_read_by_vision_model_and_fields_are_validated(): void
    {
        config(['ai.enabled' => true, 'ai.features.booking_read' => true]);
        $fake = new FakeLlm(['booking_read' => ['kind' => 'flight', 'title' => 'VN 1543 SGN-DLI', 'provider' => 'Vietnam Airlines', 'code' => 'kx7q2m',
            'start' => '2026-12-05 07:15', 'end' => 'không rõ', 'price' => 2450000]]);
        $this->app->instance(StructuredLlm::class, $fake);

        $r = app(BookingReader::class)->fromImage('PNGDATA', 'image/png', null, '2026-12-05');
        $this->assertTrue($r['ok']);
        $this->assertSame('KX7Q2M', $r['fields']['code']);
        $this->assertSame('2026-12-05T07:15', $r['fields']['startAt']);
        $this->assertArrayNotHasKey('endAt', $r['fields']);           // giá trị không đúng dạng ngày bị bỏ
        $this->assertSame('image/png', $fake->lastOpt['images'][0]['mime']);
        $this->assertSame(base64_encode('PNGDATA'), $fake->lastOpt['images'][0]['data']);
    }
}
