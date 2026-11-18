<?php

namespace App\Console\Commands;

use App\Services\Planner\Reco\RecoEval;
use App\Services\Planner\Reco\RecoFeatures;
use App\Services\Planner\Reco\RecoLog;
use App\Services\Planner\Reco\RecoModel;
use App\Services\Planner\Reco\RecoSimulator;
use Illuminate\Console\Command;

/**
 * Học và đánh giá mô hình cá nhân hóa (Đợt E, L3 + L7).
 *   php artisan reco:train                 học trên nhật ký thật, in bảng so sánh offline, lưu mô hình nếu đủ dữ liệu
 *   php artisan reco:train --simulate      chạy trên người dùng giả lập (chỉ để kiểm tra code học đúng, không phải bằng chứng)
 *   php artisan reco:train --csv=storage/app/bench/reco-offline.csv
 */
class RecoTrain extends Command
{
    protected $signature = 'reco:train {--simulate : Dữ liệu giả lập} {--users=80} {--sessions=15} {--csv=} {--no-save}';

    protected $description = 'Học mô hình cá nhân hóa gợi ý và so sánh offline với các cách xếp mốc';

    public function handle(): int
    {
        if ($this->option('simulate')) {
            $s = RecoSimulator::generate((int) $this->option('users'), (int) $this->option('sessions'));
            $this->warn('Dữ liệu GIẢ LẬP: chỉ kiểm tra code học đúng, không dùng làm bằng chứng hiệu quả.');
            $lab = $s['lab'];
            $f = $s['features'];
        } else {
            $lab = RecoLog::labelled();
            if (count($lab) < 20) {
                $this->warn('Chưa đủ dữ liệu (' . count($lab) . ' lần hiện có nhãn). Cần người dùng thật dùng Gợi ý / Tìm quanh tuyến / La bàn trước.');

                return self::SUCCESS;
            }
            $f = (new RecoFeatures())->load(array_map(fn($r) => $r['refType'] . ':' . $r['refId'], $lab));
        }
        $r = RecoEval::run($lab, $f);
        $names = ['pop' => 'Độ nổi bật', 'rules' => 'Sở thích theo luật (trước mức 5)', 'global' => 'Mô hình chung', 'model' => 'Mô hình + riêng từng người (mức 5)'];
        $this->info(sprintf(
            '%d lần hiện (%d học / %d kiểm), %d danh sách kiểm có ít nhất một nơi được chọn, %d người.',
            $r['n'],
            $r['train'],
            $r['test'],
            $r['lists'],
            count(array_unique(array_column($lab, 'userId'))),
        ));
        $this->table(['Cách xếp', 'AUC', 'NDCG@5', 'Hit@5'], array_map(fn($k) => [$names[$k], $r['methods'][$k]['auc'], $r['methods'][$k]['ndcg5'], $r['methods'][$k]['hit5']], array_keys($names)));
        $d = $r['diff'];
        $this->line(sprintf(
            'Chênh NDCG@5 (mức 5 − luật): %+.3f, khoảng tin cậy 95%%: [%+.3f; %+.3f]%s',
            $d['mean'],
            $d['lo'],
            $d['hi'],
            $d['lo'] > 0 ? ' → tốt hơn có ý nghĩa' : ($d['hi'] < 0 ? ' → kém hơn có ý nghĩa' : ' → chưa khác biệt có ý nghĩa'),
        ));
        // Đủ để kết luận chưa (mục B): dữ liệu thật ít người / ít danh sách kiểm thì chỉ báo "chưa đủ", kèm câu ghi vào quyển
        $users = count(array_unique(array_column($lab, 'userId')));
        $enough = !$this->option('simulate') && $users >= 10 && $r['lists'] >= 30;
        if ($this->option('simulate')) {
            $this->warn('Ghi vào quyển: "RecoRanker mới được kiểm trên dữ liệu giả lập; chưa có bằng chứng hiệu quả trên người dùng thật."');
        } elseif (!$enough) {
            $this->warn(sprintf(
                'CHƯA ĐỦ để kết luận (%d người thật, %d danh sách kiểm; cần ≥ 10 người và ≥ 30 danh sách). Ghi vào quyển: '
                . '"Đánh giá offline trên %d lần hiện của %d người dùng thật; cỡ mẫu nhỏ nên chênh lệch giữa mô hình cá nhân hóa và luật chưa đủ cơ sở để kết luận."',
                $users,
                $r['lists'],
                $r['n'],
                $users,
            ));
        }
        if ($csv = $this->option('csv')) {
            @mkdir(dirname($csv), 0777, true);
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['method', 'auc', 'ndcg5', 'hit5', 'n', 'train', 'test', 'lists', 'diff_mean', 'diff_lo', 'diff_hi', 'data', 'users', 'enough'], ',', '"', '');
            foreach ($r['methods'] as $m => $x) {
                fputcsv($fh, [$m, $x['auc'], $x['ndcg5'], $x['hit5'], $r['n'], $r['train'], $r['test'], $r['lists'], $d['mean'], $d['lo'], $d['hi'],
                    $this->option('simulate') ? 'simulated' : 'real', $users, $enough ? 1 : 0], ',', '"', '');
            }
            fclose($fh);
            $this->info('Đã ghi ' . $csv);
        }
        if ($this->option('simulate') || $this->option('no-save')) {
            return self::SUCCESS;
        }
        if (count($lab) < (int) config('reco.min_train', 200)) {
            $this->warn('Chưa lưu mô hình: cần ít nhất ' . config('reco.min_train') . ' lần hiện có nhãn.');

            return self::SUCCESS;
        }
        // mô hình dùng thật học trên TOÀN BỘ dữ liệu (đánh giá ở trên dùng phần cũ để học, phần mới để kiểm)
        $rows = array_map(fn($x) => ['x' => $f->vector($x['userId'], $x['refType'] . ':' . $x['refId'], strtotime($x['createdAt']), $x['ctx'] + ['position' => $x['position']]), 'y' => $x['label']], $lab);
        $m = RecoModel::train($rows, $f->names());
        $m->meta += ['offline' => $r['methods'], 'diff' => $d];
        $ver = $m->save();
        $this->info("Đã lưu mô hình $ver. Bật dùng: RECO_MODE=model (hoặc interleave để so sánh với người dùng thật).");

        return self::SUCCESS;
    }
}
