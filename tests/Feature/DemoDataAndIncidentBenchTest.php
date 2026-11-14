<?php

namespace Tests\Feature;

use App\Console\Commands\PlannerCoverage;
use App\Console\Commands\StudySummary;
use Database\Seeders\DemoCityPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gói dữ liệu 3 điểm đến demo (đủ giờ mở cửa, giá, trong / ngoài, thời lượng), đo độ phủ,
 * thí nghiệm sự cố chạy được và phát hiện đủ, công cụ tổng hợp thử nghiệm người dùng.
 */
class DemoDataAndIncidentBenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach ([1 => ['ha-noi', 'Hà Nội', 21.0285, 105.8542], 18 => ['hoi-an', 'Hội An', 15.8801, 108.338], 25 => ['da-lat', 'Đà Lạt', 11.9404, 108.4583]] as $id => [$slug, $name, $lat, $lng]) {
            DB::table('tbl_destinations')->insert(['destinationId' => $id, 'slug' => $slug, 'name' => $name, 'province' => $name, 'domain' => 'b',
                'description' => 'mô tả', 'latitude' => $lat, 'longitude' => $lng, 'isActive' => 1]);
        }
        DB::table('tbl_users')->insert(['username' => 'bench', 'email' => 'bench@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
    }

    public function test_city_pack_fills_coverage_and_is_idempotent(): void
    {
        $this->assertFalse(PlannerCoverage::measure(25)['ok']);
        $this->seed(DemoCityPackSeeder::class);
        $n = DB::table('tbl_external_places')->count();
        $this->seed(DemoCityPackSeeder::class);
        $this->assertSame($n, DB::table('tbl_external_places')->count(), 'chạy lại không nhân đôi');
        foreach (PlannerCoverage::DEMO as $id) {
            $c = PlannerCoverage::measure($id);
            $this->assertTrue($c['ok'], $c['name'] . ': ' . implode(', ', $c['gaps']));
            $this->assertGreaterThanOrEqual(80, $c['hoursPct']);
        }
        $this->assertSame(0, Artisan::call('planner:coverage'));
    }

    public function test_incident_bench_detects_every_injected_problem_and_rolls_back(): void
    {
        $this->seed(DemoCityPackSeeder::class);
        $trips = DB::table('tbl_trips')->count();
        $this->assertSame(0, Artisan::call('trips:incident-bench', ['--n' => 6]));
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/Tỷ lệ phát hiện[^:]*: (\d+)\/\1 = 100%/u', $out, $out);
        $this->assertMatchesRegularExpression('/cách sửa hợp lệ[^:]*: (\d+)\/\1 = 100%/u', $out);
        $this->assertStringContainsString('báo nhầm khi không có gì đổi: 0 sự kiện', $out);
        $this->assertSame($trips, DB::table('tbl_trips')->count(), 'mọi thay đổi bị hoàn tác');
    }

    public function test_sus_scoring(): void
    {
        $this->assertSame(100.0, StudySummary::sus([5, 1, 5, 1, 5, 1, 5, 1, 5, 1]));
        $this->assertSame(50.0, StudySummary::sus(array_fill(0, 10, 3)));
        $this->assertNull(StudySummary::sus([5, 1, 5]));
        $this->assertSame(0, Artisan::call('study:summary', ['file' => 'database/study/so_sanh_mau.csv']));
    }

    public function test_copilot_search_treats_descriptive_text_as_hint(): void
    {
        $this->seed(DemoCityPackSeeder::class);
        $trip = (object) ['tripId' => 0, 'destinationId' => 18, 'groupSize' => 2];
        $f = app(\App\Services\Ai\Copilot\PlaceFinder::class);
        $eat = $f->search($trip, ['text' => 'quán ăn tối', 'group' => 'eat'], ['keys' => [], 'dows' => []]);
        $this->assertNotEmpty($eat['cands'], 'chữ mô tả không phải tên quán: vẫn ra quán ăn');
        $cafe = $f->search($trip, ['text' => 'cà phê view đẹp', 'group' => 'cafe'], ['keys' => [], 'dows' => []]);
        $this->assertNotEmpty($cafe['cands']);
        $byName = $f->search($trip, ['text' => 'Morning Glory'], ['keys' => [], 'dows' => []]);
        $this->assertSame('Morning Glory', array_values($byName['cands'])[0]['name']);
    }
}
