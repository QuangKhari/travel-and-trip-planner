<?php

namespace App\Console\Commands;

use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\RouteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra trước khi demo / triển khai: CSDL có đủ bảng, cột mà Trip Planner cần không; cấu hình tuyến đang dùng gì.
 *   php artisan planner:doctor
 * Thiếu thứ gì thì in ra và trả mã lỗi (chạy lại `php artisan migrate`). Không sửa gì.
 */
class PlannerDoctor extends Command
{
    protected $signature = 'planner:doctor {--osrm : thử gọi máy chủ OSRM đang cấu hình}';

    protected $description = 'Kiểm tra CSDL và cấu hình của Trip Planner (bảng, cột, migration, nguồn tuyến)';

    /** bảng => cột bắt buộc (theo các migration 2026_11_09 ... 2026_11_17) */
    public const REQUIRED = [
        'tbl_trips' => ['tripId', 'userId', 'title', 'startDate', 'days', 'groupSize', 'budget', 'status', 'destinationId', 'shareToken', 'source', 'rainPlan', 'rev', 'lastEditorId'],
        'tbl_trip_items' => ['itemId', 'tripId', 'refType', 'refId', 'dayNo', 'orderNo', 'note', 'cost', 'slot', 'label', 'reasons', 'durationMin', 'startMin', 'costType', 'isLocked', 'latitude', 'longitude', 'source'],
        'tbl_trip_members' => ['tripId', 'userId', 'role'],
        'tbl_opening_hours' => ['refType', 'refId', 'dayOfWeek', 'openMin', 'closeMin', 'isClosed'],
        'tbl_content_requests' => ['folded', 'sample', 'kind', 'hits'],
        'tbl_osm_snapshots' => ['snapKey', 'cat', 'latitude', 'longitude', 'radiusM', 'payload', 'fetchedAt'],
    ];

    /** @return list<string> các thiếu sót, rỗng = đủ */
    public static function missing(): array
    {
        $out = [];
        foreach (self::REQUIRED as $table => $cols) {
            if (!Schema::hasTable($table)) {
                $out[] = "Thiếu bảng $table";
                continue;
            }
            foreach ($cols as $c) {
                if (!Schema::hasColumn($table, $c)) {
                    $out[] = "Thiếu cột $table.$c";
                }
            }
        }

        return $out;
    }

    public function handle(): int
    {
        $this->info('Trip Planner — kiểm tra CSDL');
        $rows = [];
        foreach (self::REQUIRED as $table => $cols) {
            $has = Schema::hasTable($table);
            $miss = $has ? array_values(array_filter($cols, fn($c) => !Schema::hasColumn($table, $c))) : $cols;
            $rows[] = [$table, $has ? number_format(DB::table($table)->count(), 0, ',', '.') : '—', $miss ? '<fg=red>thiếu: ' . implode(', ', $miss) . '</>' : '<fg=green>đủ ' . count($cols) . ' cột</>'];
        }
        $this->table(['Bảng', 'Số dòng', 'Cột'], $rows);

        $pending = [];
        if (Schema::hasTable('migrations')) {
            $ran = DB::table('migrations')->pluck('migration')->all();
            foreach (glob(database_path('migrations/2026_11_*.php')) as $f) {
                $name = basename($f, '.php');
                if (!in_array($name, $ran, true)) {
                    $pending[] = $name;
                }
            }
        }
        if ($pending) {
            $this->warn('Migration Planner chưa chạy: ' . implode(', ', $pending));
        }

        $r = PlannerRules::fromConfig();
        $route = $r->route();
        $this->line('Ngày: ' . sprintf('%02d:00', intdiv($r->dayStart(), 60)) . ' – ' . sprintf('%02d:00', intdiv($r->softEnd(), 60))
            . ' (sức chứa ' . $r->availableMin() . ' phút), muộn sau ' . sprintf('%02d:00', intdiv($r->dayEnd(), 60)));
        $this->line('Tuyến: ' . $route['provider'] . ' · ' . $route['mode'] . ' · ' . $route['speedKmh'] . ' km/h · hệ số ' . $route['factor']
            . ($route['provider'] === 'osrm' ? ' · ' . $route['osrmUrl'] : ''));
        $snaps = Schema::hasTable('tbl_osm_snapshots') ? DB::table('tbl_osm_snapshots')->count() : 0;
        $this->line('Dịch vụ ngoài: ' . (config('planner.external.enabled', true) ? 'bật' : '<fg=yellow>TẮT (PLANNER_EXTERNAL=off)</>')
            . ' · bản chụp OpenStreetMap đã nạp: ' . $snaps . ($snaps ? '' : ' <fg=yellow>(chạy php artisan planner:warm-osm để demo không phụ thuộc mạng)</>'));
        foreach (['overpass', 'geocoder', 'osrm'] as $svc) {
            if ($why = \App\Services\Planner\ExternalGuard::reason($svc)) {
                $this->warn("  $svc đang tạm ngắt: $why");
            }
        }
        if ($this->option('osrm')) {
            $svc = RouteService::make();
            $svc->distanceMatrix([[21.0278, 105.8342], [21.0285, 105.8522]]);
            $st = $svc->status();
            $st['fallback'] ? $this->warn('OSRM: không dùng được (' . ($st['reason'] ?? 'không rõ') . '), Planner đang dùng ước tính.')
                : $this->info('OSRM: trả lời bình thường (' . $st['actual'] . ').');
        }

        $miss = self::missing();
        if ($miss || $pending) {
            $this->error('Chưa sẵn sàng. Chạy: php artisan migrate');

            return self::FAILURE;
        }
        $this->info('CSDL đủ bảng / cột cho Trip Planner.');

        return self::SUCCESS;
    }
}
