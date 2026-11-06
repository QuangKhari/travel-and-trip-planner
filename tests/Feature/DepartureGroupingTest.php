<?php

namespace Tests\Feature;

use App\Services\DepartureGroupingService;
use App\Services\DepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartureGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function tour(string $destination = 'Sa Pa', string $itinerary = 'Núi Hàm Rồng'): array
    {
        $date = now()->addDays(20)->toDateString();
        $id = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour Sa Pa', 'time' => '3 ngày', 'description' => 'Tour thử nghiệm',
            'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => $destination, 'domain' => 'b', 'availability' => 1,
            'startDate' => $date, 'endDate' => now()->addDays(22)->toDateString(), 'durationDays' => 3,
        ]);
        DB::table('tbl_timeline')->insert(['tourId' => $id, 'title' => 'Ngày 1', 'description' => $itinerary]);
        app(DepartureService::class)->ensureForTour($id);
        $a = DB::table('tbl_tour_availability')->where('tourId', $id)->first();
        return [$id, (int) $a->availabilityId];
    }

    public function test_identical_departures_can_merge_without_changing_booking_or_inventory(): void
    {
        [$firstTour, $first] = $this->tour();
        [$secondTour, $second] = $this->tour();
        $svc = app(DepartureGroupingService::class);
        $this->assertTrue($svc->setMinimum($first, 8)['ok']);
        $this->assertCount(1, $svc->candidates($first));
        $result = $svc->merge($first, $second);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(2, DB::table('tbl_departure_group_members')->where('groupId', $result['id'])->count());
        $this->assertSame(20, (int) DB::table('tbl_departure_groups')->where('groupId', $result['id'])->value('capacity'));
        $this->assertSame(10, (int) DB::table('tbl_tour_availability')->where('availabilityId', $first)->value('totalSlots'));
        $this->assertSame(10, (int) DB::table('tbl_tour_availability')->where('availabilityId', $second)->value('totalSlots'));
        $this->assertFalse($svc->merge($first, $second)['ok']);
        $this->assertFalse(app(DepartureService::class)->update($first, 12, null, null)['ok']);
        $this->assertTrue($svc->disband($result['id'])['ok']);
        $this->assertSame(0, DB::table('tbl_departure_group_members')->where('groupId', $result['id'])->count());
    }

    public function test_different_itineraries_cannot_merge_even_with_same_destination(): void
    {
        [, $first] = $this->tour('Sa Pa', 'Núi Hàm Rồng');
        [, $second] = $this->tour('Sa Pa', 'Fansipan');
        $svc = app(DepartureGroupingService::class);
        $this->assertCount(0, $svc->candidates($first));
        $this->assertFalse($svc->merge($first, $second)['ok']);
    }

    public function test_minimum_cannot_exceed_departure_capacity(): void
    {
        [, $a] = $this->tour();
        $svc = app(DepartureGroupingService::class);
        $this->assertFalse($svc->setMinimum($a, 11)['ok']);
        $this->assertTrue($svc->setMinimum($a, 7)['ok']);
    }
}
