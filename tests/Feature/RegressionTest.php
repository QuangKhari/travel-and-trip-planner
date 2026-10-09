<?php

namespace Tests\Feature;

use App\Services\BookingException;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $over = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_users')->insertGetId($over + [
            'fullName' => "User $n",
            'username' => "user$n",
            'password' => 'x',
            'email' => "user$n@example.com",
            'isActive' => 'y',
        ]);
    }

    private function makeAdmin(): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_admin')->insertGetId([
            'userName' => "admin$n",
            'passWord' => 'x',
            'email' => "admin$n@example.com",
            'fullName' => 'Admin',
            'address' => 'HN',
            'role' => 'admin',
        ]);
    }

    private function makeTour(int $quantity = 5, int $daysAhead = 10): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour thử',
            'time' => '3 ngày 2 đêm',
            'description' => '<p>mô tả</p>',
            'quantity' => $quantity,
            'priceAdult' => 1000000,
            'priceChild' => 500000,
            'destination' => 'Đà Nẵng',
            'domain' => 't',
            'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(),
            'endDate' => now()->addDays($daysAhead + 2)->toDateString(),
        ]);
    }

    private function bookingInput(int $tourId, int $adults = 1, int $children = 0): array
    {
        return [
            'tourId' => $tourId,
            'fullName' => 'Khach',
            'email' => 'k@example.com',
            'tel' => '0900000000',
            'address' => 'HN',
            'numAdults' => $adults,
            'numChildren' => $children,
            'couponCode' => '',
        ];
    }

    private function quantity(int $tourId): int
    {
        return (int) DB::table('tbl_tours')->where('tourId', $tourId)->value('quantity');
    }

    public function test_guest_is_redirected_from_my_tours(): void
    {
        $this->get('/my-tours')->assertRedirect(route('login'));
    }

    public function test_logout_clears_user_session(): void
    {
        $this->withSession(['userId' => 1, 'username' => 'a', 'avatar' => 'avatars/1/x.webp'])
            ->post('/logout')
            ->assertRedirect(route('home'))
            ->assertSessionMissing('userId')
            ->assertSessionMissing('avatar');
    }

    public function test_my_tours_page_renders_for_user_with_booking(): void
    {
        $user = $this->makeUser();
        $tour = $this->makeTour();
        app(BookingService::class)->create($user, $this->bookingInput($tour));

        $this->withSession(['userId' => $user, 'username' => 'u'])
            ->get('/my-tours')
            ->assertOk();
    }

    public function test_booking_decrements_seats_and_rejects_overbooking(): void
    {
        $user = $this->makeUser();
        $tour = $this->makeTour(3);
        $service = app(BookingService::class);

        $service->create($user, $this->bookingInput($tour, 2));
        $this->assertSame(1, $this->quantity($tour));

        $this->expectException(BookingException::class);
        $service->create($user, $this->bookingInput($tour, 2));
    }

    public function test_only_owner_can_cancel_and_seats_are_returned(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $tour = $this->makeTour(5);
        $result = app(BookingService::class)->create($owner, $this->bookingInput($tour, 2));

        $this->withSession(['userId' => $other, 'username' => 'o'])
            ->post('/cancel-booking', ['bookingId' => $result['bookingId']])
            ->assertNotFound();
        $this->assertSame(3, $this->quantity($tour));

        $this->withSession(['userId' => $owner, 'username' => 'u'])
            ->post('/cancel-booking', ['bookingId' => $result['bookingId']])
            ->assertRedirect(route('home'));
        $this->assertSame(5, $this->quantity($tour));
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $result['bookingId'])->value('bookingStatus'));
    }

    public function test_admin_can_block_and_unblock_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser(['status' => 'b']);

        $this->withSession(['adminId' => $admin])
            ->postJson('/admin/status-user', ['userId' => $user, 'status' => 'active'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(DB::table('tbl_users')->where('userId', $user)->value('status'));
    }
}
