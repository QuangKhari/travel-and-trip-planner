<?php

namespace Tests\Feature;

use App\Console\Commands\PlannerDoctor;
use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nghiệm thu cuối (feature freeze) — bổ sung ma trận test của Planner:
 *  - Resolver: A hết + phát sinh B (mềm) -> vẫn "giải quyết được", B được liệt kê; A hết nhưng tạo lỗi CỨNG mới -> KHÔNG giải quyết được.
 *  - SLOT: không bắt đầu trước đầu buổi và không bị sắp tuyến đổi chỗ.
 *  - Quyền: người chỉ xem đọc được sức khỏe nhưng không gọi được đề xuất xử lý.
 *  - API: PUT /api/v1/trips/{id} có rev, bản cũ -> 409 (giống trang web).
 *  - CSDL: planner:doctor xác nhận đủ bảng / cột.
 */
class PlannerAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(int $days): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Kiểm thử', 'days' => $days, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function note(string $label, int $day, array $o = [], float $lat = 21.03, float $lng = 105.85): array
    {
        return $o + ['type' => 'note', 'label' => $label, 'day' => $day, 'lat' => $lat, 'lng' => $lng, 'source' => 'link'];
    }

    private function save(int $id, int $days, array $items): void
    {
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Kiểm thử', 'days' => $days, 'groupSize' => 2, 'items' => $items])->assertOk();
    }

    private function resolveOptions(int $id, string $code, int $day, ?int $index = null): array
    {
        return $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=$code&day=$day" . ($index !== null ? "&index=$index" : ''))->assertOk()->json('options');
    }

    public function test_resolving_a_problem_that_creates_a_soft_issue_still_resolves_and_lists_it(): void
    {
        $id = $this->trip(2);
        // ngày 1 quá tải (109%); ngày 2 đang 60%, nhận mục 150 phút thì lên ~87% (khá kín = cảnh báo mềm)
        $this->save($id, 2, [
            $this->note('Khách sạn', 1), $this->note('Dài', 1, ['durationMin' => 150]),
            $this->note('A', 1, ['durationMin' => 120]), $this->note('B', 1, ['durationMin' => 120]), $this->note('C', 1, ['durationMin' => 120]),
            $this->note('D', 2, ['durationMin' => 135]), $this->note('E', 2, ['durationMin' => 135]),
        ]);
        $move = collect($this->resolveOptions($id, 'capacity.overload', 1))->firstWhere('id', 'move-one');
        $this->assertNotNull($move);
        $this->assertTrue($move['resolves']);
        $new = collect($move['newIssues']);
        $this->assertNotNull($new->first(fn($x) => str_contains($x['text'], 'Ngày 2') && $x['severity'] === 'soft'), 'phải báo ngày 2 trở nên kín');
        $this->assertNull($new->firstWhere('severity', 'hard'));
    }

    public function test_resolving_a_problem_that_creates_a_hard_error_is_not_resolved(): void
    {
        $id = $this->trip(1);
        // A 09:00-10:30, B cố định 10:00 (trùng), C cố định 11:10: dời B sang 10:30 thì B đè lên C
        $this->save($id, 1, [
            $this->note('A', 1, ['startMin' => 540, 'durationMin' => 90]),
            $this->note('B', 1, ['startMin' => 600, 'durationMin' => 60]),
            $this->note('C', 1, ['startMin' => 670, 'durationMin' => 60]),
        ]);
        $opts = $this->resolveOptions($id, 'time.overlap', 1, 1);
        $this->assertNotEmpty($opts);
        foreach ($opts as $o) {
            $this->assertFalse($o['resolves'], "{$o['id']} không được báo giải quyết khi sinh trùng giờ mới");
            $this->assertNotNull(collect($o['newIssues'])->firstWhere('severity', 'hard'));
        }
    }

    public function test_slot_is_respected_by_schedule_and_never_reordered(): void
    {
        $id = $this->trip(1);
        // Hà Nội <-> Ninh Bình xen kẽ: đi lại quá 3 giờ -> route.long_day; mục B có buổi chiều
        $hn = [21.03, 105.85];
        $nb = [20.25, 105.97];
        $this->save($id, 1, [
            $this->note('Khách sạn', 1, [], ...$hn), $this->note('A', 1, [], ...$nb), $this->note('B', 1, ['slot' => 'afternoon'], ...$hn),
            $this->note('C', 1, [], ...$nb), $this->note('D', 1, [], ...$hn),
        ]);
        $h = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $b = collect($h['days'][0]['rows'])->firstWhere('name', 'B');
        $this->assertGreaterThanOrEqual('13:30', $b['start']);   // buổi chiều bắt đầu 13:30
        $this->assertNotNull(collect($h['checks'])->firstWhere('code', 'route.long_day'));

        $opt = collect($this->resolveOptions($id, 'route.long_day', 1))->firstWhere('id', 'optimize');
        $this->assertNotNull($opt);
        $order = $opt['changes'][0]['order'];
        $this->assertSame(0, $order[0]);   // đầu ngày giữ nguyên
        $this->assertSame(2, $order[2]);   // mục có buổi giữ nguyên chỗ
        $this->assertNotSame([0, 1, 2, 3, 4], $order);
    }

    public function test_viewer_can_read_health_but_cannot_request_resolutions(): void
    {
        $id = $this->trip(1);
        $this->save($id, 1, [$this->note('A', 1, ['startMin' => 540, 'durationMin' => 90]), $this->note('B', 1, ['startMin' => 600])]);
        $viewer = DB::table('tbl_users')->insertGetId(['username' => 'v', 'email' => 'v@example.com', 'password' => 'x', 'fullName' => 'V', 'isActive' => 'y']);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $viewer, 'role' => 'view', 'invitedBy' => $this->uid]);
        $as = ['userId' => $viewer, 'username' => 'v'];
        $this->withSession($as)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk();
        $this->withSession($as)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=time.overlap&day=1&index=1")->assertNotFound();
        $html = $this->withSession($as)->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('"resolveUrl":null', $html);
    }

    public function test_api_update_uses_revision_and_rejects_stale_writes(): void
    {
        $id = $this->trip(1);
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'u1', 'password' => '12345678'])->assertOk()->json('access_token');
        $h = ['Authorization' => "Bearer $token"];
        $rev = $this->getJson("/api/v1/trips/$id", $h)->assertOk()->json('rev');
        $body = ['title' => 'API', 'days' => 1, 'groupSize' => 2, 'items' => [['type' => 'note', 'label' => 'A', 'day' => 1]]];
        $this->app['auth']->forgetGuards();
        $rev2 = $this->putJson("/api/v1/trips/$id", $body + ['rev' => $rev], $h)->assertOk()->json('rev');
        $this->assertGreaterThan($rev, $rev2);
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/trips/$id", ['title' => 'Ghi đè'] + $body + ['rev' => $rev], $h)->assertStatus(409)->assertJson(['conflict' => true, 'rev' => $rev2]);
        $this->assertSame('API', DB::table('tbl_trips')->where('tripId', $id)->value('title'));
    }

    public function test_doctor_confirms_schema(): void
    {
        $this->assertSame([], PlannerDoctor::missing());
        $this->artisan('planner:doctor')->assertSuccessful();
    }
}
