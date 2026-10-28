<?php

namespace App\Console\Commands;

use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerService;
use Illuminate\Console\Command;

/**
 * Tuần 5: chạy Planner từ dòng lệnh (giao diện web ở tuần 6). In lịch trình, lý do chọn, nhật ký lọc và ngân sách.
 */
class PlannerGenerate extends Command
{
    protected $signature = 'planner:generate {slug? : Slug điểm đến}
        {--month= : Tháng đi 1-12 (bắt buộc)} {--days=3} {--group=2} {--budget=} {--pace=normal : relaxed|normal|packed}
        {--interests= : vd nature,food} {--dislikes= : vd trekking,crowds} {--max-difficulty= : easy|medium|hard} {--start= : Ngày đi Y-m-d}
        {--user= : Lưu thành chuyến đi của userId này} {--force : Bỏ qua cổng Planner-ready (chỉ để thử trên dữ liệu chưa đủ)}
        {--json : In JSON} {--log : In nhật ký lọc} {--gate : Chỉ in tình trạng Planner-ready của các điểm đến lõi}';

    protected $description = 'Planner tự đề xuất lịch trình cho một điểm đến (cổng Planner-ready, lọc, chấm điểm, gom ngày, ngân sách)';

    public function handle(PlannerService $svc, PlannerGate $gate): int
    {
        if ($this->option('gate')) {
            $rows = array_map(fn($s) => [$s['slug'], $s['percent'] . '%', $s['ready'] ? 'SẴN SÀNG' : 'chưa', implode('; ', $s['missing'])], $gate->coreStatus());
            $this->table(['slug', 'Coverage', 'Planner-ready', 'Còn thiếu'], $rows);

            return self::SUCCESS;
        }
        if (!$this->argument('slug') || !$this->option('month')) {
            $this->error('Cần slug và --month=N (hoặc dùng --gate để xem tình trạng Planner-ready).');

            return self::FAILURE;
        }
        $csv = fn($v) => array_values(array_filter(array_map('trim', explode(',', (string) $v))));
        $res = $svc->generate([
            'slug' => $this->argument('slug'), 'month' => $this->option('month'), 'days' => $this->option('days'), 'groupSize' => $this->option('group'),
            'budget' => $this->option('budget'), 'pace' => $this->option('pace'), 'interests' => $csv($this->option('interests')),
            'dislikes' => $csv($this->option('dislikes')), 'maxDifficulty' => $this->option('max-difficulty'), 'startDate' => $this->option('start'),
        ], (bool) $this->option('force'));

        if (!$res['ok']) {
            $this->error($res['message']);

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->render($res);
        }
        if ($uid = (int) $this->option('user')) {
            $this->info('Đã lưu thành chuyến đi #' . $svc->save($uid, $res) . ' cho user ' . $uid . '.');
        }

        return self::SUCCESS;
    }

    private function render(array $res): void
    {
        $p = $res['plan'];
        $this->info($res['destination']['name'] . ' · ' . $res['request']['days'] . ' ngày · tháng ' . $res['request']['month']);
        if ($res['verdict']) {
            $this->line($res['verdict']);
        }
        $slotName = ['morning' => 'Sáng', 'noon' => 'Trưa', 'afternoon' => 'Chiều', 'evening' => 'Tối'];
        foreach ($p['days'] as $d) {
            $this->newLine();
            $this->line(sprintf('<options=bold>Ngày %d</> (hoạt động %d phút, di chuyển ~%d phút, ~%d km)', $d['day'], $d['activityMin'], $d['travelMin'], $d['km']));
            foreach ($d['items'] as $i) {
                $this->line(sprintf('  %s %02d:%02d  [%s] %s%s', str_pad($slotName[$i['slot']], 6), 8 + intdiv($i['startMin'], 60), $i['startMin'] % 60, $i['type'], $i['name'],
                    $i['reasons'] ? '  — ' . implode('; ', $i['reasons']) : ''));
            }
        }
        $this->newLine();
        foreach ($p['warnings'] as $w) {
            $this->warn($w);
        }
        $this->line('Ngân sách [' . $p['budget']['status'] . ']: ' . $p['budget']['message']);
        if ($res['tours']) {
            $this->line('Tour khởi hành phù hợp (không tính vào chi phí chuyến): ' . implode(' | ', array_map(fn($t) => $t['title'] . ' ' . $t['start'], $res['tours'])));
        }
        $this->line(sprintf('Ứng viên %d, giữ %d, chọn %d, loại %d.', $p['stats']['candidates'], $p['stats']['kept'], $p['stats']['selected'], $p['stats']['filtered']));
        if ($this->option('log')) {
            $this->table(['loại', 'mã', 'mục', 'lý do'], array_map(fn($l) => [$l['type'], $l['code'], $l['name'], $l['reason']], $p['filterLog']));
        }
        $this->line($res['disclaimer']);
    }
}
