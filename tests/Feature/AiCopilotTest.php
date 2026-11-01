<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Planner\OpeningHours;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La bàn F3 Copilot (AI-3, AI-4). FakeLlm chạy theo KỊCH BẢN từng bước (search -> propose...), không tốn tiền API.
 * Bất biến:
 *   - Copilot không bao giờ ghi vào chuyến (rev không đổi); trình duyệt áp dụng changes[] rồi tự lưu như thường.
 *   - Chỉ thêm / thay bằng nơi THẬT do search_places trả về; khóa bịa bị từ chối và LLM được báo lỗi để sửa.
 *   - Đề xuất tạo vấn đề CỨNG mới (đóng cửa, trùng giờ...) không bao giờ tới tay người dùng.
 *   - "Kiểm tra lại" sau khi lịch đổi: ánh xạ theo danh tính mục, không gọi LLM.
 */
class AiCopilotTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private array $s;
    private int $hn;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ai.enabled' => true, 'ai.features.copilot' => true, 'core_destinations.slugs' => []]);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function place(string $name, float $lat, float $lng, string $kind = 'museum'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    /** Ngày 1 = Thứ Hai 16/11/2026, ngày 2 = Thứ Ba. */
    private function trip(array $items): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2026-11-16', 'budget' => null, 'destinationId' => $this->hn]);
        $this->save($id, $items)->assertOk();

        return $id;
    }

    private function save(int $id, array $items)
    {
        $rev = (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev');

        return $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2026-11-16', 'items' => $items, 'rev' => $rev]);
    }

    /** FakeLlm trả lần lượt từng bước của kịch bản (bước cuối lặp lại nếu được gọi thêm). */
    private function script(array $steps): FakeLlm
    {
        $i = 0;
        $f = new FakeLlm(['copilot' => function () use ($steps, &$i) {
            return $steps[min($i++, count($steps) - 1)];
        }]);
        $this->app->instance(StructuredLlm::class, $f);

        return $f;
    }

    private function ask(int $id, string $text, array $extra = [])
    {
        return $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => $text] + $extra);
    }

    public function test_search_then_add_returns_verified_proposal_and_never_writes_trip(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $b = $this->place('Hồ Tây', 21.06, 105.82, 'viewpoint');
        $cafe = $this->place('Cà phê Giảng', 21.029, 105.837, 'cafe');
        $this->place('Cà phê xa', 21.20, 105.70, 'cafe');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 2]]);
        $rev = DB::table('tbl_trips')->where('tripId', $id)->value('rev');
        $fake = $this->script([
            ['action' => 'search', 'say' => '', 'search' => ['group' => 'cafe', 'nearItem' => 'i1', 'day' => 1, 'text' => null]],
            ['action' => 'propose', 'say' => 'Thêm {{c:place:' . $cafe . '}} ngay sau {{i1}}.', 'changes' => [['op' => 'add', 'with' => 'c:place:' . $cafe, 'toDay' => 1, 'after' => 'i1']]],
        ]);

        $r = $this->ask($id, 'Thêm một quán cà phê gần Văn Miếu')->assertOk()->json();

        $this->assertSame('proposal', $r['kind']);
        $this->assertSame(2, $fake->calls);
        $this->assertStringContainsString('c:place:' . $cafe . ' | Cà phê Giảng', $fake->lastUser, 'kết quả công cụ được đưa vào bước sau');
        $this->assertStringNotContainsString('Cà phê xa', $fake->lastUser, 'chỉ tìm quanh mục mốc');
        $this->assertSame(['op' => 'add', 'toDay' => 1, 'after' => 0], array_intersect_key($r['changes'][0], ['op' => 1, 'toDay' => 1, 'after' => 1]));
        $this->assertSame($cafe, $r['changes'][0]['with']['refId']);
        $this->assertSame('Cà phê Giảng', $r['say'][1]['name']);
        $this->assertSame((int) $rev, $r['baseRev']);
        $this->assertArrayHasKey('receipt', $r);
        $this->assertSame([1], array_column($r['receipt']['effects'], 'day'));
        // không ghi gì vào chuyến
        $this->assertSame($rev, DB::table('tbl_trips')->where('tripId', $id)->value('rev'));
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $id)->count());
        $run = DB::table('tbl_ai_runs')->where('feature', 'copilot')->first();
        $this->assertSame('proposed', $run->outcome);
    }

    public function test_invented_candidate_is_rejected_and_llm_repairs(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $b = $this->place('Hoàng thành', 21.035, 105.84, 'historic');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $fake = $this->script([
            ['action' => 'propose', 'say' => 'Thêm nơi mới.', 'changes' => [['op' => 'add', 'with' => 'c:place:99999', 'toDay' => 2]]],
            ['action' => 'propose', 'say' => 'Chuyển {{i2}} sang ngày 2 cho ngày 1 nhẹ hơn.', 'changes' => [['op' => 'move', 'item' => 'i2', 'toDay' => 2]]],
        ]);

        $r = $this->ask($id, 'Ngày 1 nhẹ hơn')->assertOk()->json();

        $this->assertSame('proposal', $r['kind']);
        $this->assertStringContainsString('BỊ TỪ CHỐI', $fake->lastUser);
        $this->assertStringContainsString('search_places', $fake->lastUser);
        $this->assertSame(['op' => 'move', 'day' => 1, 'index' => 1, 'toDay' => 2], $r['changes'][0]);
        $this->assertSame('Hoàng thành → Ngày 2', $r['steps_human'][0]['text']);
    }

    public function test_proposal_creating_hard_issue_never_reaches_user(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $museum = $this->place('Bảo tàng Dân tộc học', 21.04, 105.80, 'museum');
        app(OpeningHours::class)->store('place', $museum, 'Tu-Su 08:30-17:30; Mo off');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $museum, 'day' => 2]]);
        $fake = $this->script([
            ['action' => 'propose', 'say' => 'Chuyển bảo tàng sang ngày 1.', 'changes' => [['op' => 'move', 'item' => 'i2', 'toDay' => 1]]],
        ]);

        $r = $this->ask($id, 'Đưa bảo tàng sang ngày 1')->assertOk()->json();

        $this->assertSame('reply', $r['kind'], 'ngày 1 là Thứ Hai, bảo tàng đóng cửa: đề xuất bị chặn');
        $this->assertArrayNotHasKey('changes', $r);
        $this->assertSame(3, $fake->calls, '1 lần đề xuất + 2 lần sửa');
        $this->assertStringContainsString('vấn đề mới', $fake->lastUser);
        $this->assertSame('refused', DB::table('tbl_ai_runs')->where('feature', 'copilot')->value('outcome'));
    }

    public function test_reply_drops_ungrounded_numbers(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1]]);
        $this->script([['action' => 'reply', 'say' => '{{i1}} rất đáng đi. Vé chỉ 35.000đ.']]);

        $r = $this->ask($id, 'Văn Miếu thế nào?')->assertOk()->json();

        $this->assertSame('reply', $r['kind']);
        $this->assertSame('Văn Miếu', $r['say'][0]['name']);
        $this->assertStringNotContainsString('35.000', json_encode($r['say'], JSON_UNESCAPED_UNICODE));
    }

    public function test_recheck_remaps_by_identity_without_llm(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $b = $this->place('Hoàng thành', 21.035, 105.84, 'historic');
        $c = $this->place('Nhà hát Lớn', 21.024, 105.857, 'attraction');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $fake = $this->script([['action' => 'propose', 'say' => 'Chuyển {{i2}} sang ngày 2.', 'changes' => [['op' => 'move', 'item' => 'i2', 'toDay' => 2]]]]);
        $p = $this->ask($id, 'Ngày 1 nhẹ hơn')->assertOk()->json();
        $this->assertSame(1, $p['changes'][0]['index']);

        // người dùng thêm một mục lên ĐẦU ngày 1 trước khi áp dụng: vị trí cũ không còn đúng
        $this->save($id, [['type' => 'place', 'refId' => $c, 'day' => 1], ['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]])->assertOk();
        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/kiem-tra-lai", ['proposal' => $p['id']])->assertOk()->json();

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['rechecked']);
        $this->assertSame(['op' => 'move', 'day' => 1, 'index' => 2, 'toDay' => 2], $r['changes'][0], 'vẫn là Hoàng thành, ở vị trí mới');
        $this->assertNotSame($p['baseRev'], $r['baseRev']);
        $this->assertSame(1, $fake->calls, 'kiểm tra lại không gọi LLM');

        // mục đã bị xóa: báo rõ, không đoán
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1]])->assertOk();
        $gone = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/kiem-tra-lai", ['proposal' => $r['id']])->assertOk()->json();
        $this->assertFalse($gone['ok']);
        $this->assertStringContainsString('Hoàng thành', $gone['say'][0]['t']);
    }

    /** Xem trước = áp dụng: áp changes[] của La bàn bằng CHÍNH planner-resolve.js + planner-save.js, lưu thật, so với phiếu kiểm chứng. */
    public function test_preview_equals_apply_through_browser_js(): void
    {
        $node = trim(strtok((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'), "\r\n") ?: '');
        if ($node === '') {
            $this->markTestSkipped('Cần Node để chạy mã trình duyệt.');
        }
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $b = $this->place('Hoàng thành', 21.035, 105.84, 'historic');
        $c = $this->place('Hồ Tây', 21.06, 105.82, 'viewpoint');
        $cafe = $this->place('Cà phê Giảng', 21.029, 105.837, 'cafe');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1], ['type' => 'place', 'refId' => $c, 'day' => 2]]);
        $this->script([
            ['action' => 'search', 'say' => '', 'search' => ['group' => 'cafe', 'nearItem' => 'i1', 'day' => 1]],
            ['action' => 'propose', 'say' => 'Gọn lại.', 'changes' => [
                ['op' => 'move', 'item' => 'i2', 'toDay' => 2],
                ['op' => 'add', 'with' => 'c:place:' . $cafe, 'toDay' => 1, 'after' => 'i1'],
            ]],
        ]);
        $p = $this->ask($id, 'Ngày 1 bớt một chỗ và thêm quán cà phê')->assertOk()->json();
        $this->assertSame('proposal', $p['kind']);

        $planner = app(TripPlanner::class);
        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();
        $pl = $planner->payload($trip);
        $items = array_map([TripPlanner::class, 'itemForClient'], array_merge(...array_map(fn($d) => $d['items'], $pl['days']), ...[$pl['unassigned']]));
        $in = ['items' => $items, 'rain' => (object) [], 'changes' => $p['changes'], 'rev' => (int) $trip->rev,
            'meta' => ['title' => $trip->title, 'start' => $trip->startDate, 'days' => (int) $trip->days, 'group' => (int) $trip->groupSize, 'budget' => $trip->budget, 'dest' => $trip->destinationId]];
        $proc = new \Symfony\Component\Process\Process([$node, base_path('tests/js/apply-resolution.cjs')]);
        $proc->setInput(json_encode($in, JSON_UNESCAPED_UNICODE));
        $proc->mustRun();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", json_decode($proc->getOutput(), true))->assertOk();

        $names = DB::table('tbl_trip_items as i')->join('tbl_external_places as p', 'p.placeId', '=', 'i.refId')->where('i.tripId', $id)
            ->orderBy('i.dayNo')->orderBy('i.orderNo')->get(['i.dayNo', 'p.name'])->map(fn($r) => $r->dayNo . ':' . $r->name)->all();
        $this->assertSame(['1:Văn Miếu', '1:Cà phê Giảng', '2:Hồ Tây', '2:Hoàng thành'], $names);
        $h = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $this->assertSame($p['receipt']['after']['score'], $h['score'], 'điểm sau khi áp dụng khác phiếu kiểm chứng');
        foreach ($p['receipt']['effects'] as $e) {
            $real = collect($h['days'])->firstWhere('day', $e['day']);
            $this->assertSame($e['afterPct'], $real['loadPct'], "độ dày ngày {$e['day']} khác phiếu kiểm chứng");
        }
    }

    public function test_feedback_marks_run_applied(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $b = $this->place('Hoàng thành', 21.035, 105.84, 'historic');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $this->script([['action' => 'propose', 'say' => 'Chuyển {{i2}}.', 'changes' => [['op' => 'move', 'item' => 'i2', 'toDay' => 2]]]]);
        $p = $this->ask($id, 'Ngày 1 nhẹ hơn')->json();

        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/ket-qua", ['proposal' => $p['id'], 'applied' => true])->assertOk();
        $this->assertSame('applied', DB::table('tbl_ai_runs')->where('feature', 'copilot')->value('outcome'));
    }

    public function test_fallback_when_ai_off_and_permissions(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355, 'historic');
        $id = $this->trip([['type' => 'place', 'refId' => $a, 'day' => 1]]);
        $fake = $this->script([['action' => 'reply', 'say' => 'x']]);

        config(['ai.enabled' => false]);
        $r = $this->ask($id, 'Ngày 1 nhẹ hơn')->assertOk()->json();
        $this->assertFalse($r['ok']);
        $this->assertSame('fallback', $r['kind']);
        $this->assertSame(0, $fake->calls);

        config(['ai.enabled' => true]);
        $viewer = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $viewer, 'role' => 'view', 'createdAt' => now()]);
        $this->withSession(['userId' => $viewer, 'username' => 'u2'])->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'x'])->assertNotFound();
        $this->withSession(['userId' => $viewer, 'username' => 'u2'])->get("/len-ke-hoach/$id")->assertOk()->assertDontSee('planner-ai-copilot.js', false);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('planner-ai-copilot.js', false)->assertSee('aiCoOpen', false);
        $this->assertSame(0, $fake->calls);
    }
}
