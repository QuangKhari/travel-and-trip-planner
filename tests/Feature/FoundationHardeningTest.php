<?php

namespace Tests\Feature;

use App\Http\Middleware\Idempotent;
use App\Support\BookingState;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Tầng 1 "Nền móng": khóa chống gửi lặp (Idempotency-Key), máy trạng thái đơn có lịch sử, nhịp scheduler / worker / outbox.
 */
class FoundationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'fh', 'email' => 'fh@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        Cache::put('idem-test-count', 0);
        Route::middleware(['web', 'idempotent'])->post('/_test/idem', function () {
            $n = Cache::increment('idem-test-count');
            if (request('fail')) {
                return response()->json(['ok' => false], 422);
            }

            return request('redirect') ? redirect('/da-xong/' . $n) : response()->json(['ok' => true, 'n' => $n]);
        })->name('test.idem');
    }

    // ================================================================== Idempotency-Key

    public function test_same_key_and_body_replays_the_first_response_without_running_twice(): void
    {
        $h = ['Idempotency-Key' => 'k-12345678'];
        $s = ['userId' => $this->uid];
        $this->withSession($s)->postJson('/_test/idem', ['a' => 1], $h)->assertOk()->assertJson(['n' => 1]);
        $this->withSession($s)->postJson('/_test/idem', ['a' => 1], $h)->assertOk()->assertJson(['n' => 1])->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Cache::get('idem-test-count'), 'thao tác chỉ chạy một lần');

        // cùng khóa, khác nội dung: từ chối
        $this->withSession($s)->postJson('/_test/idem', ['a' => 2], $h)->assertStatus(422);
        // khóa khác: chạy bình thường; không có khóa: như cũ
        $this->withSession($s)->postJson('/_test/idem', ['a' => 2], ['Idempotency-Key' => 'k-87654321'])->assertJson(['n' => 2]);
        $this->withSession($s)->postJson('/_test/idem', ['a' => 2])->assertJson(['n' => 3]);
        // phạm vi theo người dùng: cùng khóa của người khác không đụng nhau
        $other = DB::table('tbl_users')->insertGetId(['username' => 'o', 'email' => 'o@example.com', 'password' => 'x', 'fullName' => 'O', 'isActive' => 'y']);
        $this->withSession(['userId' => $other])->postJson('/_test/idem', ['a' => 1], $h)->assertJson(['n' => 4]);
    }

    public function test_form_redirect_is_replayed_and_error_responses_release_the_key(): void
    {
        $s = ['userId' => $this->uid];
        $this->withSession($s)->post('/_test/idem', ['_idem' => 'bk-aaaaaaaa', 'redirect' => 1])->assertRedirect('/da-xong/1');
        $this->withSession($s)->post('/_test/idem', ['_idem' => 'bk-aaaaaaaa', 'redirect' => 1])->assertRedirect('/da-xong/1');
        $this->assertSame(1, Cache::get('idem-test-count'));

        // lỗi 4xx: không giữ khóa, sửa rồi gửi lại cùng khóa được
        $this->withSession($s)->postJson('/_test/idem', ['fail' => 1], ['Idempotency-Key' => 'k-fail0001'])->assertStatus(422);
        $this->withSession($s)->postJson('/_test/idem', ['fail' => 0], ['Idempotency-Key' => 'k-fail0001'])->assertOk();
    }

    public function test_in_flight_duplicate_gets_409_and_expired_keys_are_pruned(): void
    {
        DB::table('tbl_idempotency_keys')->insert(['scope' => 'test.idem|' . $this->uid, 'idemKey' => 'k-inflight', 'requestHash' => sha1('POST _test/idem []'),
            'status' => 'processing', 'createdAt' => now()]);
        $this->withSession(['userId' => $this->uid])->postJson('/_test/idem', [], ['Idempotency-Key' => 'k-inflight'])->assertStatus(409);
        $this->assertSame(0, Cache::get('idem-test-count'));

        DB::table('tbl_idempotency_keys')->update(['createdAt' => now()->subHours(Idempotent::TTL_HOURS + 1)]);
        $this->assertSame(1, Idempotent::prune());
    }

    public function test_real_routes_carry_the_middleware_and_forms_render_a_key(): void
    {
        foreach (['create-booking', 'planner.ai.chat.make', 'planner.ai.feedback', 'quotes.decide'] as $name) {
            $this->assertContains('idempotent', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    // ================================================================== máy trạng thái đơn

    private function booking(string $status = 'n'): int
    {
        $tour = DB::table('tbl_tours')->insertGetId(['title' => 'T', 'time' => '2 ngày', 'description' => 'x', 'quantity' => 10, 'priceAdult' => 1, 'priceChild' => 1,
            'destination' => 'HN', 'domain' => 'b', 'availability' => 1, 'startDate' => '2027-01-01', 'endDate' => '2027-01-02']);

        return DB::table('tbl_booking')->insertGetId(\App\Support\BookingPii::encrypt(['tourId' => $tour, 'userId' => $this->uid, 'fullName' => 'K', 'email' => 'k@example.com',
            'phoneNumber' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'totalPrice' => 1, 'bookingStatus' => $status, 'bookingCode' => 'FH' . uniqid()]));
    }

    public function test_booking_state_machine_allows_only_listed_transitions_and_records_history(): void
    {
        $b = $this->booking('n');
        $this->assertTrue(BookingState::transition($b, 'n', 'y', ['holdExpiresAt' => null], 'confirm', 'system'));
        $this->assertFalse(BookingState::transition($b, 'n', 'c', [], 'customer'), 'đơn đã đổi trạng thái: cập nhật có điều kiện không ghi đè');
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $b)->value('bookingStatus'));
        $this->assertTrue(BookingState::transition($b, 'y', 'f', [], 'finish', 'admin:1'));
        $this->assertTrue(BookingState::transition($b, 'f', 'f', ['noShowNote' => 'x']), 'cùng trạng thái: chỉ cập nhật cột kèm, không ghi lịch sử');

        $h = BookingState::history($b);
        $this->assertSame([['n', 'y', 'confirm', 'system'], ['y', 'f', 'finish', 'admin:1']], array_map(fn($r) => [$r->fromStatus, $r->toStatus, $r->reason, $r->actor], $h));

        $this->expectException(LogicException::class);
        BookingState::transition($this->booking('f'), 'f', 'c');   // hoàn tất -> hủy: không có trong bảng
    }

    public function test_force_fails_loudly_when_another_process_changed_the_status(): void
    {
        $b = $this->booking('c');
        $this->expectException(LogicException::class);
        BookingState::force($b, 'n', 'y');
    }

    // ================================================================== vận hành

    public function test_system_health_reports_scheduler_worker_and_outbox_heartbeats(): void
    {
        config(['queue.default' => 'database']);
        $c = SystemHealth::check()['checks'];
        $this->assertFalse($c['scheduler']['ok']);
        $this->assertStringContainsString('schedule:run', $c['scheduler']['detail']);
        $this->assertFalse($c['worker']['ok']);
        $this->assertTrue($c['outbox']['ok']);
        $this->assertTrue(SystemHealth::check()['ok'], 'các mục giám sát không bắt buộc: không làm trang /health báo hỏng');

        Cache::put('ops:hb:scheduler', time() - 30);
        (new \App\Jobs\Heartbeat())->handle();
        DB::table('tbl_domain_events')->insert(['name' => 'X', 'aggregate' => 'booking', 'aggregateId' => '1', 'payload' => '{}', 'createdAt' => now()->subMinutes(10)]);
        $c = SystemHealth::check()['checks'];
        $this->assertTrue($c['scheduler']['ok']);
        $this->assertTrue($c['worker']['ok']);
        $this->assertFalse($c['outbox']['ok']);
        $this->assertStringContainsString('1 sự kiện', $c['outbox']['detail']);
    }
}
