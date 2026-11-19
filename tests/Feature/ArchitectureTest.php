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
 * Tầng 2 "Kiến trúc": ranh giới phân hệ (modular monolith) được kiểm bằng test (bảng, bảng cấm, lớp nội bộ), mỗi phân hệ một giao diện
 * app/Modules, sự kiện nghiệp vụ (PlaceHoursChanged, TripUpdated), API có phiên bản kèm đặc tả OpenAPI không lệch mã, JS rút gọn bằng esbuild có đường lùi.
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

        // nợ kỹ thuật đã trả hết: không còn dòng nào được miễn
        $this->assertSame([], $base);
        // Planner và AI không còn đọc thẳng bảng đơn / thanh toán, bảng ngày khởi hành, hay bảng tour (hỏi qua TourCatalog)
        $tourTables = ['tbl_tours', 'tbl_tour_stops', 'tbl_timeline', 'tbl_images'];
        $planner = array_filter(ModuleMap::scan(), fn($h) => in_array($h['module'], ['Planner', 'AI'], true)
            && (in_array($h['owner'], ['Commerce', 'TourOps'], true) || in_array($h['table'], $tourTables, true)));
        $this->assertSame([], array_values($planner));
        // không phân hệ nào GHI bảng của phân hệ khác: mọi thay đổi chéo đi qua giao diện app/Modules hoặc sự kiện
        $this->assertSame([], array_values(array_filter(ModuleMap::scan(), fn($h) => $h['kind'] === 'write')));
        // tour là nội dung (Content), ngày khởi hành / cứu chuyến là Tour Ops
        $this->assertSame('Content', ModuleMap::owners()['tbl_tours']);
        $this->assertSame('TourOps', ModuleMap::owners()['tbl_tour_availability']);
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

    public function test_scanner_enforces_denied_tables_and_internal_class_calls(): void
    {
        $f = 'app/Services/Planner/__ArchProbe2.php';
        file_put_contents(base_path($f), "<?php\nnamespace App\\Services\\Planner;\nuse App\\Services\\RefundService;\nuse App\\Modules\\Commerce\\CommerceReader;\n"
            . "class __ArchProbe2 { function a() { DB::table('tbl_tours')->count(); \\App\\Services\\DepartureService::enabled(); app(CommerceReader::class); app(RefundService::class); } }\n");
        try {
            $mine = array_values(array_filter(ModuleMap::violations(), fn($h) => $h['file'] === $f));
            $this->assertEqualsCanonicalizing([
                [$f . '|tbl_tours|read', 'deny'],
                [$f . '|class|App\\Services\\RefundService', 'class'],
                [$f . '|class|App\\Services\\DepartureService', 'class'],
            ], array_map(fn($h) => [$h['key'], $h['rule']], $mine));   // CommerceReader là giao diện: được gọi
            $this->artisan('arch:modules')->expectsOutputToContain('gọi lớp nội bộ App\\Services\\RefundService của Commerce')->assertFailed();
        } finally {
            @unlink(base_path($f));
        }
    }

    public function test_every_module_exposes_an_interface_and_other_modules_use_it(): void
    {
        foreach (['Content' => 'TourCatalog', 'TourOps' => 'Departures', 'Commerce' => 'CommerceReader', 'Planner' => 'PlannerApi'] as $m => $cls) {
            $this->assertTrue(class_exists("App\\Modules\\$m\\$cls"), $cls);
            $this->assertSame($m, ModuleMap::classOwners()["App\\Modules\\$m\\$cls"]);
        }
        $used = fn($from, $cls) => (bool) array_filter(ModuleMap::classDeps(), fn($h) => $h['module'] === $from && $h['class'] === $cls);
        $this->assertTrue($used('Planner', 'App\\Modules\\Content\\TourCatalog'));
        $this->assertTrue($used('AI', 'App\\Modules\\Content\\TourCatalog'));
        $this->assertTrue($used('Planner', 'App\\Modules\\TourOps\\Departures'));
        $this->assertTrue($used('Commerce', 'App\\Modules\\TourOps\\Departures'));
        $this->assertTrue($used('TourOps', 'App\\Modules\\Commerce\\BookingChanges'));
        $this->assertTrue($used('Commerce', 'App\\Modules\\Planner\\PlannerApi'));
        $this->assertTrue($used('AI', 'App\\Modules\\Planner\\PlannerApi'));
        // Planner không còn gọi thẳng vào La bàn khi lưu chuyến (AI tự phản ứng với TripUpdated)
        foreach (['TripRevisions.php', 'TripPlanner.php'] as $f) {
            $this->assertStringNotContainsString('CopilotService::', (string) file_get_contents(app_path('Services/Planner/' . $f)));
        }
    }

    public function test_tour_catalog_answers_planner_questions_without_planner_touching_tour_tables(): void
    {
        $dl = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'b', 'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $mk = fn($title, $start, $qty, $avail = 1) => DB::table('tbl_tours')->insertGetId(['title' => $title, 'time' => '3 ngày 2 đêm', 'description' => 'x', 'quantity' => $qty,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destinationId' => $dl, 'destination' => 'Đà Lạt', 'domain' => 'n', 'availability' => $avail, 'startDate' => $start, 'endDate' => date('Y-m-d', strtotime($start . ' +2 days'))]);
        $near = $mk('Gần', date('Y-m-d', strtotime('+10 days')), 5);
        $far = $mk('Xa', date('Y-m-d', strtotime('+40 days')), 5);
        $mk('Hết chỗ', date('Y-m-d', strtotime('+11 days')), 0);
        $mk('Ngừng bán', date('Y-m-d', strtotime('+12 days')), 5, 0);
        $c = app(\App\Modules\Content\TourCatalog::class);

        $this->assertSame([$near, $far], $c->bookable(['destId' => $dl])->pluck('tourId')->map(fn($x) => (int) $x)->all());
        $this->assertSame([$far, $near], $c->bookable(['destIds' => [$dl], 'near' => date('Y-m-d', strtotime('+45 days'))])->pluck('tourId')->map(fn($x) => (int) $x)->all());
        $this->assertSame('Đà Lạt', $c->bookable(['destId' => $dl, 'limit' => 1])->first()->destName);
        $this->assertCount(3, $c->bookable(['destId' => $dl, 'seats' => false, 'endFrom' => date('Y-m-d')]));
        $this->assertNull($c->find($mk('Hết chỗ 2', date('Y-m-d', strtotime('+5 days')), 0), true));
        $this->assertSame(3, \App\Modules\Content\TourCatalog::days('3 ngày 2 đêm'));
        $this->assertSame(\App\Modules\Content\TourCatalog::days(null, '2026-01-01', '2026-01-04'), \App\Services\Planner\TourFit::tourDays(null, '2026-01-01', '2026-01-04'));
        $month = $c->countByDestination(date('Y-m-d'), date('Y-m-d', strtotime('+60 days')));
        $this->assertSame(['count' => 2, 'minPrice' => 1000000.0], $month[$dl]);
    }

    public function test_trip_updated_event_lets_ai_measure_applied_proposals_without_planner_calling_ai(): void
    {
        Cache::flush();
        \Illuminate\Support\Facades\Queue::fake();   // outbox chưa được xử lý: lưu chuyến vẫn thành công, sự kiện nằm chờ
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'tu', 'email' => 'tu@example.com', 'password' => 'x', 'fullName' => 'T', 'isActive' => 'y']);
        $hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'HN', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $mk = fn($n, $la) => DB::table('tbl_external_places')->insertGetId(['destinationId' => $hn, 'source' => 'manual', 'externalId' => $n, 'name' => $n, 'category' => 'sight', 'kind' => 'museum',
            'latitude' => $la, 'longitude' => 105.85, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        [$a, $b] = [$mk('A', 21.03), $mk('B', 21.04)];
        $id = app(\App\Services\Planner\TripPlanner::class)->create($uid, ['title' => 'HN', 'days' => 2, 'groupSize' => 1, 'startDate' => now()->addDays(5)->toDateString(), 'destinationId' => $hn]);
        $save = fn(array $items) => $this->withSession(['userId' => $uid, 'username' => 'tu'])->postJson("/len-ke-hoach/$id/luu", ['title' => 'HN', 'days' => 2, 'groupSize' => 1,
            'startDate' => now()->addDays(5)->toDateString(), 'items' => $items])->assertOk();
        $save([['type' => 'place', 'refId' => $a, 'day' => 1]]);
        $rev = (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev');

        // La bàn: người dùng bấm Áp dụng một đề xuất ở rev hiện tại (chưa lưu)
        $run = DB::table('tbl_ai_runs')->insertGetId(['feature' => 'copilot', 'engine' => 'llm', 'outcome' => 'clicked', 'tripId' => $id, 'promptVersion' => 'x', 'createdAt' => now()], 'runId');
        Cache::put('ai:cp:pending:' . $id, [$run => $rev], 3600);
        $save([['type' => 'place', 'refId' => $b, 'day' => 1]]);

        $ev = DB::table('tbl_domain_events')->where('name', 'TripUpdated')->orderByDesc('eventId')->first();
        $p = json_decode($ev->payload, true);
        $this->assertSame(['trip', (string) $id], [$ev->aggregate, $ev->aggregateId]);
        $this->assertSame(
            ['tripId' => $id, 'rev' => $rev + 1, 'change' => 'save', 'added' => ["place:$b"], 'removed' => ["place:$a"]],
            array_intersect_key($p, array_flip(['tripId', 'rev', 'change', 'added', 'removed'])),
        );
        $this->assertNull($ev->processedAt);
        $this->assertSame('clicked', DB::table('tbl_ai_runs')->where('runId', $run)->value('outcome'));   // Planner không tự đụng vào bảng của AI

        // phân hệ AI nhận sự kiện -> đề xuất đã thật sự vào CSDL
        foreach (DB::table('tbl_domain_events')->where('name', 'TripUpdated')->whereNull('processedAt')->orderBy('eventId')->pluck('eventId') as $eid) {
            (new \App\Jobs\ProcessDomainEvent((int) $eid))->handle();   // như worker / events:relay chạy job
        }
        $this->assertSame('applied', DB::table('tbl_ai_runs')->where('runId', $run)->value('outcome'));
        $this->assertSame(0, DB::table('tbl_domain_events')->where('name', 'TripUpdated')->whereNull('processedAt')->count());

        // hoàn tác về lịch trước đề xuất -> lần lưu đó (qua sự kiện) chuyển đề xuất sang undone
        $save([['type' => 'place', 'refId' => $a, 'day' => 1]]);
        (new \App\Jobs\ProcessDomainEvent((int) DB::table('tbl_domain_events')->where('name', 'TripUpdated')->max('eventId')))->handle();
        $this->assertSame('undone', DB::table('tbl_ai_runs')->where('runId', $run)->value('outcome'));
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
        app(\App\Services\Content\PlaceFacts::class)->verify($museum, $osm, null, null, 'website', null, 1);

        $this->assertSame(1, DB::table('tbl_domain_events')->where('name', 'PlaceHoursChanged')->count());
        $ev = DB::table('tbl_trip_events')->where(['tripId' => $id, 'kind' => 'content'])->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('Bảo tàng A', $ev->title);
        $this->assertStringContainsString('phương án', $ev->text);
        $feed = app(\App\Services\Planner\TripWatch::class)->feed($id, $uid);
        $this->assertSame(['type' => 'resolve', 'code' => 'hours.closed', 'refType' => 'place', 'refId' => $museum], $feed['events'][0]['action']);

        // nhập giờ hàng loạt (nguồn khác admin / báo sai) không phát sự kiện
        app(\App\Services\Content\OpeningHours::class)->store('place', $museum, '24/7', 'osm');
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
