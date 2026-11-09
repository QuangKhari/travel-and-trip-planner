<?php

namespace Tests\Feature;

use App\Services\Planner\ExternalGuard;
use App\Services\Planner\Route\HaversineProvider;
use App\Services\Planner\Route\OsrmProvider;
use App\Services\Planner\Route\RouteProvider;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Route Validator: mỗi chặng mang trạng thái số liệu.
 *   verified    OSRM trả tuyến thật
 *   estimated   OSRM lỗi / tắt -> ước tính chim bay
 *   unreachable OSRM nói không có đường (NoRoute hoặc ô null) -> KHÔNG phải 0 phút, là lỗi cứng trong sức khỏe chuyến
 *   unknown     tọa độ hỏng (0,0 / ngoài phạm vi)
 * Chuyến chỉ "feasible_verified" khi không có lỗi cứng và mọi chặng verified.
 */
class RouteStatusTest extends TestCase
{
    use RefreshDatabase;

    private const A = [21.03, 105.85];
    private const B = [21.06, 105.80];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        ExternalGuard::reset('osrm');
    }

    private function osrm(): RouteService
    {
        return new RouteService(new OsrmProvider('http://osrm.test', new HaversineProvider(1.3, 40)), 0.5);
    }

    public function test_osrm_ok_is_verified(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'distances' => [[0, 7300], [7300, 0]], 'durations' => [[0, 1260], [1260, 0]]])]);
        $t = $this->osrm()->travelTime([self::A, self::B]);
        $this->assertSame(RouteProvider::VERIFIED, $t['legs'][0]['status']);
        $this->assertSame(21, $t['min']);
        $this->assertSame('verified', $t['confidence']);
    }

    public function test_null_cell_is_unreachable_not_zero_minutes(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'distances' => [[0, null], [null, 0]], 'durations' => [[0, null], [null, 0]]])]);
        $t = $this->osrm()->travelTime([self::A, self::B]);
        $this->assertSame(RouteProvider::UNREACHABLE, $t['legs'][0]['status']);
        $this->assertGreaterThan(0, $t['legs'][0]['min'], 'không có đường thì không được thành 0 phút');
        $this->assertSame('blocked', $t['confidence']);
    }

    public function test_no_route_code_is_unreachable(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'NoRoute', 'message' => 'Impossible route'], 400)]);
        $svc = $this->osrm();
        $t = $svc->travelTime([self::A, self::B]);
        $this->assertSame(RouteProvider::UNREACHABLE, $t['legs'][0]['status']);
        $this->assertFalse($svc->status()['fallback'], 'NoRoute là câu trả lời thật của OSRM, không phải dự phòng');
    }

    public function test_osrm_down_is_estimated(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['message' => 'down'], 500)]);
        $t = $this->osrm()->travelTime([self::A, self::B]);
        $this->assertSame(RouteProvider::ESTIMATED, $t['legs'][0]['status']);
        $this->assertSame('estimated', $t['confidence']);
    }

    public function test_invalid_coordinates_are_unknown(): void
    {
        $r = RouteService::fromRules(['provider' => 'haversine', 'mode' => 'driving', 'factor' => 1.3, 'speedKmh' => 40.0, 'minLegKm' => 0.5, 'osrmProfile' => 'driving', 'osrmUrl' => '']);
        $t = $r->travelTime([self::A, [0.0, 0.0]]);
        $this->assertSame(RouteProvider::UNKNOWN, $t['legs'][0]['status']);
        $this->assertSame('uncertain', $t['confidence']);
        $this->assertSame(RouteProvider::UNKNOWN, $r->leg(self::A, [95.0, 10.0])['status']);
        $this->assertSame(RouteService::SAME_AREA, $r->leg(self::A, [21.0301, 105.8501])['status']);
    }

    public function test_invalid_point_is_unknown_and_never_sent_to_osrm(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'distances' => [[0, 7300], [7300, 0]], 'durations' => [[0, 1260], [1260, 0]]])]);
        $t = $this->osrm()->travelTime([self::A, [0.0, 0.0], self::B]);
        $this->assertSame([RouteProvider::UNKNOWN, RouteProvider::UNKNOWN], array_column($t['legs'], 'status'));
        $this->assertSame('uncertain', $t['confidence']);
        Http::assertSent(fn($r) => !str_contains($r->url(), '0,0;') && !str_contains($r->url(), ';0,0'));
        // ma trận đầy đủ: A↔B vẫn được OSRM xác minh
        $m = $this->osrm()->distanceMatrix([self::A, [0.0, 0.0], self::B]);
        $this->assertSame(RouteProvider::VERIFIED, $m[0][2]['status']);
        $this->assertSame(RouteProvider::UNKNOWN, $m[1][2]['status']);
    }

    public function test_asymmetric_matrix_is_kept(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'distances' => [[0, 7300], [9100, 0]], 'durations' => [[0, 1260], [1800, 0]]])]);
        $m = $this->osrm()->distanceMatrix([self::A, self::B]);
        $this->assertSame(21, $m[0][1]['min']);
        $this->assertSame(30, $m[1][0]['min'], 'A→B khác B→A');
    }

    // ---- sức khỏe chuyến đi -------------------------------------------------------------------------------

    private function tripWithTwoPoints(): array
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'r1', 'email' => 'r1@example.com', 'password' => 'x', 'fullName' => 'R', 'isActive' => 'y']);
        $id = app(TripPlanner::class)->create($uid, ['title' => 'Tuyến', 'days' => 1, 'groupSize' => 2, 'startDate' => '2026-11-16', 'budget' => null]);
        $s = ['userId' => $uid, 'username' => 'r1'];
        $items = [['type' => 'note', 'label' => 'A', 'lat' => self::A[0], 'lng' => self::A[1], 'source' => 'link', 'day' => 1],
            ['type' => 'note', 'label' => 'B', 'lat' => self::B[0], 'lng' => self::B[1], 'source' => 'link', 'day' => 1]];
        $this->withSession($s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Tuyến', 'days' => 1, 'groupSize' => 2, 'startDate' => '2026-11-16', 'items' => $items])->assertOk();

        return [$id, $s];
    }

    private function useOsrm(array $response, int $status = 200): void
    {
        config(['planner.route.provider' => 'osrm', 'planner.route.osrm_url' => 'http://osrm.test']);
        Http::fake(['osrm.test/*' => Http::response($response, $status)]);
        app()->forgetInstance(RouteService::class);
    }

    public function test_health_marks_unreachable_leg_as_hard_error(): void
    {
        [$id, $s] = $this->tripWithTwoPoints();
        $this->useOsrm(['code' => 'NoRoute'], 400);
        $h = $this->withSession($s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $c = collect($h['checks'])->firstWhere('code', 'route.unreachable');
        $this->assertNotNull($c);
        $this->assertSame('bad', $c['level']);
        $this->assertSame('hard', $c['severity']);
        $this->assertSame('needs_review', $h['feasibility']);
        $this->assertSame('unreachable', $h['days'][0]['rows'][0]['routeStatus']);
    }

    public function test_health_feasibility_levels(): void
    {
        [$id, $s] = $this->tripWithTwoPoints();
        // mặc định haversine -> ước tính
        $h = $this->withSession($s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $this->assertSame('feasible_with_uncertainty', $h['feasibility']);
        $this->assertSame('estimated', $h['routeQuality']['confidence']);

        $this->useOsrm(['code' => 'Ok', 'distances' => [[0, 7300], [7300, 0]], 'durations' => [[0, 1260], [1260, 0]]]);
        $h = $this->withSession($s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $this->assertSame('feasible_verified', $h['feasibility']);
        $this->assertSame(1, $h['routeQuality']['legs']['verified']);
    }
}
