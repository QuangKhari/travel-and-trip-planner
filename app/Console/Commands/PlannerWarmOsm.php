<?php

namespace App\Console\Commands;

use App\Services\Planner\PlaceFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nạp sẵn khách sạn / cà phê / nhà hàng / bar / chợ quanh điểm đến vào CSDL (tbl_osm_snapshots), để trang lập kế hoạch
 * không phụ thuộc mạng lúc demo.
 *
 * Mặc định chỉ chạy cho các điểm đến LÕI (config core_destinations.slugs) và bỏ qua mục đã có bản chụp còn mới,
 * nên lần đầu khoảng 30 lượt gọi (vài phút), các lần sau gần như tức thì. Mạng hỏng liên tiếp 3 lần thì dừng sớm.
 *   php artisan planner:warm-osm                     # điểm đến lõi, bỏ qua mục đã có
 *   php artisan planner:warm-osm --slug=hoi-an       # một điểm đến (kể cả không phải lõi)
 *   php artisan planner:warm-osm --all               # mọi điểm đến đang bật (lâu)
 *   php artisan planner:warm-osm --force             # nạp lại cả mục đã có
 */
class PlannerWarmOsm extends Command
{
    protected $signature = 'planner:warm-osm
        {--slug= : Chỉ một điểm đến}
        {--cat= : hotel|restaurant|cafe|bar|market|sight (mặc định: hotel, cafe, restaurant, bar, market)}
        {--km=3 : Bán kính (km), nên để 3 cho khớp bán kính mặc định của trang lập kế hoạch}
        {--all : Mọi điểm đến đang bật, không chỉ điểm đến lõi}
        {--force : Nạp lại cả mục đã có bản chụp}
        {--timeout=40 : Giây máy chủ Overpass được xử lý mỗi lần (client chờ thêm 10 giây)}
        {--pause=5 : Giây nghỉ giữa hai lần gọi thành công}
        {--max-fail=4 : Lỗi liên tiếp bao nhiêu lần thì dừng}
        {--fail-pause=15 : Giây nghỉ sau một lần lỗi}
        {--photon : Nạp nhanh bằng Photon (~1 giây/lượt) thay cho Overpass (chậm, hay quá tải nhưng đủ thẻ hơn)}';

    protected $description = 'Nạp sẵn khách sạn/quán cà phê/nhà hàng... từ OpenStreetMap quanh điểm đến lõi vào CSDL, để trang lập kế hoạch không phụ thuộc mạng lúc demo';

    public function handle(PlaceFinder $finder): int
    {
        $cats = $this->option('cat') ? [(string) $this->option('cat')] : ['hotel', 'cafe', 'restaurant', 'bar', 'market'];
        $q = DB::table('tbl_destinations')->where('isActive', 1)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('destinationId');
        if ($slug = $this->option('slug')) {
            $q->where('slug', $slug);
        } elseif (!$this->option('all') && ($core = (array) config('core_destinations.slugs', []))) {
            $q->whereIn('slug', $core);
        }
        $rows = $q->get(['slug', 'name', 'latitude', 'longitude']);
        if ($rows->isEmpty()) {
            $this->error('Không có điểm đến nào (cần có tọa độ).');

            return self::FAILURE;
        }
        $radiusM = (int) round(max(0.5, (float) $this->option('km')) * 1000);
        $radius = (int) (ceil($radiusM / 500) * 500);
        $timeout = max(5, (int) $this->option('timeout'));
        $total = $rows->count() * count($cats);
        $this->info("Nạp {$rows->count()} điểm đến × " . count($cats) . " nhóm = {$total} mục (bán kính {$this->option('km')} km)." . ($this->option('all') || $this->option('slug') ? '' : ' Chỉ điểm đến lõi; thêm --all để nạp tất cả.'));

        $ok = $skip = $fail = $streak = $i = 0;
        $t0 = microtime(true);
        foreach ($rows as $d) {
            foreach ($cats as $cat) {
                $i++;
                $label = sprintf('[%d/%d] %s / %s', $i, $total, $d->slug, $cat);
                if (!$this->option('force')) {
                    [$list, $age] = PlaceFinder::snapshot($cat, round((float) $d->latitude, 3), round((float) $d->longitude, 3), $radius);
                    if ($list !== null && $age !== null && $age < 30 * 86400) {
                        $skip++;
                        $this->line("<fg=gray>· $label: đã có " . count($list) . ' mục, bỏ qua</>');
                        continue;
                    }
                }
                $t = microtime(true);
                $r = $this->option('photon')
                    ? $finder->warmPhoton($cat, (float) $d->latitude, (float) $d->longitude, $radiusM)
                    : $finder->warmOsm($cat, (float) $d->latitude, (float) $d->longitude, $radiusM, $timeout, fn($m) => $this->line("<fg=gray>$m</>"));
                [$good, $n, $why] = $r;
                $usedKm = isset($r[3]) ? (int) round($r[3] / 1000) : null;
                $sec = (int) round(microtime(true) - $t);
                if ($good && $n === 0) {
                    // máy chủ trả lời bình thường, chỉ là quanh đây không có: không phải lỗi, không nghỉ
                    $streak = 0;
                    $this->line("<fg=gray>· $label: không có địa điểm nào" . ($usedKm ? " trong {$usedKm} km" : '') . ' (kiểm tra tọa độ điểm đến)</>');
                    continue;
                }
                if ($good) {
                    $ok++;
                    $streak = 0;
                    $this->line("<fg=green>✓</> $label: {$n} mục" . ($usedKm && $usedKm * 1000 > $radiusM ? " <fg=yellow>(nới tới {$usedKm} km)</>" : '') . " <fg=gray>({$sec} giây)</>");
                } else {
                    $fail++;
                    $streak++;
                    $this->line("<fg=red>✗</> $label: <fg=yellow>{$why}</> <fg=gray>({$sec} giây)</>");
                    if ($streak >= (int) $this->option('max-fail')) {
                        $this->warn('Lỗi ' . $streak . ' lần liên tiếp: máy chủ ' . ($this->option('photon') ? 'Photon' : 'Overpass') . ' đang quá tải hoặc chặn. Dừng sớm; để 15–30 phút rồi chạy lại (mục đã nạp sẽ được bỏ qua).');
                        break 2;
                    }
                    if ($fp = max(0, (int) $this->option('fail-pause'))) {
                        $this->line("<fg=gray>   nghỉ {$fp} giây cho máy chủ hồi lại…</>");
                        sleep($fp);
                    }
                    continue;
                }
                sleep(max(0, (int) $this->option('pause')));   // máy chủ công cộng chỉ cho vài yêu cầu cùng lúc mỗi IP: đừng gọi dồn
            }
        }
        $this->info(sprintf('Xong sau %d giây: %d nạp mới, %d đã có, %d lỗi.', (int) round(microtime(true) - $t0), $ok, $skip, $fail));

        return $ok + $skip > 0 ? self::SUCCESS : self::FAILURE;
    }
}
