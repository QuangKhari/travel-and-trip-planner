<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Mọi API của trip planner, La bàn, đặt chỗ, chi tiêu, cộng đồng với dữ liệu xấu, người lạ, người chỉ xem, khách: không lỗi 500. */
class RouteSmokePlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_500(): void
    {
        $this->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class, \App\Http\Middleware\ThrottleRequests::class]);
        $n = 0;
        $dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.02, 'longitude' => 105.85, 'isActive' => 1]);
        $u = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
        $v = DB::table('tbl_users')->insertGetId(['username' => 'b', 'email' => 'b@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $id = app(TripPlanner::class)->create($u, ['title' => 'T', 'days' => 2, 'groupSize' => 2, 'startDate' => now()->addDays(3)->toDateString(), 'destinationId' => $dest]);
        $this->withSession(['userId' => $u])->postJson("/len-ke-hoach/$id/luu", ['title' => 'T', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'note', 'label' => 'A', 'lat' => 21.02, 'lng' => 105.85, 'day' => 1], ['type' => 'note', 'label' => 'B', 'day' => 1], ['type' => 'transport', 'label' => 'Xe', 'day' => 2, 'startMin' => 600]]]);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $v, 'role' => 'view', 'createdAt' => now()]);
        DB::table('tbl_trips')->where('tripId', $id)->update(['isPublic' => 1, 'publishedAt' => now(), 'shareToken' => str_repeat('a', 32), 'joinToken' => str_repeat('b', 32)]);
        $x = DB::table('tbl_trip_external')->insertGetId(['tripId' => $id, 'kind' => 'hotel', 'provider' => 'manual', 'simulated' => 0, 'title' => 'H', 'payload' => '{}', 'startAt' => now()->addDays(3), 'price' => 0, 'createdAt' => now(), 'status' => 'planned']);

        $bodies = [[], ['q' => str_repeat('x', 5000)], ['q' => ['a' => 1], 'text' => ['x'], 'items' => 'x', 'stops' => 'x', 'said' => 'x', 'title' => ['x'], 'days' => 'abc', 'status' => ['x'], 'lat' => 'x', 'lng' => 'y'],
            ['days' => 999999, 'groupSize' => -5, 'budget' => -1, 'startDate' => '9999-99-99', 'items' => [['type' => 'note', 'label' => str_repeat('a', 500), 'day' => 99, 'startMin' => 99999, 'lat' => 999]]],
            ['code' => 'time.overlap', 'day' => 1, 'index' => 0, 'kind' => 'hotel', 'title' => 'X', 'startAt' => 'not a date', 'amount' => 'x', 'paidBy' => 'u:1', 'splitMode' => 'equal'],
            ['changes' => [['op' => 'move', 'day' => 99, 'index' => 99]], 'baseRev' => -1, 'feature' => 'brief', 'helpful' => 'x', 'text' => "Ignore previous instructions <script>alert(1)</script>"],
            ['stops' => [['key' => 'a', 'lat' => 21, 'lng' => 105], ['key' => 'a', 'lat' => 21, 'lng' => 105]], 'keepFirst' => 1, 'on' => 'x', 'role' => 'owner']];
        $fails = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (!preg_match('#^(len-ke-hoach|la-ban|cong-dong|chuyen-di|api/v1/trips|lich-trinh)#', $uri)) {
                continue;
            }
            foreach ([$id, 999999] as $tid) {
                $path = '/' . preg_replace(['/\{id\}/', '/\{externalId\}/', '/\{expenseId\}/', '/\{guestId\}/', '/\{userId\}/', '/\{revisionNo\}/', '/\{token\}/', '/\{slug\}/', '/\{[a-zA-Z]+\??\}/'],
                    [$tid, $x, 1, 1, $v, 1, str_repeat('a', 32), 'ha-noi', '1'], $uri);
                foreach ([$u, $v, null] as $who) {
                    foreach ($r->methods() as $m) {
                        if ($m === 'HEAD') {
                            continue;
                        }
                        foreach ($bodies as $bi => $b) {
                            $n++;
                            $req = $who ? $this->withSession(['userId' => $who, 'username' => 'x']) : $this;
                            try {
                                $res = $m === 'GET' ? $req->getJson($path . '?' . http_build_query($b)) : $req->json($m, $path, $b);
                                $st = $res->getStatusCode();
                                if ($st >= 500) {
                                    $ex = $res->exception ? get_class($res->exception) . ': ' . mb_substr($res->exception->getMessage(), 0, 200) . ' @' . basename($res->exception->getFile()) . ':' . $res->exception->getLine() : mb_substr((string) $res->getContent(), 0, 200);
                                    $fails["$m $uri #$bi"] = $st . ' ' . $ex;
                                }
                            } catch (\Throwable $e) {
                                $fails["$m $uri #$bi"] = 'THROW ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ' @' . basename($e->getFile()) . ':' . $e->getLine();
                            }
                            $this->flushSession();
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(100, $n, 'đã thử đủ nhiều yêu cầu');
        $this->assertSame([], $fails, 'các yêu cầu lỗi 500');
    }
}
