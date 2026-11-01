<?php

namespace App\Console\Commands;

use App\Services\Planner\Health\DaySchedule;
use App\Services\Planner\OpeningHours;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\PlannerService;
use App\Services\Planner\Route\RouteService;
use Illuminate\Console\Command;

/**
 * Chạy MỘT kịch bản tự đề xuất trên mọi điểm đến lõi để kiểm tra trước buổi bảo vệ:
 * có ngày nào thưa (độ dày dưới mức tối thiểu của nhịp đi, min_load) hay quá dày (DaySchedule chấm "Bận" / "Quá tải") không.
 * Mặc định là kịch bản trong nhận xét: 4 ngày, 2 người, 5 triệu, ẩm thực + văn hóa, không trekking.
 *
 *   php artisan planner:scenario
 *   php artisan planner:scenario --days=3 --adults=2 --children=1 --budget=3000000 --interests=nature --start=2026-12-12
 *
 * Không lưu gì. Mã thoát 1 nếu có ngày thưa / quá tải ở điểm đến đã sẵn sàng (dùng được trong CI).
 */
class PlannerScenario extends Command
{
    protected $signature = 'planner:scenario
        {--days=4} {--adults=2} {--children=0} {--budget=5000000} {--interests=food,culture} {--dislikes=trekking}
        {--pace=normal : relaxed|normal|packed} {--month= : mặc định tháng sau} {--start= : ngày đi Y-m-d (bật kiểm tra giờ mở cửa)}
        {--detail : in từng mục của từng ngày}';

    protected $description = 'Chạy một kịch bản tự đề xuất trên mọi điểm đến lõi, báo ngày thưa / quá dày (không lưu gì)';

    public function handle(PlannerService $svc, PlannerGate $gate): int
    {
        $csv = fn($v) => array_values(array_filter(array_map('trim', explode(',', (string) $v))));
        $start = $this->option('start') ?: null;
        $month = $start ? (int) date('n', strtotime($start)) : (int) ($this->option('month') ?: (int) now()->addMonth()->month);
        $req = [
            'month' => $month, 'days' => (int) $this->option('days'), 'adults' => (int) $this->option('adults'), 'children' => (int) $this->option('children'),
            'budget' => $this->option('budget') !== null && $this->option('budget') !== '' ? (float) $this->option('budget') : null,
            'interests' => $csv($this->option('interests')), 'dislikes' => $csv($this->option('dislikes')), 'pace' => $this->option('pace'), 'startDate' => $start,
        ];
        $rules = PlannerRules::fromConfig();
        $route = RouteService::make();
        $minLoad = (float) (config('planner.pace.' . $req['pace'] . '.min_load') ?? 0.45);
        $maxLoad = (float) (config('planner.pace.' . $req['pace'] . '.target_load') ?? 0.8);

        $this->info(sprintf('Kịch bản: %d ngày · %d người lớn%s · ngân sách %s · sở thích %s · không thích %s · nhịp %s · %s',
            $req['days'], $req['adults'], $req['children'] ? ' + ' . $req['children'] . ' trẻ em' : '', $req['budget'] ? number_format($req['budget'], 0, ',', '.') . 'đ' : 'không',
            implode('+', $req['interests']) ?: '—', implode('+', $req['dislikes']) ?: '—', $req['pace'], $start ? 'từ ' . $start : 'tháng ' . $month));

        $rows = [];
        $problems = 0;
        foreach ($gate->coreStatus() as $d) {
            if (!$d['ready']) {
                $rows[] = [$d['name'], '<fg=yellow>chưa sẵn sàng</>', '—', '—', mb_strimwidth(implode('; ', $d['missing']), 0, 90, '…')];
                continue;
            }
            $res = $svc->generate($req + ['destinationId' => $d['destinationId']]);
            if (empty($res['ok'])) {
                $rows[] = [$d['name'], '<fg=red>lỗi</>', '—', '—', $res['message'] ?? ''];
                $problems++;
                continue;
            }
            $cells = [];
            $flags = [];
            foreach ($res['plan']['days'] as $day) {
                $items = array_map(fn($it) => ['type' => $it['type'], 'refId' => $it['refId'], 'name' => $it['name'], 'kind' => $it['kind'] ?? null,
                    'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null, 'slot' => $it['slot'], 'durationMin' => $it['durationMin'], 'hours' => $it['hours'] ?? null], $day['items']);
                $sch = new DaySchedule($day['day'], $items, $route, $rules, OpeningHours::dowOf($start, $day['day']));
                $acts = count(array_filter($day['items'], fn($i) => empty($i['isMeal'])));
                $pct = (int) round($sch->load() * 100);
                $cells[] = 'N' . $day['day'] . ': ' . $acts . ' hđ, ' . $pct . '%';
                if ($day['items'] && $sch->load() < $minLoad) {
                    $flags[] = 'N' . $day['day'] . ' thưa';
                } elseif (!$day['items']) {
                    $flags[] = 'N' . $day['day'] . ' trống';
                }
                // "Bận" là chủ ý khi chọn nhịp dày; chỉ báo khi vượt mức mục tiêu của nhịp hoặc quá tải
                if ($sch->load() >= 1 || $sch->load() > $maxLoad + 0.03) {
                    $flags[] = 'N' . $day['day'] . ' ' . $rules->loadLabel($sch->load());
                }
                if ($this->option('detail')) {
                    $this->line("<options=bold>{$d['name']} · Ngày {$day['day']}</> (" . $rules->loadLabel($sch->load()) . " {$pct}%)");
                    foreach ($sch->rows as $r) {
                        $this->line(sprintf('   %02d:%02d  %-40s %s', intdiv($r['start'], 60), $r['start'] % 60, mb_strimwidth($r['item']['name'], 0, 40, '…'), $r['travelMin'] ? '→ ' . $r['travelMin'] . ' phút' : ''));
                    }
                }
            }
            $problems += $flags ? 1 : 0;
            $b = $res['plan']['budget'];
            $rows[] = [$d['name'], $flags ? '<fg=yellow>' . implode(', ', $flags) . '</>' : '<fg=green>ổn</>', implode(' · ', $cells),
                number_format($b['knownMin'], 0, ',', '.') . 'đ (' . $b['status'] . ')', count($res['plan']['unplaced']) . ' vào "Đã lưu", ' . count($res['plan']['filterLog']) . ' bị loại'];
        }
        $this->table(['Điểm đến', 'Kết luận', 'Từng ngày (hoạt động, tải)', 'Giá đã biết', 'Ghi chú'], $rows);
        $this->line('Tải = (hoạt động + di chuyển + bữa ăn chừa sẵn + đệm) / ' . $rules->availableMin() . ' phút, cùng công thức với "Sức khỏe chuyến đi".');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
