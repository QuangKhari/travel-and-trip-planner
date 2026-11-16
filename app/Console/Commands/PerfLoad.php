<?php

namespace App\Console\Commands;

use App\Support\PasswordHasher;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Đợt F (F6): đo thời gian phản hồi và thử tải qua HTTP thật (chạy trên chính máy chủ, cùng APP_KEY và kho phiên).
 *   php artisan perf:load --base=http://127.0.0.1:8000                    đo từng trang: 20 lần nối tiếp, p50 / p95
 *   php artisan perf:load --users=50 --duration=60                         thêm thử tải: 50 người dùng ảo cùng lúc trong 60 giây
 *   php artisan perf:load --users=50 --duration=60 --md=docs/so-lieu/hieu-nang.md
 *   php artisan perf:load --cleanup                                        xóa tài khoản perf01… và chuyến của chúng
 *
 * Người dùng ảo: tài khoản perf01…perfNN, mỗi người một bản sao chuyến demo Đà Lạt; phiên đăng nhập được ghi thẳng vào kho phiên
 * (không đi qua form đăng nhập, vì form giới hạn 10 lần / phút / IP). Mỗi người ảo lặp: chọn một trang theo tỉ lệ dùng thật,
 * chờ 1–3 giây (thời gian đọc), rồi chọn trang khác. Lỗi 429 (giới hạn tốc độ) được đếm riêng, không tính là máy chủ hỏng.
 * Kết quả phụ thuộc máy chạy: ghi rõ cấu hình (CPU, RAM, php-fpm / artisan serve, CACHE_STORE, SESSION_DRIVER) khi báo cáo.
 */
class PerfLoad extends Command
{
    protected $signature = 'perf:load {--base=http://127.0.0.1:8000} {--reps=20 : số lần đo mỗi trang khi chạy nối tiếp} {--users=0 : số người dùng ảo cho thử tải (0 = bỏ qua)}
        {--duration=60 : giây thử tải} {--think=1000-3000 : ms nghỉ giữa hai yêu cầu của một người ảo} {--md=} {--cleanup}';

    protected $description = 'Đo p50/p95 từng trang chính và thử tải nhiều người dùng đồng thời qua HTTP';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            $ids = DB::table('tbl_users')->where('username', 'like', 'perf%')->where('email', 'like', '%@perf.travela.test')->pluck('userId');
            DB::table('tbl_trips')->whereIn('userId', $ids)->delete();
            DB::table('tbl_users')->whereIn('userId', $ids)->delete();
            $this->info('Đã xóa ' . count($ids) . ' tài khoản thử tải.');

