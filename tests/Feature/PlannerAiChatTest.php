<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trợ lý trò chuyện tạo chuyến và chip "đúng ý không": đọc câu nói thành checklist (không ghi gì), xem trước rồi mới tạo,
 * giới hạn đầu vào, AI tắt vẫn chạy bằng luật, đánh giá chỉ gắn vào chuyến người đó được xem.
 */
class PlannerAiChatTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => false]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'b',
            'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
    }

    private function me(): static
    {
        return $this->withSession(['userId' => $this->uid, 'username' => 'a']);
    }

    public function test_chat_page_and_read_turn_sentences_into_slots_without_writing(): void
    {
        $this->me()->get('/len-ke-hoach/tro-ly')->assertOk();
        $trips = DB::table('tbl_trips')->count();

        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => ['Đi Đà Lạt 3 ngày tháng 12, 2 người, khoảng 5 triệu'], 'from' => '<b>Hà Nội</b>'])->assertOk()->json();
        $this->assertArrayHasKey('slots', $j);
        $this->assertArrayHasKey('next', $j);
        $this->assertContains('Ngân sách: 5.000.000đ', $j['extras']);
        $this->assertSame($trips, DB::table('tbl_trips')->count(), 'đọc câu không được tạo chuyến');

        // rỗng vẫn trả lời được, quá dài bị chặn
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => []])->assertOk()->assertJsonStructure(['slots', 'next']);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => [str_repeat('a', 501)]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => array_fill(0, 13, 'x')])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['suggest' => 1])->assertOk()->assertJsonStructure(['pick']);
    }

    public function test_create_shows_review_first_and_validates_adjustments(): void
    {
        $said = ['said' => ['Đà Lạt 2 ngày, 2 người lớn']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        if (!empty($j['review'])) {
            $this->assertSame(2, $j['details']['days']);
            $this->assertSame(2, $j['details']['adults']);
        } else {
            $this->assertFalse($j['ok'], 'không có điểm đến đủ dữ liệu thì báo rõ, không tạo chuyến rỗng');
        }
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->where('kind', 'trip')->count());

        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['adults' => 40, 'children' => 20]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['days' => 99]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['hack' => 1]])->assertStatus(422);
    }

    public function test_preview_returns_real_draft_and_confirm_saves_that_draft(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequests::class);
        $plan = ['days' => [['day' => 1, 'activityMin' => 150, 'travelMin' => 12, 'items' => [
            ['key' => 'place:1', 'type' => 'place', 'refId' => 1, 'name' => 'Hồ Xuân Hương', 'startMin' => 480, 'durationMin' => 60, 'travelMin' => 0, 'travelKm' => 0, 'slot' => 'morning',
                'reasons' => ['Hợp sở thích thiên nhiên', 'Mở cửa cả ngày', 'Gần trung tâm', 'Lý do thứ tư bị cắt'], 'lat' => 11.94, 'lng' => 108.44, 'coordsApprox' => false, 'priceMin' => 0],
            ['key' => 'place:2', 'type' => 'place', 'refId' => 2, 'name' => 'Vườn hoa', 'startMin' => 552, 'durationMin' => 90, 'travelMin' => 12, 'travelKm' => 3.1, 'slot' => 'morning',
                'reasons' => [], 'lat' => 11.95, 'lng' => 108.45, 'coordsApprox' => false]]]],
            'unplaced' => [['key' => 'place:3']], 'budget' => ['knownMin' => 100000, 'message' => 'x']];
        $result = ['ok' => true, 'destination' => ['id' => $this->dest, 'name' => 'Đà Lạt'], 'request' => ['startDate' => '2027-03-10'], 'plan' => $plan];
        $calls = 0;
        $saved = null;
        $mock = \Mockery::mock(\App\Services\Planner\PlannerService::class);
        $mock->shouldReceive('generate')->andReturnUsing(function () use (&$calls, $result) {
            $calls++;
            return $result;
        });
        $mock->shouldReceive('save')->andReturnUsing(function ($uid, $r) use (&$saved) {
            $saved = $r;
            return DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'title' => 'Đà Lạt', 'days' => 1, 'groupSize' => 2, 'kind' => 'trip', 'createdAt' => now(), 'updatedAt' => now()]);
        });
        $this->app->instance(\App\Services\Planner\PlannerService::class, $mock);

        $said = ['said' => ['Đà Lạt 1 ngày, 2 người lớn, thích thiên nhiên']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        $this->assertTrue($j['review']);
        $this->assertSame(2, $j['draft']['count']);
        $this->assertSame(12, $j['draft']['travelMin']);
        $this->assertSame('Vườn hoa', $j['draft']['days'][0]['items'][1]['name']);
        $this->assertCount(3, $j['draft']['days'][0]['items'][0]['reasons'], 'tối đa 3 lý do');
        $this->assertSame(1, $j['draft']['unplaced']);
        $this->assertSame(32, strlen($j['draftToken']));
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->count(), 'bản nháp chưa ghi gì');

        // xác nhận kèm mã nháp và cùng yêu cầu: lưu đúng bản đã xem, không xếp lại
        $first = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $j['draftToken']])->assertOk()->assertJsonPath('ok', true)->json();
        $this->assertSame(1, $calls);
        $this->assertSame($plan, $saved['plan']);
        // bấm Lưu lần nữa với cùng mã nháp (bấm đúp / mạng chậm): trả về ĐÚNG chuyến đã tạo, không tạo chuyến thứ hai
        $trips = DB::table('tbl_trips')->where('userId', $this->uid)->count();
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $j['draftToken']])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('url', $first['url']);
        $this->assertSame($trips, DB::table('tbl_trips')->where('userId', $this->uid)->count());
        // mã hết hạn / không có mã / yêu cầu đã đổi: KHÔNG tự xếp lịch mới rồi lưu, trả 409 để trình duyệt xếp lại và cho xem trước
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => str_repeat('a', 32)])
            ->assertStatus(409)->assertJsonPath('expired', true)->assertJsonPath('reason', 'expired');
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true])->assertStatus(409);
        $this->assertSame(1, $calls, 'không xếp lại lặng lẽ');
        $this->assertSame($trips, DB::table('tbl_trips')->where('userId', $this->uid)->count(), 'không lưu chuyến khác bản đã xem');
        // còn mã nhưng yêu cầu đã đổi (số ngày) -> 409 "changed"
        $j2 = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $j2['draftToken'], 'adjustments' => ['days' => 2]])
            ->assertStatus(409)->assertJsonPath('reason', 'changed');
        $this->assertSame(2, $calls);
        // khác kiểu nhưng cùng giá trị (3 và "3", nhịp trống và "normal") vẫn là cùng yêu cầu
        $this->assertSame(
            \App\Http\Controllers\clients\PlannerAiController::hashOf(['days' => 3, 'pace' => null, 'interests' => ['food', 'nature']]),
            \App\Http\Controllers\clients\PlannerAiController::hashOf(['interests' => ['nature', 'food'], 'pace' => 'normal', 'days' => '3']),
        );
        // nhịp đi / sở thích sửa thẳng được, giá trị lạ bị chặn
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['pace' => 'relaxed', 'interests' => ['food']]])->assertOk();
        $calls = 0;
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['pace' => 'sleep']])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['interests' => ['hack']]])->assertStatus(422);
    }

    /** Bản nháp: bỏ điểm, đổi thứ tự, chuyển ngày ở máy chủ; giờ xếp lại; bản được lưu = bản đã sửa. */
    public function test_draft_can_be_edited_before_saving_and_saved_as_edited(): void
    {
        $mk = fn($k, $n, $lat) => ['key' => 'place:' . $k, 'type' => 'place', 'refId' => $k, 'name' => $n, 'startMin' => 480, 'durationMin' => 60, 'travelMin' => 0, 'travelKm' => 0,
            'slot' => null, 'reasons' => [], 'lat' => $lat, 'lng' => 108.44, 'priceMin' => 20000, 'group' => 'nature'];
        $plan = ['days' => [['day' => 1, 'activityMin' => 120, 'travelMin' => 0, 'items' => [$mk(1, 'A', 11.94), $mk(2, 'B', 11.95)]],
            ['day' => 2, 'activityMin' => 60, 'travelMin' => 0, 'items' => [$mk(3, 'C', 11.96)]]], 'unplaced' => [], 'budget' => ['knownMin' => 120000]];
        $result = ['ok' => true, 'destination' => ['id' => $this->dest, 'name' => 'Đà Lạt'], 'request' => ['startDate' => null, 'days' => 2, 'adults' => 2, 'children' => 0], 'plan' => $plan];
        $saved = null;
        $mock = \Mockery::mock(\App\Services\Planner\PlannerService::class);
        $mock->shouldReceive('generate')->andReturn($result);
        $mock->shouldReceive('save')->andReturnUsing(function ($uid, $r) use (&$saved) {
            $saved = $r;
            return DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'title' => 'Đà Lạt', 'days' => 2, 'groupSize' => 2, 'kind' => 'trip', 'createdAt' => now(), 'updatedAt' => now()]);
        });
        $this->app->instance(\App\Services\Planner\PlannerService::class, $mock);
        $said = ['said' => ['Đà Lạt 2 ngày, 2 người lớn']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        $tok = $j['draftToken'];
        $this->assertSame('nature', $j['draft']['days'][0]['items'][0]['tile']['g'], 'thiếu ảnh thì có ô màu theo nhóm');
        $this->assertContains('price_ref', array_column($j['draft']['days'][0]['items'][0]['trust'], 'code'), 'giá chưa xác minh gắn "Giá tham khảo"');

        // đổi thứ tự trong ngày 1: B lên trước A
        $r = $this->me()->postJson('/len-ke-hoach/tro-ly/nhap/sua', ['draftToken' => $tok, 'ops' => [['op' => 'move', 'day' => 1, 'index' => 1, 'toDay' => 1, 'toIndex' => 0]]])->assertOk()->json();
        $this->assertSame(['B', 'A'], array_column($r['draft']['days'][0]['items'], 'name'));
        $this->assertGreaterThan(0, $r['draft']['days'][0]['items'][1]['travelMin'], 'đường đi tính lại cho thứ tự mới');
        // chuyển C sang ngày 1, bỏ A
        $r = $this->me()->postJson('/len-ke-hoach/tro-ly/nhap/sua', ['draftToken' => $tok, 'ops' => [['op' => 'move', 'day' => 2, 'index' => 0, 'toDay' => 1], ['op' => 'remove', 'day' => 1, 'index' => 1]]])->assertOk()->json();
        $this->assertSame(['B', 'C'], array_column($r['draft']['days'][0]['items'], 'name'));
        $this->assertSame([], $r['draft']['days'][1]['items']);
        $this->assertSame(1, $r['draft']['unplaced'], 'mục bị bỏ vào "Đã lưu"');
        $this->assertSame(80000, $r['draft']['knownCost'], 'chi phí biết trước tính lại');
        // chỉ số sai -> 422; mã lạ -> 409
        $this->me()->postJson('/len-ke-hoach/tro-ly/nhap/sua', ['draftToken' => $tok, 'ops' => [['op' => 'remove', 'day' => 1, 'index' => 9]]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/nhap/sua', ['draftToken' => str_repeat('x', 32), 'ops' => [['op' => 'remove', 'day' => 1, 'index' => 0]]])->assertStatus(409);
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->count(), 'sửa nháp không ghi CSDL');

        // lưu: đúng bản đã sửa
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $tok])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(['place:2', 'place:3'], array_column($saved['plan']['days'][0]['items'], 'key'));
        $this->assertSame('place:1', $saved['plan']['unplaced'][0]['key']);
    }

    /** So sánh "Thong thả" và "Đi nhiều": mỗi bản có mã riêng, chọn bản nào thì lưu đúng bản đó. */
    public function test_compare_paces_returns_two_drafts_each_saveable(): void
    {
        $seen = [];
        $mock = \Mockery::mock(\App\Services\Planner\PlannerService::class);
        $mock->shouldReceive('generate')->andReturnUsing(function ($d) use (&$seen) {
            $seen[] = $d['pace'] ?? null;
            $n = ($d['pace'] ?? null) === 'packed' ? 5 : (($d['pace'] ?? null) === 'relaxed' ? 2 : 3);
            $items = [];
            for ($i = 1; $i <= $n; $i++) {
                $items[] = ['key' => 'place:' . $i, 'type' => 'place', 'refId' => $i, 'name' => 'N' . $i, 'startMin' => 480 + $i * 70, 'durationMin' => 60, 'travelMin' => 5, 'travelKm' => 1, 'slot' => null, 'reasons' => [], 'lat' => null, 'lng' => null];
            }
            return ['ok' => true, 'destination' => ['id' => $this->dest, 'name' => 'Đà Lạt'], 'request' => ['startDate' => null, 'pace' => $d['pace'] ?? null],
                'plan' => ['days' => [['day' => 1, 'activityMin' => $n * 60, 'travelMin' => $n * 5, 'items' => $items]], 'unplaced' => [], 'budget' => []]];
        });
        $mock->shouldReceive('save')->andReturnUsing(fn($uid, $r) => DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'title' => 'x', 'days' => 1, 'groupSize' => 2, 'kind' => 'trip', 'createdAt' => now(), 'updatedAt' => now()]));
        $this->app->instance(\App\Services\Planner\PlannerService::class, $mock);
        $said = ['said' => ['Đà Lạt 1 ngày, 2 người lớn']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['compare' => true])->assertOk()->json();
        $this->assertSame(['relaxed', 'packed'], array_column($j['compare'], 'pace'));
        $this->assertSame([2, 5], array_map(fn($v) => $v['draft']['count'], $j['compare']));
        $packed = $j['compare'][1];
        // chọn "Đi nhiều": gửi nhịp packed + mã của bản đó -> lưu được, không xếp lại
        $before = count($seen);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $packed['token'], 'adjustments' => ['pace' => 'packed']])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($before, count($seen));
    }

    public function test_origin_city_is_not_taken_as_destination(): void
    {
        DB::table('tbl_destinations')->insert(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        \Illuminate\Support\Facades\Cache::flush();
        $i = app(\App\Services\Content\SearchIntent::class);
        $this->assertSame('da-lat', $i->parse('Mình đi Đà Lạt 3 ngày, xuất phát từ Hà Nội')['destination']['slug']);
        $this->assertSame('da-lat', $i->parse('từ Hà Nội bay vào Đà Lạt')['destination']['slug']);
        $this->assertSame('ha-noi', $i->parse('đi Hà Nội 2 ngày')['destination']['slug']);
    }

    public function test_brief_endpoint_works_with_ai_off(): void
    {
        $j = $this->me()->postJson('/len-ke-hoach/hieu-yeu-cau', ['text' => 'Đà Lạt 3 ngày cho 2 người'])->assertOk()->json();
        $this->assertTrue($j['ok']);
        $this->assertSame('rules', $j['engine']);
        $this->assertSame(3, (int) $j['prefill']['days']);
        $this->me()->postJson('/len-ke-hoach/hieu-yeu-cau', ['text' => ''])->assertStatus(422);
    }

    public function test_rate_stores_feedback_and_ignores_trips_user_cannot_see(): void
    {
        $other = DB::table('tbl_users')->insertGetId(['username' => 'b', 'email' => 'b@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $foreign = DB::table('tbl_trips')->insertGetId(['userId' => $other, 'kind' => 'trip', 'title' => 'Của B', 'days' => 1, 'status' => 'planned', 'createdAt' => now()]);

        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'copilot', 'helpful' => false, 'reason' => 'too_generic', 'note' => '<script>x</script>Chung chung', 'tripId' => $foreign])
            ->assertOk()->assertJson(['ok' => true]);
        $f = DB::table('tbl_ai_feedback')->first();
        $this->assertNull($f->tripId, 'không gắn đánh giá vào chuyến của người khác');
        $this->assertSame('too_generic', $f->reason);
        $this->assertStringNotContainsString('<script>', $f->note);

        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'brief', 'helpful' => true, 'reason' => 'too_generic'])->assertOk();
        $this->assertNull(DB::table('tbl_ai_feedback')->orderByDesc('feedbackId')->value('reason'), 'đúng ý thì không lưu lý do');
        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'hack', 'helpful' => true])->assertStatus(422);
    }

    /** Lưu lỗi thì bản nháp còn nguyên để bấm lại; bấm lại sau khi lỗi được khắc phục thì lưu đúng một chuyến. */
    public function test_failed_save_keeps_draft_and_retry_creates_exactly_one_trip(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequests::class);
        $plan = ['days' => [['day' => 1, 'activityMin' => 60, 'travelMin' => 0, 'items' => [
            ['key' => 'place:1', 'type' => 'place', 'refId' => 1, 'name' => 'Hồ', 'startMin' => 480, 'durationMin' => 60, 'travelMin' => 0, 'travelKm' => 0, 'slot' => 'morning',
                'reasons' => [], 'lat' => 11.94, 'lng' => 108.44, 'coordsApprox' => false]]]], 'unplaced' => [], 'budget' => ['knownMin' => 0, 'message' => 'x']];
        $result = ['ok' => true, 'destination' => ['id' => $this->dest, 'name' => 'Đà Lạt'], 'request' => ['startDate' => '2027-03-10'], 'plan' => $plan];
        $fail = true;
        $mock = \Mockery::mock(\App\Services\Planner\PlannerService::class);
        $mock->shouldReceive('generate')->andReturn($result);
        $mock->shouldReceive('save')->andReturnUsing(function ($uid) use (&$fail) {
            if ($fail) {
                throw new \DomainException('Lỗi tạm thời khi lưu.');
            }

            return DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'title' => 'Đà Lạt', 'days' => 1, 'groupSize' => 2, 'kind' => 'trip', 'createdAt' => now(), 'updatedAt' => now()]);
        });
        $this->app->instance(\App\Services\Planner\PlannerService::class, $mock);
        $said = ['said' => ['Đà Lạt 1 ngày, 2 người lớn, thích thiên nhiên']];
        $tok = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json('draftToken');

        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $tok])->assertOk()->assertJsonPath('ok', false);
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\clients\PlannerAiController::draftKey($this->uid, $tok)), 'lưu lỗi: bản nháp còn nguyên');

        $fail = false;
        $a = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $tok])->assertOk()->assertJsonPath('ok', true)->json();
        $b = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $tok])->assertOk()->json();
        $this->assertSame($a['url'], $b['url']);
        $this->assertSame(1, DB::table('tbl_trips')->where('userId', $this->uid)->count());
    }
}
