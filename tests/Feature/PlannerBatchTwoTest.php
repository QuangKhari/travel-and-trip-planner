<?php

namespace Tests\Feature;

use App\Services\Planner\PlaceFacts;
use App\Services\Planner\PlaceSignals;
use App\Services\Planner\TripPlanner;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Đợt 2: học từ hành vi (thêm / xóa / ghé / bỏ qua -> hồ sơ + thống kê -> chấm điểm có lý do), đánh giá sau chuyến,
 * quy trình dữ liệu (nhập giờ OSM, hàng chờ kiểm tra, xác nhận), bàn bạc nhóm, thông báo đẩy (VAPID), PWA.
 */
class PlannerBatchTwoTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 09:00:00');
        Cache::flush();
        config(['core_destinations.slugs' => [], 'planner.weather.enabled' => false, 'ai.enabled' => false]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'x', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = $this->user('c1');
        $this->s = ['userId' => $this->uid, 'username' => 'c1'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $n): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n, 'email' => $n . '@example.com', 'password' => 'x', 'fullName' => strtoupper($n), 'isActive' => 'y']);
    }

    private function place(string $name, string $kind, float $d = 0, array $x = []): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->dest, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => 21.03 + $d, 'longitude' => 105.85 + $d, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()] + $x);
    }

    private function save(int $id, array $items, array $s = null): void
    {
        $this->withSession($s ?? $this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'items' => $items, 'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();
    }

    public function test_signals_profile_and_learned_scoring(): void
    {
        $mus = [$this->place('Bảo tàng 1', 'museum'), $this->place('Bảo tàng 2', 'museum', 0.001)];
        $park = $this->place('Công viên', 'park', 0.002);
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->dest]);
        $this->save($id, [['type' => 'place', 'refId' => $mus[0], 'day' => 1], ['type' => 'place', 'refId' => $mus[1], 'day' => 1], ['type' => 'place', 'refId' => $park, 'day' => 2]]);
        // ghé 2 bảo tàng, xóa công viên
        $this->save($id, [['type' => 'place', 'refId' => $mus[0], 'day' => 1, 'visitedAt' => '2027-03-03T03:00:00Z'],
            ['type' => 'place', 'refId' => $mus[1], 'day' => 1, 'visitedAt' => '2027-03-03T05:00:00Z']]);
        $sig = DB::table('tbl_place_signals')->where('userId', $this->uid)->pluck('signal')->countBy()->all();
        $this->assertSame(['added' => 3, 'removed' => 1, 'visited' => 2], array_intersect_key($sig, ['added' => 1, 'removed' => 1, 'visited' => 1]) + []);
        $p = app(PlaceSignals::class)->profile($this->uid);
        $this->assertGreaterThan(0.3, $p['culture']);
        $this->assertLessThanOrEqual(0, $p['nature']);   // thêm rồi xóa: không còn điểm cộng

        // thống kê chung: 5 người khác đều ghé Bảo tàng 1 -> lý do "x% người đã xếp nơi này thật sự ghé"
        foreach (range(1, 5) as $i) {
            DB::table('tbl_place_signals')->insert([['userId' => 900 + $i, 'refType' => 'place', 'refId' => $mus[0], 'grp' => 'culture', 'signal' => 'added', 'createdAt' => now()],
                ['userId' => 900 + $i, 'refType' => 'place', 'refId' => $mus[0], 'grp' => 'culture', 'signal' => 'visited', 'createdAt' => now()]]);
        }
        $st = app(PlaceSignals::class)->stats(['place:' . $mus[0], 'place:' . $park]);
        $this->assertSame(1.0, $st['place:' . $mus[0]]['keep']);
        $this->assertArrayNotHasKey('place:' . $park, $st);   // < 5 tín hiệu: không dùng

        $r = app(\App\Services\Planner\PlannerService::class)->generate(['destinationId' => $this->dest, 'startDate' => '2027-03-10', 'days' => 1, 'adults' => 2, 'groupSize' => 2,
            'month' => 3, 'pace' => 'normal', 'userId' => $this->uid], true);
        $reasons = collect($r['plan']['days'][0]['items'])->firstWhere('refId', $mus[0])['reasons'] ?? [];
        $this->assertContains('Bạn hay giữ và ghé những nơi kiểu này', $reasons);
        $this->assertTrue(collect($reasons)->contains(fn($x) => str_contains($x, 'người đã xếp nơi này thật sự ghé')));
    }

    public function test_post_trip_review_needs_visit_and_feeds_engine(): void
    {
        $a = $this->place('Đền A', 'place_of_worship');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->dest]);
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1]]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/danh-gia", ['type' => 'place', 'refId' => $a, 'stars' => 5])->assertStatus(422);
        $this->save($id, [['type' => 'place', 'refId' => $a, 'day' => 1, 'visitedAt' => '2027-03-03T03:00:00Z']]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/danh-gia", ['type' => 'place', 'refId' => $a, 'stars' => 5, 'stayMin' => 40])->assertOk()
            ->assertJsonPath('reviews.place:' . $a . '.stars', 5);
        foreach ([4, 5] as $i => $st) {
            DB::table('tbl_place_reviews')->insert(['userId' => 800 + $i, 'tripId' => null, 'refType' => 'place', 'refId' => $a, 'stars' => $st, 'stayMin' => 50, 'createdAt' => now()]);
        }
        $rv = app(PlaceSignals::class)->reviews(['place:' . $a]);
        $this->assertSame(4.7, $rv['place:' . $a]['avg']);
        $this->assertSame(50, $rv['place:' . $a]['stay']);
    }

    public function test_data_pipeline_queue_verify_and_osm_import(): void
    {
        $a = $this->place('Chùa thiếu giờ', 'place_of_worship');
        $b = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->dest, 'source' => 'overpass', 'externalId' => 'node/42', 'name' => 'Quán OSM',
            'category' => 'eat', 'kind' => 'restaurant', 'latitude' => 21.04, 'longitude' => 105.86, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()]);
        $this->assertSame(1, app(PlaceFacts::class)->importHours('overpass', [['externalId' => 'node/42', 'hours' => 'Mo-Su 07:00-22:00'], ['externalId' => 'node/43', 'hours' => 'nonsense']]));
        $this->assertSame('osm', DB::table('tbl_opening_hours')->where('refId', $b)->value('source'));
        $this->assertSame('OpenStreetMap', DB::table('tbl_external_places')->where('placeId', $b)->value('factsSource'));
        // giờ do người nhập không bị bản đồ ghi đè
        app(\App\Services\Planner\OpeningHours::class)->store('place', $b, 'Mo-Su 08:00-20:00', 'admin');
        $this->assertSame(0, app(PlaceFacts::class)->importHours('overpass', [['externalId' => 'node/42', 'hours' => 'Mo-Su 07:00-22:00']]));

        $ids = array_column(app(PlaceFacts::class)->queue(), 'placeId');
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);   // có giờ nhưng chưa xác minh
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'm' . uniqid(), 'passWord' => 'x', 'email' => uniqid() . '@example.com', 'fullName' => 'A', 'address' => 'HN', 'role' => 'manager']);
        $this->withSession(['adminId' => $admin])->get('/admin/place-data?tab=verify')->assertOk()->assertSee('Chùa thiếu giờ');
        $this->withSession(['adminId' => $admin])->post("/admin/place-data/verify/$a", ['hours' => 'sai cú pháp'])->assertSessionHasErrors('hours');
        $this->withSession(['adminId' => $admin])->post("/admin/place-data/verify/$a", ['hours' => 'Mo-Su 06:00-18:00', 'source' => 'Biển ở cổng', 'priceMin' => 0])->assertSessionHas('success');
        $this->assertNotContains($a, array_column(app(PlaceFacts::class)->queue(), 'placeId'));
        $facts = app(PlaceFacts::class)->forPlaces([$a]);
        $this->assertSame('Biển ở cổng', $facts[$a]['src']);
        $this->assertFalse($facts[$a]['stale']);
    }

    public function test_group_comments_and_votes(): void
    {
        $a = $this->place('Hồ', 'viewpoint');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->dest]);
        $friend = $this->user('c2');
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $friend, 'role' => 'edit']);
        $k = 'place:' . $a;
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/ban-bac/binh-luan", ['itemKey' => $k, 'text' => 'Đi lúc bình minh nhé'])->assertOk();
        $j = $this->withSession(['userId' => $friend, 'username' => 'c2'])->postJson("/len-ke-hoach/$id/ban-bac/phieu", ['itemKey' => $k, 'vote' => 1])->assertOk()->json('items');
        $this->assertSame('Đi lúc bình minh nhé', $j[$k]['comments'][0]['text']);
        $this->assertFalse($j[$k]['comments'][0]['mine']);
        $this->assertSame(['C2'], $j[$k]['up']);
        $this->assertSame(1, $j[$k]['mine']);
        $this->withSession(['userId' => $friend, 'username' => 'c2'])->postJson("/len-ke-hoach/$id/ban-bac/phieu", ['itemKey' => $k, 'vote' => 0])->assertOk();
        $this->assertSame(0, DB::table('tbl_trip_votes')->count());
        $this->withSession(['userId' => $friend, 'username' => 'c2'])->postJson("/len-ke-hoach/$id/ban-bac/binh-luan", ['itemKey' => 'drop table', 'text' => 'x'])->assertStatus(422);
        $stranger = $this->user('c3');
        $this->withSession(['userId' => $stranger, 'username' => 'c3'])->getJson("/len-ke-hoach/$id/ban-bac")->assertNotFound();
    }

    public function test_web_push_vapid_subscribe_and_tickle(): void
    {
        $k = WebPush::generateKeys();
        config(['push.public' => $k['public'], 'push.private' => $k['private'], 'push.subject' => 'mailto:t@example.com']);
        // chữ ký ES256 kiểm được bằng khóa công khai (r||s -> DER)
        $jwt = WebPush::jwt('https://fcm.googleapis.com', time() + 3600);
        [$h, $p, $sig] = explode('.', $jwt);
        $raw = base64_decode(strtr($sig, '-_', '+/') . str_repeat('=', (4 - strlen($sig) % 4) % 4));
        $int = fn(string $x) => "\x02" . chr(strlen($x = (ord(ltrim($x, "\0")[0] ?? "\0") & 0x80 ? "\0" : '') . ltrim($x, "\0"))) . $x;
        $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($der)) . $der;
        $pub = openssl_pkey_get_details(openssl_pkey_get_private(base64_decode($k['private'])))['key'];
        $this->assertSame(1, openssl_verify($h . '.' . $p, $der, $pub, OPENSSL_ALGO_SHA256));
        $this->assertSame('https://fcm.googleapis.com', json_decode(base64_decode(strtr($p, '-_', '+/')), true)['aud']);

        $this->withSession($this->s)->postJson('/thong-bao-day/dang-ky', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertOk();
        $this->withSession($this->s)->postJson('/thong-bao-day/dang-ky', ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertStatus(422);
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()->push('', 201)->push('', 410)]);
        $this->assertSame(1, app(WebPush::class)->ping([$this->uid]));
        Http::assertSent(fn($r) => str_starts_with($r->header('Authorization')[0] ?? '', 'vapid t=') && $r->body() === '');
        app(WebPush::class)->ping([$this->uid]);
        $this->assertSame(0, DB::table('tbl_push_subscriptions')->count(), '410 Gone: xóa đăng ký');

        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->dest]);
        DB::table('tbl_trip_events')->insert(['tripId' => $id, 'kind' => 'alert', 'tone' => 'bad', 'title' => 'Dông ngày 1', 'text' => 'Đổi lịch', 'ref' => 'x', 'createdAt' => now()]);
        $this->withSession($this->s)->getJson('/thong-bao/moi-nhat')->assertOk()->assertJsonPath('event.title', 'Dông ngày 1');
    }

    public function test_pwa_files(): void
    {
        $m = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $m['display']);
        foreach ($m['icons'] as $i) {
            $this->assertFileExists(public_path(ltrim($i['src'], '/')));
        }
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('/thong-bao/moi-nhat', $sw);
        $this->assertStringContainsString('len-ke-hoach', $sw);
    }
}
