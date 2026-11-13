<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-05…T-08 phương tiện từng chặng, T-03 lấp khoảng trống, cờ "đã ghé", A-25 chip "đúng ý không", T-29 cá nhân hóa bản sao Cộng đồng.
 */
class PlannerTransportGapVisitTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'p1', 'email' => 'p1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'p1'];
    }

    private function place(string $name, float $lat, float $lng, string $kind = 'museum'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => '2027-05-03', 'destinationId' => $this->hn]);
    }

    private function save(int $id, array $items): void
    {
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => '2027-05-03', 'items' => $items])->assertOk();
    }

    public function test_leg_mode_is_saved_and_changes_server_travel_time(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355);
        $b = $this->place('Bảo tàng Dân tộc học', 21.0405, 105.7985);
        $id = $this->trip();
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $car = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->json('days.0.rows.0.travelMin');

        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1, 'legMode' => 'bus'], ['type' => 'place', 'refId' => $b, 'day' => 1]]);
        $this->assertSame('bus', DB::table('tbl_trip_items')->where(['tripId' => $id, 'refId' => $a])->value('legMode'));
        $h = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->json();
        $this->assertGreaterThan($car + 10, $h['days'][0]['rows'][0]['travelMin'], 'xe buýt chậm hơn ô tô và có 10 phút chờ');
        $this->assertSame($h['days'][0]['rows'][0]['travelMin'], $h['days'][0]['travelMin']);

        // trang sửa nhận được bảng phương tiện để so sánh
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('"legModes"', false)->assertSee('Xe buýt');
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2,
            'items' => [['type' => 'place', 'refId' => $a, 'day' => 1, 'legMode' => 'tau-ngam']]])->assertStatus(422);
    }

    public function test_visited_flag_roundtrips(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355);
        $id = $this->trip();
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1, 'visitedAt' => '2027-05-03T09:15:00+07:00']]);
        $this->assertNotNull(DB::table('tbl_trip_items')->where(['tripId' => $id, 'refId' => $a])->value('visitedAt'));
        $p = app(TripPlanner::class)->payload(DB::table('tbl_trips')->where('tripId', $id)->first());
        $this->assertNotNull($p['days'][0]['items'][0]['visitedAt']);
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1, 'visitedAt' => null]]);
        $this->assertNull(DB::table('tbl_trip_items')->where(['tripId' => $id, 'refId' => $a])->value('visitedAt'));
    }

    public function test_gap_fill_suggests_places_that_fit_the_gap_and_are_open(): void
    {
        $this->place('Văn Miếu', 21.0277, 105.8355);
        $near = $this->place('Bảo tàng Mỹ thuật', 21.0310, 105.8370);   // nằm giữa tuyến
        $closed = $this->place('Nhà trưng bày đóng cửa', 21.0300, 105.8360);
        app(\App\Services\Planner\OpeningHours::class)->store('place', $closed, 'Mo 13:00-17:00');   // 03/05/2027 là Thứ Hai
        $far = $this->place('Làng gốm xa', 20.9760, 105.9130);
        $id = $this->trip();
        $q = http_build_query(['a' => '21.0277,105.8355', 'b' => '21.0352,105.8400', 'gap' => 90, 'from' => 600, 'day' => 1]);
        $r = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/lap-khoang-trong?$q")->assertOk()->json('results');
        $ids = array_column($r, 'refId');
        $this->assertContains($near, $ids);
        $this->assertNotContains($closed, $ids, 'đóng cửa trong khoảng trống thì không gợi ý');
        $this->assertNotContains($far, $ids);
        $first = collect($r)->firstWhere('refId', $near);
        $this->assertLessThanOrEqual(90, $first['stayMin'] + $first['detourMin']);
        $this->assertStringContainsString('vừa khoảng trống 90′', $first['fitText']);

        $other = DB::table('tbl_users')->insertGetId(['username' => 'p2', 'email' => 'p2@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'p2'])->getJson("/len-ke-hoach/$id/lap-khoang-trong?$q")->assertNotFound();
    }

    public function test_ai_feedback_chip_records_and_links_latest_run(): void
    {
        $id = $this->trip();
        $run = DB::table('tbl_ai_runs')->insertGetId(['userId' => $this->uid, 'tripId' => $id, 'feature' => 'explain', 'engine' => 'llm', 'promptVersion' => 'explain-1', 'outcome' => 'ok']);
        $this->withSession($this->s)->postJson('/la-ban/danh-gia', ['feature' => 'explain', 'helpful' => false, 'reason' => 'too_generic', 'tripId' => $id])
            ->assertOk()->assertJson(['ok' => true, 'runId' => $run]);
        // đổi ý: chỉ giữ đánh giá mới nhất cho cùng lượt AI
        $this->withSession($this->s)->postJson('/la-ban/danh-gia', ['feature' => 'explain', 'helpful' => true, 'tripId' => $id])->assertOk();
        $rows = DB::table('tbl_ai_feedback')->where('runId', $run)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->helpful);
        $this->assertNull($rows[0]->reason);
        $this->withSession($this->s)->postJson('/la-ban/danh-gia', ['feature' => 'khac', 'helpful' => true])->assertStatus(422);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('ai-feedback.js', false);
    }

    public function test_external_confirmation_with_private_attachment(): void
    {
        \Illuminate\Support\Facades\Storage::fake('proofs');
        $id = $this->trip();
        $file = \Illuminate\Http\UploadedFile::fake()->create('xac-nhan-ks.pdf', 120, 'application/pdf');
        $j = $this->withSession($this->s)->post("/len-ke-hoach/$id/xac-nhan", ['kind' => 'hotel', 'title' => 'Hotel Sen 2 đêm', 'provider' => 'Booking',
            'code' => 'BK-778899', 'startAt' => '2027-05-03T14:00', 'price' => 1800000, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json();
        $x = DB::table('tbl_trip_external')->where('externalId', $j['externalId'])->first();
        $this->assertSame('BK-778899', $x->confirmationCode);
        \Illuminate\Support\Facades\Storage::disk('proofs')->assertExists($x->attachPath);
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Hotel Sen 2 đêm', 'dayNo' => 1, 'startMin' => 840])->exists());

        $this->withSession($this->s)->get("/len-ke-hoach/$id/xac-nhan/{$x->externalId}/tep")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = DB::table('tbl_users')->insertGetId(['username' => 'p3', 'email' => 'p3@example.com', 'password' => 'x', 'fullName' => 'C', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'p3'])->get("/len-ke-hoach/$id/xac-nhan/{$x->externalId}/tep")->assertNotFound();
        $this->withSession($this->s)->post("/len-ke-hoach/$id/xac-nhan", ['kind' => 'hotel', 'title' => 'X', 'startAt' => '2027-05-03T14:00',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('a.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/ngoai/{$x->externalId}/xoa")->assertOk();
        \Illuminate\Support\Facades\Storage::disk('proofs')->assertMissing($x->attachPath);
        $this->assertFalse(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Hotel Sen 2 đêm'])->exists());
    }

    public function test_community_clone_is_personalized_by_dislikes_and_interests(): void
    {
        $peak = $this->place('Đỉnh núi', 21.10, 105.80, 'peak');
        $museum = $this->place('Bảo tàng Lịch sử', 21.11, 105.81, 'museum');
        $author = DB::table('tbl_users')->insertGetId(['username' => 'a1', 'email' => 'a1@example.com', 'password' => 'x', 'fullName' => 'Tác giả', 'isActive' => 'y']);
        $src = app(TripPlanner::class)->create($author, ['title' => 'Hà Nội leo núi', 'days' => 1, 'groupSize' => 2, 'destinationId' => $this->hn]);
        DB::table('tbl_trip_items')->insert(['tripId' => $src, 'refType' => 'place', 'refId' => $peak, 'dayNo' => 1, 'orderNo' => 0]);
        DB::table('tbl_trips')->where('tripId', $src)->update(['isPublic' => 1, 'publishedAt' => now()]);

        $this->withSession($this->s)->post("/cong-dong/$src/dung-lai", ['groupSize' => 2, 'interests' => ['culture'], 'dislikes' => ['trekking']])
            ->assertRedirect()->assertSessionHas('personalized');
        $new = (int) DB::table('tbl_trips')->where('userId', $this->uid)->where('sourceTripId', $src)->value('tripId');
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $new, 'refId' => $museum, 'dayNo' => 1])->exists(), 'đỉnh núi được thay bằng bảo tàng gần đó');
        $this->assertFalse(DB::table('tbl_trip_items')->where(['tripId' => $new, 'refId' => $peak])->exists());
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $src, 'refId' => $peak])->exists(), 'bản gốc không đổi');
        $this->assertSame(['trekking'], json_decode((string) DB::table('tbl_trips')->where('tripId', $new)->value('dislikes'), true));
        // có lịch sử để quay lại bản chép nguyên gốc
        $this->assertSame(2, DB::table('tbl_trip_revisions')->where('tripId', $new)->count());
    }
}
