<?php

namespace App\Console\Commands;

use App\Support\AssetBundle;
use App\Support\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra trước buổi trình bày (Đợt D mục D9): hệ thống, cấu hình, dữ liệu lõi, tài khoản demo, gói CSS/JS.
 *   php artisan demo:check
 * Dòng ✗ phải sửa trước khi demo; dòng ! nên xem lại; ✓ là ổn. Mã thoát 1 khi còn ✗.
 */
class DemoCheck extends Command
{
    protected $signature = 'demo:check';

    protected $description = 'Kiểm tra mọi thứ cần cho buổi demo';

    private array $rows = [];
    private int $fails = 0;

    private function row(string $group, string $name, string $state, string $detail): void
    {
        $this->rows[] = [$group, $name, ['ok' => '✓', 'warn' => '!', 'bad' => '✗'][$state], $detail];
        $this->fails += $state === 'bad' ? 1 : 0;
    }

    public function handle(): int
    {
        // 1. Hệ thống
        foreach (SystemHealth::check()['checks'] as $name => $c) {
            $this->row('Hệ thống', $name, $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn'), $c['detail'] . " ({$c['ms']} ms)");
        }

        // 2. Cấu hình
        $this->row('Cấu hình', 'APP_KEY', config('app.key') ? 'ok' : 'bad', config('app.key') ? 'có' : 'thiếu: php artisan key:generate');
        $keys = (array) config('travela.pii_keys', []);
        $enc = (string) ($keys[config('travela.pii_current_key', 'k1')] ?? '');
        $this->row('Cấu hình', 'DATA_ENCRYPTION_KEY', $enc !== '' ? 'ok' : 'bad', $enc !== '' ? 'có' : 'thiếu: php artisan pii:key');
        $this->row('Cấu hình', 'APP_DEBUG', config('app.debug') ? 'warn' : 'ok', config('app.debug') ? 'đang bật: lỗi sẽ hiện chi tiết cho người xem' : 'tắt');
        $url = (string) config('app.url');
        $this->row('Cấu hình', 'APP_URL', str_starts_with($url, 'https://') ? 'ok' : 'warn', $url . (str_starts_with($url, 'https://') ? '' : ' (chưa HTTPS: PWA, thông báo đẩy, thanh toán cần HTTPS)'));
        if (str_starts_with($url, 'https://')) {
            $this->row('Cấu hình', 'Cookie phiên chỉ qua HTTPS', config('session.secure') ? 'ok' : 'warn', config('session.secure') ? 'có' : 'SESSION_SECURE_COOKIE chưa bật');
        }
        // OWASP A02: mật khẩu MD5 cũ chỉ còn để nâng cấp khi người đó đăng nhập lại
        $md5 = \Illuminate\Support\Facades\DB::table('tbl_users')->whereRaw("password REGEXP '^[a-fA-F0-9]{32}$'")->count()
            + \Illuminate\Support\Facades\DB::table('tbl_admin')->whereRaw("passWord REGEXP '^[a-fA-F0-9]{32}$'")->count();
        $this->row('Bảo mật', 'Mật khẩu MD5 cũ', $md5 ? 'warn' : 'ok', $md5 ? "$md5 tài khoản (tự nâng lên bcrypt khi đăng nhập; tài khoản bỏ hoang nên đặt lại)" : 'không còn');
        if (config('study.enabled')) {
            $this->row('Cấu hình', 'Buổi thử (STUDY_ENABLED)', 'warn', 'đang bật: tắt sau buổi thử');
        }
        $this->row('Cấu hình', 'Gửi mail', config('mail.default') === 'log' ? 'warn' : 'ok', (string) config('mail.default'));
        $this->row('Cấu hình', 'Bản đồ', 'ok', config('services.google_maps.key') ? 'Google Maps' : 'OpenStreetMap / MapLibre (không cần khóa)');
        $this->row('Cấu hình', 'Liên kết ảnh (storage:link)', is_dir(public_path('storage')) ? 'ok' : 'warn', is_dir(public_path('storage')) ? 'có' : 'chạy php artisan storage:link');

        // 3. CSDL: migration chưa chạy
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = array_map(fn($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
            $pending = array_values(array_diff($files, $ran));
            $this->row('CSDL', 'Migration', $pending ? 'bad' : 'ok', $pending ? count($pending) . ' chưa chạy: php artisan migrate (vd. ' . $pending[0] . ')' : 'đủ ' . count($files));
        } catch (\Throwable $e) {
            $this->row('CSDL', 'Migration', 'bad', $e->getMessage());
        }

        // 4. Dữ liệu điểm đến lõi
        $minS = (int) config('core_destinations.min_sight', 15);
        $minF = (int) config('core_destinations.min_food', 8);
        $minI = (int) config('core_destinations.min_indoor', 0);
        foreach ((array) config('core_destinations.slugs', []) as $slug) {
            $d = DB::table('tbl_destinations')->where('slug', $slug)->first(['destinationId', 'slug', 'name', 'latitude', 'longitude']);
            if (!$d) {
                $this->row('Dữ liệu', $slug, 'bad', 'không có điểm đến này');
                continue;
            }
            $a = PlacesAudit::auditDestination($d);
            $heavy = count(array_filter($a['issues'], fn($i) => $i['level'] === 'nặng'));
            $state = ($a['sight'] >= $minS && $a['food'] >= $minF && $a['indoor'] >= $minI && !$heavy) ? 'ok' : (($a['sight'] + $a['food']) ? 'warn' : 'bad');
            $this->row('Dữ liệu', $d->name, $state, "{$a['sight']} tham quan, {$a['food']} ăn uống, {$a['indoor']} trong nhà, giờ {$a['pctHours']}%" . ($heavy ? ", $heavy lỗi nặng (places:audit)" : ''));
        }

        // 5. Tài khoản demo (database/seeders/DemoShowcaseSeeder.php)
        foreach ((array) config('demo.accounts', []) as $acc) {
            $table = $acc['type'] === 'admin' ? 'tbl_admin' : 'tbl_users';
            $col = $acc['type'] === 'admin' ? 'userName' : 'username';
            $ok = Schema::hasTable($table) && DB::table($table)->where($col, $acc['username'])->exists();
            $this->row('Demo', $acc['label'] ?? $acc['username'], $ok ? 'ok' : 'warn', $ok ? $acc['username'] : 'chưa có: php artisan demo:reset');
        }

        // 6. Gói CSS/JS
        $stale = array_values(array_filter(array_keys((array) config('assets.bundles')), fn($n) => AssetBundle::stale($n)));
        $this->row('Giao diện', 'Gói CSS/JS', $stale ? 'warn' : 'ok', $stale ? 'cũ: ' . implode(', ', $stale) . ' (php artisan assets:bundle)' : 'mới');

        $this->table(['Nhóm', 'Mục', '', 'Chi tiết'], $this->rows);
        $this->line($this->fails ? "<error>{$this->fails} mục phải sửa trước khi demo.</error>" : '<info>Sẵn sàng demo.</info>');

        return $this->fails ? self::FAILURE : self::SUCCESS;
    }
}
