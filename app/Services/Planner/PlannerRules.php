<?php

namespace App\Services\Planner;

/**
 * Quy tắc nghiệp vụ của một ngày Planner, đọc từ MỘT nơi (config/planner.php) và dùng chung cho:
 *  - máy chủ: DaySchedule / TripHealth, TripPlanner::payload(), PlannerEngine, RouteService, kiểm tra lịch trình mẫu;
 *  - trình duyệt: client() được nhúng vào trang sửa (cfg.rules), planner-schedule.js và planner-geo.js đọc từ đó,
 *    không khai báo lại hằng số nào.
 *
 * Ba ràng buộc thời gian của một mục là ba trường riêng, không gộp:
 *  - FIXED  = startMin   (giờ bắt đầu cố định)
 *  - SLOT   = slot       (không bắt đầu trước đầu buổi)
 *  - LOCKED = locked     (giữ vị trí/thứ tự khi tối ưu, cân bằng)
 *
 * Lớp thuần: nhận mảng cấu hình nên chạy được trong Unit test không có Laravel.
 */
final class PlannerRules
{
    /** @param array<string,mixed> $cfg nội dung config('planner') */
    public function __construct(private array $cfg) {}

    public static function fromConfig(): self
    {
        return new self((array) config('planner'));
    }

    // ---------------------------------------------------------------- một ngày

    public function dayStart(): int
    {
        return (int) ($this->cfg['day']['start_min'] ?? 480);
    }

    /** Hết khung hoạt động chính; sức chứa tính từ dayStart() tới đây. */
    public function softEnd(): int
    {
        return (int) ($this->cfg['day']['soft_end_min'] ?? 1080);
    }

    /** Kết thúc sau giờ này là muộn. */
    public function dayEnd(): int
    {
        return (int) ($this->cfg['day']['end_min'] ?? 1320);
    }

    /** Sức chứa một ngày (phút) = softEnd - dayStart. */
    public function availableMin(): int
    {
        return max(60, $this->softEnd() - $this->dayStart());
    }

    public function mealAllowanceMin(): int
    {
        return (int) ($this->cfg['meal_allowance_min'] ?? 60);
    }

    public function bufferRatio(): float
    {
        return (float) ($this->cfg['buffer_ratio'] ?? 0.1);
    }

    /** Số mục một ngày từ mức này trở lên là "hơi nhiều" (dù độ dày chưa vượt). */
    public function itemsWarn(): int
    {
        return (int) ($this->cfg['day_items_warn'] ?? 7);
    }

    public function travelWarnMin(): int
    {
        return (int) ($this->cfg['travel_warn_min'] ?? 180);
    }

    public function loadWarn(): float
    {
        return (float) ($this->cfg['load_warn'] ?? 0.85);
    }

    /**
     * Tổng thời lượng các mục (kể cả bữa ăn) vừa sức chứa một ngày khi còn đệm và chừa sẵn thời gian đi lại.
     * = floor((availableMin / (1 + buffer) - travel_reserve) / 10) * 10. Với cấu hình mặc định: 480 phút.
     */
    public function itemBudgetMin(): int
    {
        $raw = $this->availableMin() / (1 + $this->bufferRatio()) - (int) ($this->cfg['travel_reserve_min'] ?? 60);

        return max(60, (int) (floor($raw / 10) * 10));
    }

    /** Độ dày = (hoạt động + di chuyển + bữa ăn chừa sẵn + đệm) / sức chứa. */
    public function occupied(int $activityMin, int $travelMin, bool $hasMeal, bool $hasItems): array
    {
        $meal = $hasItems && !$hasMeal ? $this->mealAllowanceMin() : 0;
        $buffer = (int) round(($activityMin + $travelMin) * $this->bufferRatio());

        return ['meal' => $meal, 'buffer' => $buffer, 'occupied' => $activityMin + $travelMin + $meal + $buffer];
    }

    /** Nhãn dễ hiểu của độ dày: Nhẹ (< 50%) · Vừa phải (< mức cảnh báo) · Bận (< 100%) · Quá tải. */
    public function loadLabel(float $load): string
    {
        return $load >= 1 ? 'Quá tải' : ($load >= $this->loadWarn() ? 'Bận' : ($load >= 0.5 ? 'Vừa phải' : 'Nhẹ'));
    }

    public function level(float $load): string
    {
        return $load >= 1 ? 'bad' : ($load >= $this->loadWarn() ? 'warn' : 'ok');
    }

    // ---------------------------------------------------------------- buổi

    /** @return array<string,array{label:string,start:int,end:int}> */
    public function slots(): array
    {
        $out = [];
        foreach ((array) ($this->cfg['slots'] ?? []) as $k => $s) {
            $out[$k] = ['label' => (string) ($s['label'] ?? $k), 'start' => (int) $s['start'], 'end' => (int) $s['end']];
        }

        return $out;
    }

    public function slotStart(?string $slot): ?int
    {
        return $slot !== null && isset($this->slots()[$slot]) ? $this->slots()[$slot]['start'] : null;
    }

