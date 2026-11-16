<?php

namespace App\Services\Planner\Reco;

/**
 * Đánh giá offline (Đợt E, L7): chia nhật ký theo THỜI GIAN (80% cũ để học, 20% mới để kiểm), so 4 cách xếp trên cùng các danh sách đã hiện:
 *   pop   : độ nổi bật (thứ tự "ai cũng thích")
 *   rules : hồ sơ sở thích theo luật hiện có (PlaceSignals) — mốc của hệ thống trước mức 5
 *   global: mô hình chung, chưa thích nghi theo người
 *   model : mô hình chung + độ lệch riêng từng người (mức 5)
 * Chỉ số: AUC (toàn bộ lần hiện), NDCG@5 và Hit@5 (mỗi danh sách có ít nhất một nơi được chọn), khoảng tin cậy 95% bootstrap
 * cho chênh lệch NDCG@5 giữa model và rules.
 */
final class RecoEval
{
    /**
     * @param list<array> $lab RecoLog::labelled() (hoặc dữ liệu mô phỏng cùng dạng)
     * @return array{n:int, train:int, test:int, lists:int, methods:array<string,array{auc:float,ndcg5:float,hit5:float}>, diff:array{mean:float,lo:float,hi:float}, model:?RecoModel}
     */
    public static function run(array $lab, RecoFeatures $f, float $trainFrac = 0.8, int $seed = 11): array
    {
        usort($lab, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']) ?: $a['position'] <=> $b['position']);
        $cut = (int) floor(count($lab) * $trainFrac);
        $train = array_slice($lab, 0, $cut);
        $test = array_slice($lab, $cut);
        $vec = function (array $r) use ($f) {
            return $f->vector($r['userId'], $r['refType'] . ':' . $r['refId'], strtotime($r['createdAt']), $r['ctx'] + ['position' => $r['position']]);
        };
        $rows = array_map(fn($r) => ['x' => $vec($r), 'y' => $r['label']], $train);
        $model = count($rows) >= 20 ? RecoModel::train($rows, $f->names()) : null;
        $uv = [];
        if ($model) {
            $byUser = [];
            foreach ($train as $k => $r) {
                $byUser[$r['userId']][$k] = $r;
            }
            foreach ($byUser as $u => $list) {
                $uv[$u] = $model->fitUser(array_map(fn($k) => $rows[$k], array_combine(array_keys($list), array_keys($list))),
                    array_map(fn($r) => $f->groupOf($r['refType'] . ':' . $r['refId']), $list));
            }
        }
        $scores = ['pop' => [], 'rules' => [], 'global' => [], 'model' => []];
        foreach ($test as $k => $r) {
            $x = $vec($r);
            $g = $f->groupOf($r['refType'] . ':' . $r['refId']);
            $scores['pop'][$k] = $x['pop'];
            $scores['rules'][$k] = $x['aff'] + 0.01 * $x['pop'];
            $scores['global'][$k] = $model ? $model->score($x) : 0.5;
            $scores['model'][$k] = $model ? $model->score($x, $uv[$r['userId']] ?? [], $g) : 0.5;
        }
        $labels = array_map(fn($r) => $r['label'], $test);
        $lists = [];
        foreach ($test as $k => $r) {
            $lists[$r['listId'] ?? ($r['userId'] . '|' . $r['createdAt'])][] = $k;
        }
        $lists = array_filter($lists, fn($ks) => count($ks) >= 2 && array_sum(array_map(fn($k) => $labels[$k], $ks)) > 0);
        $methods = [];
        $perList = [];
        foreach ($scores as $m => $sc) {
            $nd = [];
            $hit = [];
            foreach ($lists as $lid => $ks) {
                $nd[$lid] = self::ndcg($ks, $sc, $labels, 5);
                $hit[$lid] = self::hit($ks, $sc, $labels, 5);
            }
            $perList[$m] = $nd;
            $methods[$m] = ['auc' => round(self::auc($sc, $labels), 3), 'ndcg5' => round($nd ? array_sum($nd) / count($nd) : 0, 3), 'hit5' => round($hit ? array_sum($hit) / count($hit) : 0, 3)];
        }

        return ['n' => count($lab), 'train' => count($train), 'test' => count($test), 'lists' => count($lists), 'methods' => $methods,
            'diff' => self::bootstrap($perList['model'] ?? [], $perList['rules'] ?? [], $seed), 'model' => $model];
    }

    public static function auc(array $scores, array $labels): float
    {
        $pos = $neg = [];
        foreach ($scores as $k => $s) {
            ($labels[$k] ?? 0) ? $pos[] = $s : $neg[] = $s;
        }
        if (!$pos || !$neg) {
            return 0.5;
        }
        $wins = 0.0;
        foreach ($pos as $p) {
            foreach ($neg as $n) {
                $wins += $p > $n ? 1 : ($p == $n ? 0.5 : 0);
            }
        }

        return $wins / (count($pos) * count($neg));
    }

    private static function order(array $ks, array $sc): array
    {
        usort($ks, fn($a, $b) => ($sc[$b] <=> $sc[$a]) ?: $a <=> $b);

        return $ks;
    }

    public static function ndcg(array $ks, array $sc, array $labels, int $k): float
    {
        $ord = array_slice(self::order($ks, $sc), 0, $k);
        $dcg = 0.0;
        foreach ($ord as $i => $x) {
            $dcg += $labels[$x] / log($i + 2, 2);
        }
        $ideal = array_slice(array_values(array_filter(array_map(fn($x) => $labels[$x], $ks))), 0, $k);
        $idcg = 0.0;
        foreach ($ideal as $i => $y) {
            $idcg += $y / log($i + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    public static function hit(array $ks, array $sc, array $labels, int $k): float
    {
        foreach (array_slice(self::order($ks, $sc), 0, $k) as $x) {
            if ($labels[$x]) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /** Khoảng tin cậy 95% của trung bình chênh lệch (a − b) theo danh sách, bootstrap 1000 lần. */
    public static function bootstrap(array $a, array $b, int $seed = 11, int $n = 1000): array
    {
        $ids = array_values(array_intersect(array_keys($a), array_keys($b)));
        if (!$ids) {
            return ['mean' => 0.0, 'lo' => 0.0, 'hi' => 0.0];
        }
        $d = array_map(fn($i) => $a[$i] - $b[$i], $ids);
        mt_srand($seed);
        $means = [];
        for ($r = 0; $r < $n; $r++) {
            $s = 0.0;
            for ($j = 0; $j < count($d); $j++) {
                $s += $d[mt_rand(0, count($d) - 1)];
            }
            $means[] = $s / count($d);
        }
        sort($means);

        return ['mean' => round(array_sum($d) / count($d), 3), 'lo' => round($means[(int) floor(0.025 * $n)], 3), 'hi' => round($means[(int) floor(0.975 * $n) - 1], 3)];
    }
}
