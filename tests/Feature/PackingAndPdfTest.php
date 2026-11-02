<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Planner\PackingList;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A-13 danh sách đồ mang theo (AI qua AiGateway, lùi về luật) và A-10d xuất PDF lịch trình + đơn tour. */
class PackingAndPdfTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $sapa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.features.packing' => true]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->sapa = DB::table('tbl_destinations')->insertGetId(['slug' => 'sa-pa', 'name' => 'Sa Pa', 'province' => 'Lào Cai', 'domain' => 'b',
            'description' => 'x', 'latitude' => 22.33, 'longitude' => 103.84, 'isActive' => 1]);
        DB::table('tbl_destination_climate')->insert(['destinationId' => $this->sapa, 'month' => 12, 'tMax' => 15, 'tMin' => 6, 'rainMm' => 30, 'rainyDays' => 5, 'years' => 10, 'periodFrom' => 2014, 'periodTo' => 2023, 'fetchedAt' => now()]);
    }

    private function trip(): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Sa Pa mùa đông', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->sapa, 'startDate' => '2026-12-20']);
        DB::table('tbl_trip_items')->insert([
            ['tripId' => $id, 'refType' => 'note', 'label' => 'Trekking Lao Chải', 'dayNo' => 1, 'orderNo' => 1, 'note' => 'đi bộ 12 km', 'cost' => 200000],
            ['tripId' => $id, 'refType' => 'note', 'label' => 'Nhà thờ đá', 'dayNo' => 2, 'orderNo' => 1, 'note' => null, 'cost' => null],
        ]);

        return $id;
    }

    private function names(array $groups): string
    {
        return implode(' | ', array_merge(...array_map(fn($g) => array_column($g['items'], 'name'), $groups)));
    }

    public function test_rules_follow_climate_and_activities(): void
    {
        $id = $this->trip();
        $r = app(PackingList::class)->forTrip(DB::table('tbl_trips')->where('tripId', $id)->first(), $this->uid, false);
        $this->assertSame('rules', $r['source']);
        $all = $this->names($r['groups']);
        $this->assertStringContainsString('Áo khoác dày', $all);          // tMin 6°C
        $this->assertStringContainsString('giày leo núi', $all);          // có trekking
        $this->assertStringNotContainsString('Đồ bơi', $all);
        $this->assertSame(12, $r['facts']['month']);
    }

    public function test_ai_list_is_used_and_cached_and_cleaned(): void
    {
        $fake = new FakeLlm(['packing' => ['note' => 'Đêm Sa Pa rất lạnh.', 'groups' => [
            ['name' => 'Giữ ấm', 'items' => [['name' => 'Áo phao', 'why' => 'Đêm 6°C'], ['name' => 'Áo phao', 'why' => ''], ['name' => '<b>Tất len</b>', 'why' => '']]],
            ['name' => 'Giấy tờ', 'items' => [['name' => 'CCCD', 'why' => ''], ['name' => 'Tiền mặt', 'why' => ''], ['name' => 'Mã đơn', 'why' => '']]],
        ]]]);
        $this->app->instance(StructuredLlm::class, $fake);
        $id = $this->trip();

        $j = $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/do-mang-theo")->assertOk()->json();
        $this->assertSame('ai', $j['source']);
        $this->assertSame('Đêm Sa Pa rất lạnh.', $j['note']);
        $this->assertSame(['Áo phao', 'Tất len'], array_column($j['groups'][0]['items'], 'name'));   // bỏ trùng, bỏ HTML
        $this->assertStringContainsString('Sa Pa', $fake->lastUser);
        $this->assertStringNotContainsString('u1@example.com', $fake->lastUser);

        $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/do-mang-theo")->assertOk();
        $this->assertSame(1, $fake->calls);   // lần hai lấy từ cache
        $this->assertSame(1, DB::table('tbl_ai_runs')->where(['feature' => 'packing', 'engine' => 'llm'])->count());
    }

    public function test_ai_failure_or_disabled_falls_back_to_rules(): void
    {
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'timeout'));
        $id = $this->trip();
        $j = $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/do-mang-theo")->assertOk()->json();
        $this->assertSame('rules', $j['source']);
        $this->assertNotEmpty($j['groups']);

        config(['ai.features.packing' => false]);
        $j = $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/do-mang-theo?ai=0")->assertOk()->json();
        $this->assertSame('rules', $j['source']);
    }

    public function test_other_users_cannot_read_packing_or_pdf(): void
    {
        $id = $this->trip();
        $other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'L', 'isActive' => 'y']);
        $this->withSession(['userId' => $other])->getJson("/len-ke-hoach/$id/do-mang-theo")->assertNotFound();
        $this->withSession(['userId' => $other])->getJson("/len-ke-hoach/$id/xuat-pdf")->assertNotFound();
    }

    public function test_pdf_export_contains_days_bookings_and_packing(): void
    {
        $id = $this->trip();
        $tour = DB::table('tbl_tours')->insertGetId(['title' => 'Tour Fansipan', 'time' => '1 ngày', 'description' => 'x', 'quantity' => 10, 'priceAdult' => 900000,
            'priceChild' => 500000, 'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => '2026-12-21', 'endDate' => '2026-12-21']);
        DB::table('tbl_trip_items')->insert(['tripId' => $id, 'refType' => 'tour', 'refId' => $tour, 'dayNo' => 2, 'orderNo' => 2]);
        $b = app(\App\Services\BookingService::class)->create($this->uid, ['tourId' => $tour, 'fullName' => 'K', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => 2, 'numChildren' => 0, 'couponCode' => '']);

        $j = $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/xuat-pdf")->assertOk()->json();
        $this->assertSame('Sa Pa mùa đông', $j['title']);
        $this->assertSame('20/12/2026', $j['days'][0]['date']);
        $this->assertSame('Trekking Lao Chải', $j['days'][0]['items'][0]['name']);
        $this->assertCount(1, $j['bookings']);
        $this->assertSame($b['bookingCode'], $j['bookings'][0]['code']);
        $this->assertSame(2, $j['bookings'][0]['people']);
        $this->assertNotEmpty($j['packing']);
    }
}
