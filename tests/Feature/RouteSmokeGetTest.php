<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Mọi trang GET (khách, admin) với tham số URL bị sửa (mảng, chuỗi rất dài, số âm): không trang nào lỗi 500. */
class RouteSmokeGetTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_routes_with_array_query(): void
    {
        $this->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class, \App\Http\Middleware\ThrottleRequests::class]);
        $u = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
        $a = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
        $keys = ['q', 'search', 'status', 'page', 'sort', 'type', 'month', 'days', 'from', 'to', 'tab', 'filter', 'rating', 'visibility', 'destination', 'category', 'kind', 'date', 'id', 'user', 'tour', 'level', 'per', 'edit'];
        $fails = [];
        $n = 0;
        foreach (Route::getRoutes() as $r) {
            if (!in_array('GET', $r->methods(), true) || str_contains($r->uri(), '{') || preg_match('#^(_|telescope|sanctum|storage|up$|health|api/)#', $r->uri())) {
                continue;
            }
            $sess = str_starts_with($r->uri(), 'admin') ? ['adminId' => $a, 'adminRole' => 'admin'] : ['userId' => $u, 'username' => 'a'];
            foreach ([array_fill_keys($keys, ['x' => ['y']]), array_fill_keys($keys, str_repeat('ä', 300)), array_fill_keys($keys, '-1')] as $bi => $q) {
                $n++;
                try {
                    $res = $this->withSession($sess)->get('/' . $r->uri() . '?' . http_build_query($q));
                    if ($res->getStatusCode() >= 500) {
                        $e = $res->exception;
                        $fails[$r->uri() . " #$bi"] = $e ? get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 220) . ' @' . str_replace(base_path(), '', $e->getFile()) . ':' . $e->getLine() : 'status ' . $res->getStatusCode();
                    }
                } catch (\Throwable $e) {
                    $fails[$r->uri() . " #$bi"] = 'THROW ' . $e->getMessage();
                }
                unset($res);
                $this->flushSession();
                if ($n % 25 === 0) {
                    gc_collect_cycles();   // máy Windows mặc định memory_limit 128M, cả bộ test chạy chung một tiến trình
                }
            }
        }
        $this->assertGreaterThan(100, $n, 'đã thử đủ nhiều yêu cầu');
        $this->assertSame([], $fails, 'các yêu cầu lỗi 500');
    }
}