    /** Giờ (phút kể từ 00:00) thuộc buổi nào. Trước buổi đầu tiên tính là buổi đầu, từ buổi cuối trở đi là buổi cuối. */
    public function slotOf(int $min): string
    {
        $slots = $this->slots();
        foreach ($slots as $k => $s) {
            if ($min < $s['end']) {
                return $k;
            }
        }

        return (string) array_key_last($slots);
    }

    // ---------------------------------------------------------------- loại mục

    /** lodging | meal | experience | transport | null (mục tự thêm đoán theo tên). */
    public function kindOf(array $it): ?string
    {
        $t = $it['type'] ?? '';
        if ($t === 'food') {
            return 'meal';
        }
        if ($t === 'experience' || $t === 'transport') {
            return $t;
        }
        if ($t === 'place' && !empty($it['kind'])) {
            foreach (['meal', 'lodging'] as $k) {
                if (in_array((string) $it['kind'], (array) ($this->cfg['kind_places'][$k] ?? []), true)) {
                    return $k;
                }
            }
        }
        if ($t === 'note') {
            $txt = ($it['name'] ?? $it['label'] ?? '') . ' ' . ($it['sub'] ?? '');
            foreach (['lodging', 'meal'] as $k) {
                $p = $this->cfg['kind_patterns'][$k] ?? null;
                if ($p && preg_match('/' . str_replace('/', '\/', $p) . '/iu', $txt)) {
                    return $k;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- tuyến

    /** @return array{provider:string, mode:string, factor:float, speedKmh:float, minLegKm:float, osrmProfile:string, osrmUrl:string} */
    public function route(?string $mode = null): array
    {
        $r = (array) ($this->cfg['route'] ?? []);
        $modes = (array) ($r['modes'] ?? []);
        $mode = $mode !== null && isset($modes[$mode]) ? $mode : (string) ($r['mode'] ?? 'driving');
        $m = (array) ($modes[$mode] ?? ['speed_kmh' => 40, 'osrm' => 'driving']);
        $url = (string) ($r['osrm_url'] ?? '');

        return [
            'provider' => ($r['provider'] ?? 'haversine') === 'osrm' && $url !== '' ? 'osrm' : 'haversine',
            'mode' => $mode,
            'factor' => (float) ($r['factor'] ?? 1.3),
            'speedKmh' => (float) ($m['speed_kmh'] ?? 40),
            'minLegKm' => (float) ($r['min_leg_km'] ?? 0.5),
            'osrmProfile' => (string) ($m['osrm'] ?? 'driving'),
            'osrmUrl' => $url,
        ];
    }

    /** Ngưỡng quãng đi vòng (quanh tuyến): great / good / consider / far. */
    public function detourLevel(int $min): array
    {
        foreach ((array) ($this->cfg['detour_levels'] ?? []) as $l) {
            if ($l['max'] === null || $min <= (int) $l['max']) {
                return ['key' => $l['key'], 'label' => $l['label']];
            }
        }

        return ['key' => 'far', 'label' => 'Vòng xa'];
    }

    public function estimateNote(): string
    {
        $r = $this->route();
        if ($r['provider'] === 'osrm') {
            return 'Quãng đường và thời gian theo bản đồ đường bộ (OSRM); khi không gọi được dịch vụ thì dùng ước tính.';
        }

        return 'Quãng đường và thời gian là ước tính (đường chim bay x ' . $r['factor'] . ', tốc độ ' . rtrim(rtrim(number_format($r['speedKmh'], 1, '.', ''), '0'), '.') . ' km/h).';
    }

    // ---------------------------------------------------------------- gửi xuống trình duyệt

    /** Hợp đồng quy tắc cho planner-schedule.js / planner-geo.js. Đổi tên khóa ở đây thì phải đổi cả hai file JS. */
    public function client(): array
    {
        $r = $this->route();

        return [
            'dayStart' => $this->dayStart(),
            'softEnd' => $this->softEnd(),
            'dayEnd' => $this->dayEnd(),
            'availableMin' => $this->availableMin(),
            'itemBudgetMin' => $this->itemBudgetMin(),
            'mealAllowanceMin' => $this->mealAllowanceMin(),
            'bufferRatio' => $this->bufferRatio(),
            'loadWarn' => $this->loadWarn(),
            'travelWarnMin' => $this->travelWarnMin(),
            'itemsWarn' => $this->itemsWarn(),
            'slots' => $this->slots(),
            'kindPatterns' => (array) ($this->cfg['kind_patterns'] ?? []),
            'placeKinds' => (array) ($this->cfg['kind_places'] ?? []),
            'route' => ['provider' => $r['provider'], 'mode' => $r['mode'], 'factor' => $r['factor'], 'speedKmh' => $r['speedKmh'], 'minLegKm' => $r['minLegKm']],
            'estimateNote' => $this->estimateNote(),
        ];
    }
}
