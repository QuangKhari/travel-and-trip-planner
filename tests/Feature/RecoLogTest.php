<?php

namespace Tests\Feature;

use App\Services\Planner\Reco\RecoFeatures;
use App\Services\Planner\Reco\RecoLog;
use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt D (L1, L2): ghi gợi ý đã hiện, gán nhãn theo hành vi sau đó, đặc trưng không nhìn trước tương lai. */
class RecoLogTest extends TestCase
{
    use RefreshDatabase;

    private int $u;
    private int $trip;
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->u = DB::table('tbl_users')->insertGetId(['username' => 'reco', 'email' => 'reco@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'R', 'isActive' => 'y']);
        $d = DB::table('tbl_destinations')->insertGetId(['slug' => 'dl', 'name' => 'Đà Lạt', 'province' => 'x', 'domain' => 'b', 'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.45, 'isActive' => 1]);
        foreach ([['Bảo tàng Lâm Đồng', 'museum', 'Bảo tàng lịch sử văn hóa Tây Nguyên'], ['Thác Datanla', 'attraction', 'Thác nước, máng trượt'], ['Đồi chè Cầu Đất', 'viewpoint', 'Đồi chè, ngắm cảnh']] as [$n, $k, $desc]) {
            $this->p[] = DB::table('tbl_external_places')->insertGetId(['destinationId' => $d, 'source' => 'import', 'externalId' => uniqid(), 'name' => $n, 'category' => 'sight',
                'kind' => $k, 'description' => $desc, 'latitude' => 11.94, 'longitude' => 108.45, 'rank' => 5, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        }
        $this->trip = app(TripPlanner::class)->create($this->u, ['title' => 'Đà Lạt', 'days' => 2, 'groupSize' => 2, 'destinationId' => $d]);
    }

    public function test_search_logs_impressions_without_query_text_and_labels_follow_later_actions(): void
    {
        $j = $this->withSession(['userId' => $this->u, 'username' => 'reco'])->getJson("/len-ke-hoach/{$this->trip}/tim?q=bảo tàng")->assertOk()->json();
        $this->assertNotEmpty($j['listId']);
        $rows = DB::table('tbl_reco_impressions')->where('listId', $j['listId'])->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame('search', $rows[0]->surface);
        $this->assertStringNotContainsString('bảo', (string) $rows[0]->ctx);   // chỉ độ dài truy vấn

        // hiện cả ba nơi, người dùng thêm nơi thứ hai
        $trip = DB::table('tbl_trips')->where('tripId', $this->trip)->first();
        RecoLog::log($this->u, $trip, 'browse', array_map(fn($id) => ['type' => 'place', 'refId' => $id], $this->p));
        DB::table('tbl_reco_impressions')->update(['createdAt' => now()->subHours(2)]);
        DB::table('tbl_place_signals')->insert(['userId' => $this->u, 'tripId' => $this->trip, 'refType' => 'place', 'refId' => $this->p[1], 'grp' => 'nature', 'signal' => 'added', 'createdAt' => now()->subHour()]);

        $lab = collect(RecoLog::labelled())->where('surface', 'browse');
        $this->assertSame([0, 1, 0], $lab->sortBy('position')->pluck('label')->all());
    }

    public function test_pause_and_forget_are_respected(): void
    {
        DB::table('tbl_user_taste')->insert(['userId' => $this->u, 'grp' => '_pause', 'value' => 1]);
        $trip = DB::table('tbl_trips')->where('tripId', $this->trip)->first();
        $this->assertNull(RecoLog::log($this->u, $trip, 'browse', [['type' => 'place', 'refId' => $this->p[0]]]));
        DB::table('tbl_user_taste')->delete();
        $this->assertNotNull(RecoLog::log($this->u, $trip, 'browse', [['type' => 'place', 'refId' => $this->p[0]]]));
        $this->withSession(['userId' => $this->u, 'username' => 'reco'])->post('/len-ke-hoach/so-thich/quen');
        $this->assertSame(0, DB::table('tbl_reco_impressions')->where('userId', $this->u)->count());
    }

    public function test_features_only_use_history_before_the_impression_time(): void
    {
        $other = $this->u + 1000;
        $t0 = strtotime('2026-12-01 10:00:00');
        $f = (new RecoFeatures())->load(array_map(fn($id) => 'place:' . $id, $this->p))->withSignals([
            ['userId' => $this->u, 't' => $t0 - 3600, 'key' => 'place:' . $this->p[0], 'grp' => 'culture', 'signal' => 'added'],
            ['userId' => $this->u, 't' => $t0 + 3600, 'key' => 'place:' . $this->p[2], 'grp' => 'nature', 'signal' => 'visited'],   // tương lai: không được dùng
            ['userId' => $other, 't' => $t0 - 60, 'key' => 'place:' . $this->p[1], 'grp' => 'nature', 'signal' => 'visited'],
        ]);
        $a = $f->vector($this->u, 'place:' . $this->p[0], $t0);
        $this->assertGreaterThan(0, $f->affinityAt($this->u, $t0)['culture']);
        $this->assertArrayNotHasKey('nature', $f->affinityAt($this->u, $t0));
        $this->assertSame(0.0, $a['sim']);   // chính nó không tính vào hồ sơ
        $this->assertGreaterThan(0, $f->globalAt($this->u, 'place:' . $this->p[1], $t0));
        $this->assertSame(0.0, $f->globalAt($other, 'place:' . $this->p[1], $t0));   // không tự đếm mình
        $this->assertSame($f->names(), array_keys($a));
    }
}
