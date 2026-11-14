<?php

namespace Database\Seeders;

use App\Services\Planner\OpeningHours;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gói dữ liệu 3 điểm đến demo (Hà Nội, Hội An, Đà Lạt; 40 nơi mỗi chỗ): giờ mở cửa, giá vé, trong nhà / ngoài trời, thời gian tham quan.
 *   php artisan db:seed --class=DemoCityPackSeeder
 * Chạy lại an toàn: nơi đã có (cùng điểm đến, cùng tên) chỉ được bổ sung cột còn trống và giờ mở cửa còn thiếu; không ghi đè dữ liệu đã duyệt.
 * Số liệu là tham khảo (xem _note trong data/demo_city_pack.json). Kiểm tra độ phủ: php artisan planner:coverage
 */
class DemoCityPackSeeder extends Seeder
{
    public function run(): void
    {
        $pack = json_decode((string) file_get_contents(__DIR__ . '/data/demo_city_pack.json'), true);
        $hours = app(OpeningHours::class);
        $extra = Schema::hasColumn('tbl_external_places', 'durationMinutes');
        $eat = (array) config('planner.kind_groups.eat', []);
        foreach ($pack['cities'] as $destId => $rows) {
            if (!DB::table('tbl_destinations')->where('destinationId', (int) $destId)->exists()) {
                $this->command?->warn("  bỏ qua điểm đến #$destId: không có trong CSDL");
                continue;
            }
            $new = $filled = $timed = 0;
            $published = $held = [];
            foreach ($rows as $r) {
                $x = array_combine($pack['cols'], $r);
                $fields = $extra ? ['durationMinutes' => $x['dur'], 'priceMin' => $x['pmin'], 'priceMax' => $x['pmax'], 'indoor' => $x['indoor']] : [];
                $row = DB::table('tbl_external_places')->where('destinationId', (int) $destId)->where('name', $x['name'])->first();
                if ($row) {
                    // chỉ điền chỗ trống: dữ liệu admin đã nhập / đã duyệt được giữ nguyên
                    $patch = array_filter($fields, fn($v, $k) => $v !== null && (!property_exists($row, $k) || $row->$k === null), ARRAY_FILTER_USE_BOTH);
                    // nơi đã có nhưng còn nháp và CHƯA ai duyệt (vd. đồng bộ OSM): gói demo xuất bản luôn; nơi admin đã duyệt / từ chối thì để nguyên
                    if ($row->reviewStatus !== 'published' && empty($row->reviewedBy)) {
                        $patch += ['reviewStatus' => 'published'];
                        $published[] = $x['name'];
                    } elseif ($row->reviewStatus !== 'published') {
                        $held[] = $x['name'];
                    }
                    if ($patch) {
                        DB::table('tbl_external_places')->where('placeId', $row->placeId)->update($patch);
                        $filled++;
                    }
                    $id = (int) $row->placeId;
                } else {
                    $id = (int) DB::table('tbl_external_places')->insertGetId([
                        'destinationId' => (int) $destId, 'source' => 'demo', 'externalId' => 'demo-' . substr(md5($destId . '|' . $x['name']), 0, 16),
                        'name' => $x['name'], 'category' => in_array($x['kind'], $eat, true) ? 'food' : 'sight', 'kind' => $x['kind'],
                        'latitude' => $x['lat'], 'longitude' => $x['lng'], 'rank' => (int) $x['rank'], 'isMustTry' => (int) $x['must'],
                        'reviewStatus' => 'published', 'syncedAt' => now(),
                    ] + $fields, 'placeId');
                    $new++;
                }
                if ($x['hours'] && !DB::table('tbl_opening_hours')->where('refType', 'place')->where('refId', $id)->exists()) {
                    $hours->store('place', $id, $x['hours'], 'demo');
                    $timed++;
                }
            }
            $this->command?->line("  #$destId: thêm $new nơi, bổ sung $filled nơi, nạp giờ mở cửa cho $timed nơi");
            if ($published) {
                $this->command?->line('    xuất bản ' . count($published) . ' nơi đang nháp: ' . implode(', ', $published));
            }
            if ($held) {
                $this->command?->warn('    ' . count($held) . ' nơi đã được admin duyệt nhưng chưa xuất bản, giữ nguyên (duyệt trong trang quản trị nếu muốn dùng): ' . implode(', ', $held));
            }
        }
    }
}
