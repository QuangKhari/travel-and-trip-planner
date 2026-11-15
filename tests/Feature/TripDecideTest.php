<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đi nhóm: bỏ phiếu trước khi có lịch (ngày, điểm đến, chỗ ở) + ai đi; chốt thì áp dụng vào chuyến. */
class TripDecideTest extends TestCase
{
    use RefreshDatabase;

    private function u(string $n): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => ucfirst($n), 'isActive' => 'y']);
    }

    public function test_vote_close_apply_and_rsvp(): void
    {
        [$a, $b, $c, $x] = [$this->u('an'), $this->u('binh'), $this->u('chi'), $this->u('ngoai')];
        $dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'hoi-an', 'name' => 'Hội An', 'province' => 'QN', 'domain' => 't', 'description' => 'x', 'latitude' => 15.8, 'longitude' => 108.3, 'isActive' => 1]);
        $trip = app(TripPlanner::class)->create($a, ['title' => 'Nhóm', 'days' => 3, 'groupSize' => 2, 'startDate' => null]);
        DB::table('tbl_trip_members')->insert([['tripId' => $trip, 'userId' => $b, 'role' => 'edit', 'createdAt' => now()], ['tripId' => $trip, 'userId' => $c, 'role' => 'view', 'createdAt' => now()]]);
        $as = fn($u) => $this->withSession(['userId' => $u, 'username' => 'u' . $u]);
        $base = "/len-ke-hoach/$trip/cung-quyet-dinh";

        // người chỉ xem không tạo câu hỏi; người ngoài không thấy
        $as($c)->postJson($base, ['kind' => 'date'])->assertForbidden();
        $as($x)->getJson($base)->assertNotFound();

        $d1 = now()->addDays(20)->toDateString();
        $d2 = now()->addDays(27)->toDateString();
        $j = $as($a)->postJson($base, ['kind' => 'date', 'options' => [['value' => $d1], ['value' => '2020-01-01']]])->assertOk()->json();
        $poll = $j['polls'][0]['id'];
        $this->assertCount(1, $j['polls'][0]['options'], 'ngày đã qua bị bỏ');
        // ai trong chuyến cũng thêm lựa chọn + bỏ phiếu (kể cả người chỉ xem)
        $as($c)->postJson("$base/$poll/lua-chon", ['value' => $d2])->assertOk();
        $as($c)->postJson("$base/$poll/lua-chon", ['value' => $d2])->assertStatus(422);
        $opts = collect($as($a)->getJson($base)->json('polls.0.options'))->pluck('id', 'value');
        $as($a)->postJson("$base/$poll/bo-phieu", ['optionId' => $opts[$d1]])->assertOk();
        $as($b)->postJson("$base/$poll/bo-phieu", ['optionId' => $opts[$d2]])->assertOk();
        // bằng phiếu: chốt theo phiếu bị từ chối
        $as($a)->postJson("$base/$poll/chot", [])->assertStatus(422)->assertJsonPath('message', 'Đang bằng phiếu. Chọn lựa chọn để chốt.');
        $j = $as($c)->postJson("$base/$poll/bo-phieu", ['optionId' => $opts[$d2]])->assertOk()->json();
        $this->assertSame(2, collect($j['polls'][0]['options'])->firstWhere('value', $d2)['votes']);
        $as($c)->postJson("$base/$poll/chot", [])->assertForbidden();
        $rev = (int) DB::table('tbl_trips')->where('tripId', $trip)->value('rev');
        $as($b)->postJson("$base/$poll/chot", [])->assertOk()->assertJsonPath('reload', true);
        $t = DB::table('tbl_trips')->where('tripId', $trip)->first();
        $this->assertSame($d2, $t->startDate, 'chốt ngày -> ngày khởi hành');
        $this->assertGreaterThan($rev, (int) $t->rev, 'tăng phiên bản để người đang mở thấy ngay');
        $as($a)->postJson("$base/$poll/bo-phieu", ['optionId' => $opts[$d1]])->assertStatus(422);

        // điểm đến + chỗ ở
        $q2 = $as($a)->postJson($base, ['kind' => 'destination', 'options' => [['value' => (string) $dest]]])->assertOk()->json('polls.0.id');
        $o2 = $as($a)->getJson($base)->json('polls.0.options.0.id');
        $as($a)->postJson("$base/$q2/chot", ['optionId' => $o2])->assertOk();
        $this->assertSame($dest, (int) DB::table('tbl_trips')->where('tripId', $trip)->value('destinationId'));
        $q3 = $as($a)->postJson($base, ['kind' => 'stay', 'options' => [['label' => 'Homestay Bên Sông', 'value' => 'https://example.com/h']]])->assertOk()->json('polls.0.id');
        $as($a)->postJson("$base/$q3/bo-phieu", ['optionId' => $as($a)->getJson($base)->json('polls.0.options.0.id')])->assertOk();
        $as($a)->postJson("$base/$q3/chot", [])->assertOk();
        $this->assertSame('Homestay Bên Sông', DB::table('tbl_trip_external')->where('tripId', $trip)->value('title'));

        // ai đi -> số người của chuyến; người không đi không được chia khoản chi mới
        $as($a)->postJson("/len-ke-hoach/$trip/tham-gia", ['rsvp' => 'no'])->assertStatus(422);
        $as($b)->postJson("/len-ke-hoach/$trip/tham-gia", ['rsvp' => 'going'])->assertOk();
        $j = $as($c)->postJson("/len-ke-hoach/$trip/tham-gia", ['rsvp' => 'no'])->assertOk()->json();
        $this->assertSame([2, 0, 1], [$j['people']['going'], $j['people']['maybe'], $j['people']['no']]);
        DB::table('tbl_trips')->where('tripId', $trip)->update(['groupSize' => 5]);
        $as($a)->postJson("/len-ke-hoach/$trip/so-nguoi-theo-nhom")->assertOk();
        $this->assertSame(2, (int) DB::table('tbl_trips')->where('tripId', $trip)->value('groupSize'));
        $people = app(\App\Services\Planner\TripExpenses::class)->people(DB::table('tbl_trips')->where('tripId', $trip)->first());
        $this->assertNotContains('u:' . $c, array_column($people, 'who'));
    }
}
