<?php

namespace Tests\Feature;

use App\Services\Planner\PlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Đề xuất mở rộng: điểm đến chưa đủ nội dung đã duyệt, hoặc nơi bất kỳ trên bản đồ.
 * Ứng viên bổ sung từ OpenStreetMap (Photon giả lập), gắn nhãn, xem trước rồi lưu thành mục "từ bản đồ".
 */
class PlannerOpenModeTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['core_destinations.slugs' => [], 'planner.photon_url' => 'https://photon.test/api', 'planner.geocoder' => 'photon', 'planner.browse.live' => 'photon',
            'planner.browse.overpass_urls' => ['https://overpass.test/api/interpreter']]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'con-dao', 'name' => 'Côn Đảo', 'province' => 'Bà Rịa', 'domain' => 'n',
            'description' => 'ngắn', 'latitude' => 8.683, 'longitude' => 106.607, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
        Http::fake(['photon.test/reverse*' => fn($req) => Http::response($this->features((string) $req->url())), 'photon.test/api*' => Http::response(['features' => [
            ['geometry' => ['coordinates' => [104.98, 22.82]], 'properties' => ['name' => 'Hà Giang', 'osm_value' => 'city', 'state' => 'Hà Giang']],
        ]]), 'overpass.test/*' => Http::response('lỗi', 504)]);
    }

    /** Photon giả lập: trả mục đúng loại theo osm_tag trong yêu cầu, quanh điểm hỏi. */
    private function features(string $url): array
    {
        $u = urldecode($url);
        preg_match('/lat=([\d.]+)&lon=([\d.]+)/', $u, $m);
        [$lat, $lng] = [(float) ($m[1] ?? 8.683), (float) ($m[2] ?? 106.607)];
        $mk = fn($i, $name, $val, $dLat, $dLng) => ['geometry' => ['coordinates' => [$lng + $dLng, $lat + $dLat]],
            'properties' => ['name' => $name, 'osm_type' => 'N', 'osm_id' => $i, 'osm_value' => $val, 'street' => 'Tôn Đức Thắng']];
        if (str_contains($u, 'amenity:restaurant')) {
            return ['features' => [$mk(21, 'Quán Bếp Biển', 'restaurant', 0.004, 0.002), $mk(22, 'Nhà hàng Thu Tâm', 'restaurant', -0.003, 0.004), $mk(23, 'Quán Cơm Đảo', 'restaurant', 0.006, -0.002)]];
        }
        if (str_contains($u, 'amenity:cafe')) {
            return ['features' => [$mk(31, 'Cà phê Biển Xanh', 'cafe', 0.002, 0.001)]];
        }
        if (str_contains($u, 'tourism:')) {
            return ['features' => [$mk(1, 'Nhà tù Côn Đảo', 'museum', 0.003, 0.003), $mk(2, 'Nghĩa trang Hàng Dương', 'attraction', 0.008, 0.006),
                $mk(3, 'Bãi Đầm Trầu', 'attraction', 0.03, -0.02), $mk(4, 'Bảo tàng Côn Đảo', 'museum', 0.002, -0.001), $mk(5, 'Cầu tàu 914', 'attraction', -0.002, 0.001)]];
        }

        return ['features' => []];
    }

    private function q(array $x = []): array
    {
        return $x + ['destinationId' => $this->dest, 'month' => 6, 'days' => 2, 'adults' => 2];
    }

    public function test_not_ready_destination_uses_open_mode_with_map_items(): void
    {
        $r = app(PlannerService::class)->generate($this->q());
        $this->assertTrue($r['ok']);
        $this->assertSame('open', $r['mode']);
        $items = array_merge(...array_map(fn($d) => $d['items'], $r['plan']['days']));
        $this->assertNotEmpty($items);
        $this->assertTrue(collect($items)->every(fn($i) => !empty($i['osm'])), 'không có nội dung Travela: mọi mục đến từ bản đồ');
        $this->assertNotEmpty(array_filter($items, fn($i) => $i['isMeal']), 'có bữa ăn từ quán trên bản đồ');
        $this->assertGreaterThan(0, $r['plan']['stats']['fromMap']);
    }

    public function test_curated_mode_still_refuses_not_ready_destination(): void
    {
        $r = app(PlannerService::class)->generate($this->q(['mode' => 'curated']));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_ready', $r['code']);
    }

    public function test_preview_labels_map_items_and_saves_them_as_map_entries(): void
    {
        $html = $this->withSession($this->s)->get(route('planner.preview', $this->q()))->assertOk()
            ->assertSee('Đề xuất mở rộng')->assertSee('Bản đồ')->assertSee('OpenStreetMap')->getContent();
        $this->assertStringContainsString('id="pvMap"', $html);
        $this->assertSame(0, DB::table('tbl_trips')->count());

        $this->withSession($this->s)->post(route('planner.generate'), $this->q())->assertRedirect()->assertSessionHas('planner_notice');
        $trip = DB::table('tbl_trips')->first();
        $this->assertSame($this->dest, (int) $trip->destinationId);
        $rows = DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->whereNotNull('dayNo')->get();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('note', $row->refType);
            $this->assertNull($row->refId);
            $this->assertSame('search', $row->source);
            $this->assertNotNull($row->latitude);
            $this->assertNotEmpty($row->label);
        }
        // trang sửa mở được, sức khỏe chạy trên các mục này
        $this->withSession($this->s)->get("/len-ke-hoach/{$trip->tripId}")->assertOk()->assertSee('Nhà tù Côn Đảo', false);
        $this->withSession($this->s)->getJson("/len-ke-hoach/{$trip->tripId}/suc-khoe")->assertOk();
    }

    public function test_any_place_on_the_map_can_be_planned_without_a_destination(): void
    {
        $q = ['placeName' => 'Hà Giang', 'placeLat' => 22.82, 'placeLng' => 104.98, 'month' => 10, 'days' => 2, 'adults' => 2];
        $this->withSession($this->s)->get(route('planner.preview', $q))->assertOk()->assertSee('Hà Giang 2 ngày')->assertSee('Đề xuất mở rộng');
        $this->withSession($this->s)->post(route('planner.generate'), $q)->assertRedirect();
        $trip = DB::table('tbl_trips')->first();
        $this->assertNull($trip->destinationId);
        $this->assertStringStartsWith('Hà Giang 2 ngày', $trip->title);
        $this->assertGreaterThan(0, DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->count());
    }

    public function test_request_needs_a_destination_or_a_place(): void
    {
        $this->withSession($this->s)->get(route('planner.preview', ['month' => 6, 'days' => 2, 'adults' => 2]))->assertSessionHasErrors(['destinationId', 'placeLat']);
        $this->withSession($this->s)->get(route('planner.preview', ['placeLat' => 95, 'placeLng' => 10, 'month' => 6, 'days' => 2, 'adults' => 2]))->assertSessionHasErrors('placeLat');
    }

    public function test_open_mode_can_be_disabled(): void
    {
        config(['planner.open_mode.enabled' => false]);
        $r = app(PlannerService::class)->generate($this->q());
        $this->assertFalse($r['ok']);
    }

    public function test_place_search_returns_destinations_folded_and_map_places(): void
    {
        $j = $this->withSession($this->s)->getJson(route('planner.placeSearch', ['q' => 'con dao']))->assertOk()->json();
        $this->assertSame('Côn Đảo', $j['destinations'][0]['name']);
        $this->assertFalse($j['destinations'][0]['ready']);
        $j2 = $this->withSession($this->s)->getJson(route('planner.placeSearch', ['q' => 'ha giang']))->assertOk()->json();
        $this->assertSame('Hà Giang', $j2['places'][0]['name']);
        $this->assertEqualsWithDelta(22.82, $j2['places'][0]['lat'], 0.001);
        $this->withSession($this->s)->getJson(route('planner.placeSearch', ['q' => 'x']))->assertStatus(422);
        $this->flushSession();
        $this->assertContains($this->getJson(route('planner.placeSearch', ['q' => 'con dao']))->status(), [302, 401, 403]);   // phải đăng nhập
    }

    public function test_suggest_page_offers_open_mode_for_not_ready_destination(): void
    {
        $html = $this->withSession($this->s)->get(route('planner.suggest', ['diem-den' => 'con-dao']))->assertOk()->getContent();
        $this->assertStringContainsString('đề xuất mở rộng', $html);
        $this->assertStringContainsString('"openMode":true', $html);
        $this->assertStringContainsString('id="sgWhere"', $html);
    }
}
