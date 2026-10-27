<?php

namespace App\Console\Commands;

use App\Services\Content\CoverageHistory;
use App\Services\Content\CoverageScorer;
use Illuminate\Console\Command;

/** Bảng mức đủ nội dung. Dùng CoverageScorer, cùng một logic với trang Admin "Chất lượng nội dung". */
class ContentCoverage extends Command
{
    protected $signature = 'content:coverage {--demo : Chỉ 4 điểm đến demo trong demo_content.json} {--core : Chỉ các điểm đến lõi khai báo trong config/core_destinations.php} {--save : Lưu mốc hôm nay vào lịch sử Coverage}';

    protected $description = 'Bảng mức đủ nội dung của từng điểm đến: điểm %, Planner-ready, số lượng từng loại (dùng CoverageScorer)';

    public function handle(CoverageScorer $scorer, CoverageHistory $history): int
    {
        $slugs = null;
        if ($this->option('demo')) {
            $d = json_decode((string) @file_get_contents(database_path('seeders/data/demo_content.json')), true);
            $slugs = array_column($d['destinations'] ?? [], 'slug');
        }
        if ($this->option('core')) {
            $slugs = (array) config('core_destinations.slugs', []);
            if (!$slugs) {
                $this->error('config/core_destinations.php chưa khai báo điểm đến lõi (mảng slugs đang rỗng).');

                return self::FAILURE;
            }
        }

        $scores = $scorer->scoreAll($slugs ?: null);
        $rows = [];
        foreach ($scores as $s) {
            $n = $s['counts'];
            $rows[] = [
                $s['slug'],
                $s['percent'] . '%',
                $s['plannerReady'] ? 'ĐẠT' : 'chưa',
                $n['months'] . '/12',
                $n['places'] . ' (tọa độ ' . $n['placesCoords'] . ', ăn ' . $n['placesEat'] . ')',
                (string) $n['foods'],
                $n['experiences'] . ' (đủ độ khó+phút ' . $n['experiencesReady'] . ')',
                $n['events'] . ' (có ngày ' . $n['eventsDated'] . ')',
                (string) $n['itineraries'],
                (string) $n['tours'],
                $s['hasCover'] ? ($s['hasCredit'] ? 'có' : 'thiếu ghi công') : 'THIẾU',
            ];
        }
        $this->table(['slug', 'điểm', 'Planner', 'tháng', 'địa điểm đã duyệt', 'món', 'trải nghiệm', 'lễ hội', 'lịch trình', 'tour', 'ảnh bìa'], $rows);

        if ($scores) {
            $ready = count(array_filter($scores, fn($s) => $s['plannerReady']));
            $avg = (int) round(array_sum(array_column($scores, 'percent')) / count($scores));
            $this->line("Trung bình {$avg}% · Planner-ready {$ready}/" . count($scores) . ' điểm đến. Mục tiêu: Planner-ready ở tuần 4, Coverage ≥80% khi đóng băng nội dung.');
            foreach ($scores as $s) {
                if (!$s['plannerReady'] && $slugs) {
                    $this->line('  - ' . $s['slug'] . ' còn thiếu: ' . implode('; ', $s['plannerMissing']));
                }
            }
        }
        if ($this->option('save')) {
            $this->info('Đã lưu mốc ' . date('d/m/Y') . ' cho ' . $history->snapshot($scores) . ' điểm đến.');
        }

        return self::SUCCESS;
    }
}
