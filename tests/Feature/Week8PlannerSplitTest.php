<?php

namespace Tests\Feature;

use App\Services\Planner\PlaceGeocoder;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tuần 8: tách trang tự lập / Travela đề xuất, và tìm địa điểm theo tên ngoài kho (Photon / Geoapify). */
class Week8PlannerSplitTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $other;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['planner.geocoder' => 'photon', 'planner.geoapify_key' => null]);
        DB::table('tbl_destinations')->insert(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(?int $uid = null): int
    {
        return app(TripPlanner::class)->create($uid ?? $this->uid, ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function photonPayload(): array
    {
        return ['features' => [
            ['properties' => ['name' => 'Hồ Hoàn Kiếm', 'district' => 'Hoàn Kiếm', 'city' => 'Hà Nội', 'osm_value' => 'park'], 'geometry' => ['coordinates' => [105.8523, 21.0288]]],
            ['properties' => ['name' => 'Hồ Hoàn Kiếm', 'district' => 'Hoàn Kiếm', 'city' => 'Hà Nội', 'osm_value' => 'park'], 'geometry' => ['coordinates' => [105.85231, 21.02881]]],
            ['properties' => ['name' => 'Phở Thìn', 'street' => 'Lò Đúc', 'housenumber' => '13', 'city' => 'Hà Nội', 'osm_value' => 'restaurant'], 'geometry' => ['coordinates' => [105.8601, 21.0172]]],
            ['properties' => ['osm_value' => 'x'], 'geometry' => ['coordinates' => [105.0, 21.0]]],
            ['properties' => ['name' => 'Hỏng'], 'geometry' => ['coordinates' => ['a', 'b']]],
        ]];
    }

    // ---------------------------------------------------------------- tách trang

    public function test_planner_page_is_manual_only_with_link_to_suggest_page(): void
    {
        $this->withSession($this->s)->get('/len-ke-hoach')->assertOk()
            ->assertSee('Tạo chuyến đi mới')
            ->assertSee('Bắt đầu lên kế hoạch')
            ->assertSee('/len-ke-hoach/de-xuat', false)
            ->assertDontSee('Điền sẵn giúp tôi');
    }

    public function test_old_prefill_links_redirect_to_suggest_page_keeping_query(): void
    {
        $this->withSession($this->s)->get('/len-ke-hoach?thang=10&q=Hà+Nội')
            ->assertRedirect(route('planner.suggest', ['thang' => '10', 'q' => 'Hà Nội']));
    }

    public function test_suggest_page_has_auto_form_and_back_link(): void
    {
        $this->withSession($this->s)->get('/len-ke-hoach/de-xuat')->assertOk()
            ->assertSee('Điền sẵn giúp tôi')
            ->assertSee('/len-ke-hoach/tao-tu-dong', false);
    }

    public function test_suggest_page_requires_login(): void
    {
        $this->get('/len-ke-hoach/de-xuat')->assertRedirect();
    }

    // ---------------------------------------------------------------- tìm ngoài kho

    public function test_parse_photon_normalizes_dedupes_and_skips_bad_rows(): void
    {
        $rows = PlaceGeocoder::parsePhoton($this->photonPayload());
        $this->assertCount(2, $rows);
        $this->assertSame('Hồ Hoàn Kiếm', $rows[0]['name']);
        $this->assertSame('Công viên', $rows[0]['kind']);
        $this->assertEqualsWithDelta(21.0288, $rows[0]['lat'], 1e-6);
        $this->assertEqualsWithDelta(105.8523, $rows[0]['lng'], 1e-6);
        $this->assertSame('21.0288,105.8523', $rows[0]['key']);
        $this->assertSame('Nhà hàng', $rows[1]['kind']);
        $this->assertStringContainsString('13 Lò Đúc', $rows[1]['address']);
    }

    public function test_parse_geoapify_normalizes_rows(): void
    {
        $rows = PlaceGeocoder::parseGeoapify(['features' => [
            ['properties' => ['name' => 'Khách sạn Sen', 'formatted' => 'Khách sạn Sen, Huế', 'address_line2' => 'Huế, Việt Nam', 'lat' => 16.46, 'lon' => 107.59, 'category' => 'accommodation.hotel']],
            ['properties' => ['lat' => 1, 'lon' => 2]],
        ]]);
        $this->assertCount(1, $rows);
        $this->assertSame('Khách sạn', $rows[0]['kind']);
        $this->assertSame('Huế, Việt Nam', $rows[0]['address']);
    }

    public function test_external_search_returns_results_and_sends_bias_and_bbox(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response($this->photonPayload())]);
        $id = $this->trip();
        $r = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ho guom') . '&lat=21.03&lng=105.85')->assertOk();
        $r->assertJsonPath('ok', true)->assertJsonPath('provider', 'photon')->assertJsonCount(2, 'results');
        Http::assertSent(function ($req) {
            $q = $req->data();

            return str_contains($req->url(), 'photon.komoot.io') && $q['q'] === 'ho guom' && isset($q['bbox']) && (float) $q['lat'] == 21.03 && (float) $q['lon'] == 105.85 && !isset($q['lang']);
        });
    }

    public function test_external_search_uses_geoapify_when_key_is_set(): void
    {
        config(['planner.geocoder' => null, 'planner.geoapify_key' => 'k123']);
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => [
            ['properties' => ['name' => 'Chợ Bến Thành', 'lat' => 10.772, 'lon' => 106.698, 'category' => 'commercial.marketplace']],
        ]])]);
        $id = $this->trip();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('Bến Thành'))->assertOk()
            ->assertJsonPath('provider', 'geoapify')->assertJsonPath('results.0.kind', 'Chợ');
        Http::assertSent(fn($req) => str_contains($req->url(), 'api.geoapify.com') && $req['apiKey'] === 'k123' && str_starts_with($req['filter'], 'rect:'));
    }

    public function test_external_search_is_cached(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response($this->photonPayload())]);
        $id = $this->trip();
        for ($i = 0; $i < 3; $i++) {
            $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ho guom'))->assertOk();
        }
        Http::assertSentCount(1);
    }

    public function test_external_failure_reports_ok_false_and_is_not_cached(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response('boom', 500)]);
        $id = $this->trip();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ho guom'))->assertOk()
            ->assertJsonPath('ok', false)->assertJsonPath('results', []);
        Http::fake(['photon.komoot.io/*' => Http::response($this->photonPayload())]);
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ho guom'))->assertJsonPath('ok', true);
    }

    public function test_external_search_validation_ownership_and_auth(): void
    {
        Http::fake();
        $id = $this->trip();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai")->assertStatus(422);
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=a")->assertStatus(422);
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ok ten') . '&lat=999&lng=1')->assertStatus(422);
        $this->withSession($this->s)->getJson("/len-ke-hoach/" . $this->trip($this->other) . '/tim-ngoai?q=' . urlencode('ok ten'))->assertNotFound();
        Http::assertNothingSent();
        $this->flushSession();
        $this->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ok ten'))->assertStatus(401);
    }

    public function test_provider_off_disables_search_and_hides_url_from_page(): void
    {
        config(['planner.geocoder' => 'off']);
        Http::fake();
        $id = $this->trip();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/tim-ngoai?q=" . urlencode('ho guom'))->assertOk()->assertJsonPath('results', []);
        Http::assertNothingSent();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('"searchExtUrl":null', false);
    }

    // ---------------------------------------------------------------- trang sửa

    public function test_editor_page_has_unified_search_and_three_tabs(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('id="tpQ"', false)
            ->assertSee('id="tpManual"', false)
            ->assertSee('data-tab="plan"', false)
            ->assertSee('data-tab="cost"', false)
            ->assertSee('data-tab="info"', false)
            ->assertSee('tim-ngoai', false)
            ->assertDontSee('data-tab="explore"', false)
            ->assertDontSee('id="tpSearchForm"', false);
    }

    public function test_external_result_becomes_positioned_note_with_search_source(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'note', 'label' => 'Phở Thìn', 'note' => '13 Lò Đúc, Hà Nội', 'day' => 1, 'lat' => 21.0172, 'lng' => 105.8601, 'source' => 'search'],
        ]])->assertOk();
        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertSame('search', $row->source);
        $this->assertEqualsWithDelta(21.0172, (float) $row->latitude, 1e-6);
    }
}
