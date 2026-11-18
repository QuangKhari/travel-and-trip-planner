<?php

namespace Tests\Feature;

use App\Console\Commands\ApiDocs;
use App\Support\AssetBundle;
use App\Support\ModuleMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tầng 2 "Kiến trúc": ranh giới phân hệ (modular monolith) được kiểm bằng test, sự kiện nghiệp vụ mới (PlaceHoursChanged),
 * API có phiên bản kèm đặc tả OpenAPI không lệch mã, JS rút gọn bằng esbuild có đường lùi.
 */
class ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_new_cross_module_table_access_and_baseline_only_shrinks(): void
    {
        $base = (array) config('modules.baseline');
        $found = array_values(array_unique(array_column(ModuleMap::violations(), 'key')));
        $new = array_diff($found, $base);
        $this->assertSame([], array_values($new), "Vi phạm ranh giới phân hệ MỚI (đọc / ghi bảng của phân hệ khác thay vì gọi giao diện app/Modules hoặc phát sự kiện):\n" . implode("\n", $new));
        $fixed = array_diff($base, $found);
        $this->assertSame([], array_values($fixed), "Nợ kỹ thuật đã được trả: xóa các dòng này khỏi modules.baseline để không tái phạm:\n" . implode("\n", $fixed));

        // Planner và AI không còn đọc thẳng bảng đơn / thanh toán
        $toCommerce = array_filter(ModuleMap::scan(), fn($h) => in_array($h['module'], ['Planner', 'AI'], true) && $h['owner'] === 'Commerce');
        $this->assertSame([], array_values($toCommerce));
        // mỗi bảng thuộc đúng một phân hệ
        $all = array_merge(...array_values(array_map(fn($m) => (array) $m['tables'], config('modules.modules'))));
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function test_scanner_detects_reads_writes_and_joins(): void
    {
        $f = 'app/Services/Planner/__ArchProbe.php';
        file_put_contents(base_path($f), "<?php\nDB::table('tbl_booking')->where('a', 1)->update(['x' => 1]);\nDB::table('tbl_trips as t')->join('tbl_checkout as c', 'c.id', '=', 't.id')->get();\n");
        try {
            $hits = array_values(array_filter(ModuleMap::scan(), fn($h) => $h['file'] === $f));
            $this->assertSame([['tbl_booking', 'write', 2], ['tbl_checkout', 'read', 3]], array_map(fn($h) => [$h['table'], $h['kind'], $h['line']], $hits));
            $this->artisan('arch:modules')->expectsOutputToContain('vi phạm MỚI')->assertFailed();
        } finally {
            @unlink(base_path($f));
        }
        $this->artisan('arch:modules')->expectsOutputToContain('Không có vi phạm mới')->assertSuccessful();
    }

    public function test_openapi_spec_matches_route_table_and_covers_new_endpoints(): void
    {
        $spec = ApiDocs::spec();
        $this->assertArrayHasKey('/api/v1/trips/{id}/readiness', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/trips/{id}/today', $spec['paths']);
        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v1/trips/{id}/today']['get']['security']);
        $this->artisan('api:docs', ['--check' => true])->assertSuccessful();
    }

    public function test_api_v1_today_and_readiness_for_mobile(): void
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'api', 'email' => 'api@example.com', 'password' => \App\Support\PasswordHasher::make('12345678'), 'fullName' => 'A', 'isActive' => 'y']);
        $hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'HN', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $p = DB::table('tbl_external_places')->insertGetId(['destinationId' => $hn, 'source' => 'manual', 'externalId' => 'vm', 'name' => 'Văn Miếu', 'category' => 'sight', 'kind' => 'museum',
            'latitude' => 21.0277, 'longitude' => 105.8355, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        $start = now()->subDay()->toDateString();
        $id = app(\App\Services\Planner\TripPlanner::class)->create($uid, ['title' => 'HN', 'days' => 3, 'groupSize' => 1, 'startDate' => $start, 'destinationId' => $hn]);
        $this->withSession(['userId' => $uid, 'username' => 'api'])->postJson("/len-ke-hoach/$id/luu", ['title' => 'HN', 'days' => 3, 'groupSize' => 1, 'startDate' => $start,
            'items' => [['type' => 'place', 'refId' => $p, 'day' => 2], ['type' => 'note', 'label' => 'Ăn trưa', 'day' => 2]]])->assertOk();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'api', 'password' => '12345678'])->assertOk()->json('access_token');
        $h = ['Authorization' => 'Bearer ' . $token];

        $j = $this->getJson("/api/v1/trips/$id/today", $h)->assertOk()->json();
        $this->assertSame(2, $j['day']);
        $this->assertSame(['Văn Miếu', 'Ăn trưa'], array_column($j['items'], 'name'));
        $this->assertSame('Văn Miếu', $j['next']['name']);
        $this->assertNotNull($j['items'][0]['start']);
        $this->getJson("/api/v1/trips/$id/today?date=2020-01-01", $h)->assertNotFound()->assertJsonPath('from', $start);
        $this->getJson("/api/v1/trips/$id/readiness", $h)->assertOk()->assertJsonPath('phase', 'during');
        $this->assertContains('auth:api', \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create("/api/v1/trips/$id/today"))->gatherMiddleware());
    }

    public function test_place_hours_changed_event_warns_upcoming_trips_with_options(): void
    {
        Cache::flush();
        Mail::fake();
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'ph', 'email' => 'ph@example.com', 'password' => 'x', 'fullName' => 'P', 'isActive' => 'y']);
        $hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'HN', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $mk = fn($n, $la, $lo) => DB::table('tbl_external_places')->insertGetId(['destinationId' => $hn, 'source' => 'manual', 'externalId' => $n, 'name' => $n, 'category' => 'sight', 'kind' => 'museum',
            'latitude' => $la, 'longitude' => $lo, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        $museum = $mk('Bảo tàng A', 21.031, 105.851);
        $mk('Bảo tàng B', 21.032, 105.852);   // nơi thay thế cùng loại, gần
        $start = now()->addDays(10)->startOfDay();
        $id = app(\App\Services\Planner\TripPlanner::class)->create($uid, ['title' => 'HN', 'days' => 2, 'groupSize' => 1, 'startDate' => $start->toDateString(), 'destinationId' => $hn]);
        $this->withSession(['userId' => $uid, 'username' => 'ph'])->postJson("/len-ke-hoach/$id/luu", ['title' => 'HN', 'days' => 2, 'groupSize' => 1, 'startDate' => $start->toDateString(),
            'items' => [['type' => 'place', 'refId' => $museum, 'day' => 1]]])->assertOk();

        // admin xác minh: bảo tàng đóng cửa đúng thứ của ngày 1
        $dow = $start->dayOfWeekIso;
        $osm = 'Mo-Su 08:00-17:00; ' . ['', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'][$dow] . ' off';
        app(\App\Services\Planner\PlaceFacts::class)->verify($museum, $osm, null, null, 'website', null, 1);

        $this->assertSame(1, DB::table('tbl_domain_events')->where('name', 'PlaceHoursChanged')->count());
        $ev = DB::table('tbl_trip_events')->where(['tripId' => $id, 'kind' => 'content'])->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('Bảo tàng A', $ev->title);
        $this->assertStringContainsString('phương án', $ev->text);
        $feed = app(\App\Services\Planner\TripWatch::class)->feed($id, $uid);
        $this->assertSame(['type' => 'resolve', 'code' => 'hours.closed', 'refType' => 'place', 'refId' => $museum], $feed['events'][0]['action']);

        // nhập giờ hàng loạt (nguồn khác admin / báo sai) không phát sự kiện
        app(\App\Services\Planner\OpeningHours::class)->store('place', $museum, '24/7', 'osm');
        $this->assertSame(1, DB::table('tbl_domain_events')->where('name', 'PlaceHoursChanged')->count());
    }

    public function test_minified_js_is_used_only_while_it_matches_the_source(): void
    {
        $f = 'clients/assets/js/planner-ready.js';
        $url = AssetBundle::js($f, 7);
        $m = (json_decode((string) @file_get_contents(AssetBundle::manifestPath()), true) ?: [])['min:' . $f] ?? null;
        if (!$m) {
            $this->assertStringEndsWith($f . '?v=7', $url, 'chưa rút gọn: dùng tệp gốc');

            return;
        }
        $this->assertMatchesRegularExpression('#/build/min/[0-9a-f]{10}/planner-ready\.js$#', $url);
        $this->assertSame(md5(str_replace("\r\n", "\n", (string) file_get_contents(public_path($f)))), $m['src'], 'bản rút gọn trong gói khớp nguồn hiện tại (chạy lại php artisan assets:bundle)');
        config(['assets.enabled' => false]);
        AssetBundle::reset();
        $this->assertStringEndsWith($f . '?v=7', AssetBundle::js($f, 7));
    }
}