            return self::SUCCESS;
        }
        $base = rtrim((string) $this->option('base'), '/');
        $users = max(0, min(300, (int) $this->option('users')));
        $vus = $this->accounts(max(1, $users));
        if (!$vus) {
            $this->error('Không có chuyến mẫu để nhân bản: chạy php artisan demo:reset --force trước.');

            return self::FAILURE;
        }
        $tour = (int) DB::table('tbl_tours')->where('availability', 1)->orderBy('tourId')->value('tourId') ?: (int) DB::table('tbl_tours')->value('tourId');
        $pages = self::pages($tour);
        $env = sprintf('APP_ENV=%s · APP_DEBUG=%s · CACHE_STORE=%s · SESSION_DRIVER=%s · QUEUE=%s · PHP %s · %s', app()->environment(), config('app.debug') ? 'true' : 'false',
            config('cache.default'), config('session.driver'), config('queue.default'), PHP_VERSION, php_uname('s') . ' ' . php_uname('m'));
        $md = ['# Hiệu năng (F6)', '', 'Tạo bởi `php artisan perf:load` lúc ' . now()->format('H:i d/m/Y') . " tới $base.", '', "Cấu hình đọc từ .env của máy chạy lệnh (nếu máy chủ chạy với biến môi trường khác, ghi lại cấu hình thật): $env.", ''];

        // 1. từng trang, nối tiếp
        $this->info('Đo từng trang (' . (int) $this->option('reps') . ' lần, nối tiếp, sau 2 lần khởi động)…');
        $md[] = '## Thời gian phản hồi từng trang (một người, nối tiếp)';
        $md[] = '';
        $md[] = '| Trang | Lần đo | p50 (ms) | p95 (ms) | Lớn nhất (ms) | Kích thước (KB) | Lỗi |';
        $md[] = '|---|---|---|---|---|---|---|';
        $vu = $vus[0];
        foreach ($pages as $key => $pg) {
            $url = $base . str_replace('{trip}', (string) $vu['trip'], $pg['path']);
            $t = [];
            $bad = 0;
            $size = 0;
            for ($i = 0; $i < 2 + (int) $this->option('reps'); $i++) {
                [$code, $ms, $bytes] = self::get($url, $pg['auth'] ? $vu['cookie'] : null, $pg['json']);
                if ($i < 2) {
                    continue;
                }
                $code >= 200 && $code < 300 ? $t[] = $ms : $bad++;   // 3xx = bị đẩy về trang đăng nhập: tính là lỗi
                $size = $bytes;
            }
            $q = $t ? self::pct($t) : null;
            $md[] = sprintf('| %s | %d | %s | %s | %s | %s | %d |', $pg['label'], count($t) + $bad, $q ? $q[50] : '–', $q ? $q[95] : '–', $t ? (int) max($t) : '–', number_format($size / 1024, 0), $bad);
        }
        $md[] = '';

        // 2. thử tải
        if ($users > 0) {
            $this->info("Thử tải: $users người dùng ảo trong " . (int) $this->option('duration') . ' giây…');
            $r = $this->load($base, $vus, $pages, (int) $this->option('duration'));
            $all = array_merge(...array_values(array_map(fn($x) => $x['ms'], $r['by']))) ?: [0];
            $q = self::pct($all);
            $md[] = "## Thử tải: $users người dùng đồng thời, " . (int) $this->option('duration') . ' giây';
            $md[] = '';
            $md[] = sprintf('Tổng %d yêu cầu (%.1f yêu cầu / giây) · lỗi máy chủ (5xx, hết giờ) %d (%.2f%%) · bị giới hạn tốc độ (429) %d · p50 %d ms · p95 %d ms · p99 %d ms.',
                $r['n'], $r['n'] / max(1, $r['secs']), $r['err'], 100 * $r['err'] / max(1, $r['n']), $r['limited'], $q[50], $q[95], $q[99]);
            $md[] = '';
            $md[] = '| Trang | Yêu cầu | p50 (ms) | p95 (ms) | Lỗi |';
            $md[] = '|---|---|---|---|---|';
            foreach ($r['by'] as $k => $x) {
                $qq = $x['ms'] ? self::pct($x['ms']) : null;
                $md[] = sprintf('| %s | %d | %s | %s | %d |', $pages[$k]['label'], count($x['ms']) + $x['err'], $qq ? $qq[50] : '–', $qq ? $qq[95] : '–', $x['err']);
            }
            $md[] = '';
        }
        foreach ($md as $l) {
            $this->line($l);
        }
        if ($p = $this->option('md')) {
            @mkdir(dirname(base_path($p)), 0775, true);
            file_put_contents(base_path($p), implode("\n", $md) . "\n");
            $this->info("Đã ghi $p");
        }

        return self::SUCCESS;
    }

    /** Trang đo và tỉ lệ chọn khi thử tải (ước theo cách dùng: xem tour, mở chuyến, tìm nơi, xem thông báo). */
    public static function pages(int $tour): array
    {
        return [
            'home' => ['label' => 'Trang chủ', 'path' => '/', 'auth' => false, 'json' => false, 'w' => 10],
            'tours' => ['label' => 'Danh sách tour', 'path' => '/tours', 'auth' => false, 'json' => false, 'w' => 15],
            'tour' => ['label' => 'Chi tiết tour', 'path' => '/tour-detail/' . $tour, 'auth' => false, 'json' => false, 'w' => 10],
            'trips' => ['label' => 'Chuyến đi của tôi', 'path' => '/len-ke-hoach', 'auth' => true, 'json' => false, 'w' => 10],
            'trip' => ['label' => 'Trang chuyến đi (lịch, kiểm tra lịch)', 'path' => '/len-ke-hoach/{trip}', 'auth' => true, 'json' => false, 'w' => 25],
            'search' => ['label' => 'Tìm nơi trong chuyến (JSON)', 'path' => '/len-ke-hoach/{trip}/tim?q=ca%20phe', 'auth' => true, 'json' => true, 'w' => 15],
            'events' => ['label' => 'Thông báo chuyến (JSON)', 'path' => '/len-ke-hoach/{trip}/thong-bao', 'auth' => true, 'json' => true, 'w' => 10],
            'rev' => ['label' => 'Phiên bản lịch (JSON, cập nhật cùng nhóm)', 'path' => '/len-ke-hoach/{trip}/phien-ban', 'auth' => true, 'json' => true, 'w' => 5],
        ];
    }

    /** @return list<array{uid:int,trip:int,cookie:string}> */
    private function accounts(int $n): array
    {
        $owner = DB::table('tbl_users')->where('username', 'demo_khach')->value('userId');
        $src = $owner ? DB::table('tbl_trips')->where('userId', $owner)->where('title', 'Đà Lạt 3 ngày')->first() : null;
        if (!$src) {
            return [];
        }
        $pass = PasswordHasher::make(Str::random(24));
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $name = sprintf('perf%02d', $i);
            DB::table('tbl_users')->updateOrInsert(['username' => $name], ['email' => $name . '@perf.travela.test', 'password' => $pass, 'fullName' => 'Thử tải ' . $i, 'isActive' => 'y']);
            $uid = (int) DB::table('tbl_users')->where('username', $name)->value('userId');
            $trip = (int) DB::table('tbl_trips')->where('userId', $uid)->where('sourceType', 'perf')->value('tripId');
            if (!$trip) {
                $trip = \App\Services\Study\StudySession::cloneTrip($src, $uid, 'perf');
            }
            $out[] = ['uid' => $uid, 'trip' => $trip, 'cookie' => $this->session($uid, $name)];
        }

        return $out;
    }

    /** Tạo phiên đăng nhập trong kho phiên, trả về chuỗi Cookie (đã mã hóa như EncryptCookies). */
    private function session(int $uid, string $username): string
    {
        $store = app('session')->driver();
        $store->setId(Str::random(40));
        $store->start();
        $store->put(['userId' => $uid, 'username' => $username, '_token' => Str::random(40)]);
        $store->save();
        $name = config('session.cookie');
        $val = Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()) . $store->getId(), false);

        return $name . '=' . rawurlencode($val);
    }

    /** @return array{0:int,1:float,2:int} mã HTTP, ms, số byte */
    private static function get(string $url, ?string $cookie, bool $json): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, self::opts($cookie, $json));
        $body = curl_exec($ch);
        $r = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, is_string($body) ? strlen($body) : 0];
        curl_close($ch);

        return $r;
    }

    private static function opts(?string $cookie, bool $json): array
    {
        $h = ['Accept: ' . ($json ? 'application/json' : 'text/html'), 'Accept-Encoding: gzip'];
        if ($json) {
            $h[] = 'X-Requested-With: XMLHttpRequest';
        }
        if ($cookie) {
            $h[] = 'Cookie: ' . $cookie;
        }

        return [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_ENCODING => ''];
    }

    /** Người dùng ảo chạy song song bằng curl_multi; mỗi người chỉ có một yêu cầu đang chờ tại một thời điểm. */
    private function load(string $base, array $vus, array $pages, int $secs): array
    {
        [$tmin, $tmax] = array_map('intval', explode('-', (string) $this->option('think')) + [1 => 3000]);
        $wheel = [];
        foreach ($pages as $k => $p) {
            $wheel = array_merge($wheel, array_fill(0, $p['w'], $k));
        }
        mt_srand(17);
        $mh = curl_multi_init();
        $state = [];
        $by = array_map(fn() => ['ms' => [], 'err' => 0], $pages);
        $n = $err = $limited = 0;
        $t0 = microtime(true);
        $end = $t0 + $secs;
        foreach ($vus as $i => $vu) {
            $state[$i] = ['next' => $t0 + mt_rand(0, $tmax) / 1000, 'h' => null, 'k' => null];
        }
        while (true) {
            $now = microtime(true);
            $busy = false;
            foreach ($state as $i => &$s) {
                if ($s['h']) {
                    $busy = true;
                } elseif ($now < $end && $now >= $s['next']) {
                    $k = $wheel[mt_rand(0, count($wheel) - 1)];
                    $p = $pages[$k];
                    $ch = curl_init($base . str_replace('{trip}', (string) $vus[$i]['trip'], $p['path']));
                    curl_setopt_array($ch, self::opts($p['auth'] ? $vus[$i]['cookie'] : null, $p['json']));
                    curl_multi_add_handle($mh, $ch);
                    $s['h'] = $ch;
                    $s['k'] = $k;
                    $busy = true;
                }
            }
            unset($s);
            if (!$busy && $now >= $end) {
                break;
            }
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.01);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                foreach ($state as $i => &$s) {
                    if ($s['h'] === $ch) {
                        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
                        $n++;
                        if ($code === 429) {
                            $limited++;
                        } elseif ($code >= 200 && $code < 300) {
                            $by[$s['k']]['ms'][] = $ms;
                        } else {
                            $err++;
                            $by[$s['k']]['err']++;
                        }
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                        $s['h'] = null;
                        $s['next'] = microtime(true) + mt_rand($tmin, $tmax) / 1000;
                    }
                }
                unset($s);
            }
        }
        curl_multi_close($mh);

        return ['n' => $n, 'err' => $err, 'limited' => $limited, 'by' => $by, 'secs' => microtime(true) - $t0];
    }

    /** @return array{50:int,95:int,99:int} */
    public static function pct(array $x): array
    {
        sort($x);
        $at = fn(float $p) => (int) round($x[min(count($x) - 1, (int) ceil($p * count($x)) - 1)]);

        return [50 => $at(.5), 95 => $at(.95), 99 => $at(.99)];
    }
}
