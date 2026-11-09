<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_database_passes(): void
    {
        $this->artisan('integrity:audit')->expectsOutputToContain('Không phát hiện')->assertExitCode(0);
    }

    public function test_overbooked_departure_and_resource_overlap_are_reported(): void
    {
        $tourId = DB::table('tbl_tours')->insertGetId(['title' => 'T', 'time' => '1 ngày', 'description' => 'm', 'quantity' => 2, 'priceAdult' => 1, 'priceChild' => 1,
            'destination' => 'X', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays(9)->toDateString(), 'endDate' => now()->addDays(9)->toDateString()]);
        // bộ đếm đã có CHECK ở DB (chk_avail_slots); mô phỏng bộ đếm LỆCH so với đơn thật: 3 khách đã xác nhận trên 2 chỗ
        $av = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $tourId, 'departureDate' => now()->addDays(9)->toDateString(), 'totalSlots' => 2,
            'bookedSlots' => 2, 'heldSlots' => 0, 'status' => 'open', 'createdAt' => now()]);
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'ia', 'email' => 'ia@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        DB::table('tbl_booking')->insert(\App\Support\BookingPii::encrypt(['tourId' => $tourId, 'userId' => $uid, 'availabilityId' => $av, 'departureDate' => now()->addDays(9)->toDateString(),
            'fullName' => 'K', 'email' => 'k@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN', 'numAdults' => 3, 'numChildren' => 0,
            'totalPrice' => 3, 'bookingStatus' => 'y', 'bookingCode' => 'IA' . uniqid()]));
        $r = DB::table('tbl_rescue_resources')->insertGetId(['name' => 'Xe', 'kind' => 'transport', 'capacity' => 16, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        // ghi thẳng (bỏ qua service) để mô phỏng dữ liệu hỏng
        foreach ([[10, '06:00', '12:00'], [9, '11:00', '15:00']] as [$q, $s, $e]) {
            DB::table('tbl_resource_allocations')->insert(['resourceId' => $r, 'ownerType' => 'departure', 'ownerId' => $q, 'startAt' => "2026-12-12 $s",
                'endAt' => "2026-12-12 $e", 'quantity' => $q, 'status' => 'confirmed', 'createdAt' => now()]);
        }

        $this->artisan('integrity:audit')->expectsOutputToContain('seats.counter_drift')->expectsOutputToContain('resource.over_capacity')->assertExitCode(1);
    }
}
