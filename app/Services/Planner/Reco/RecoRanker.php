<?php

namespace App\Services\Planner\Reco;

/**
 * Áp mô hình vào danh sách gợi ý thật (Đợt E, L5–L6). Chỉ ĐỔI THỨ TỰ các ứng viên mà luật / bộ lọc đã chọn
 * (đúng điểm đến, đang mở cửa, còn chỗ…): mô hình không thêm nơi mới và không vượt qua kiểm tra khả thi.
 *  - model      : xếp theo xác suất chọn, đa dạng hóa kiểu MMR, chèn một nơi khác nhóm vào ô "thử khác"
 *  - interleave : team-draft giữa thứ tự luật (A) và thứ tự mô hình (B); mỗi mục ghi đội của nó để so sánh sau
 * Mỗi mục có thêm `why` (tối đa 3 lý do bằng lời thường) khi xếp bằng mô hình.
 */
final class RecoRanker
{
    private const WHY = [
        'aff' => 'Bạn hay chọn những nơi kiểu này', 'sim' => 'Giống các nơi bạn đã thêm trước đây', 'glob' => 'Nhiều người đi trước đã giữ và ghé',
        'pop' => 'Nơi nổi bật ở điểm đến', 'free' => 'Không mất vé', 'indoor' => 'Trong nhà', 'dist_log' => 'Gần tuyến của bạn',
        'aff_x_pop' => 'Nổi bật và hợp sở thích của bạn',
    ];

    /**
     * @param list<array> $results (type, refId, ...)
     * @return array{results:list<array>, policy:string}
     */
    public static function rank(?int $userId, string $surface, array $results, array $ctx = []): array
    {
        $mode = (string) config('reco.mode', 'rules');
        if (!$userId || count($results) < 2 || $mode === 'rules' || !in_array($surface, (array) config('reco.surfaces', []), true)) {
            return ['results' => $results, 'policy' => 'rules'];
        }
        $model = RecoModel::current();
        if (!$model) {
            return ['results' => $results, 'policy' => 'rules'];
        }
        $keys = array_map(fn($r) => ($r['type'] ?? '') . ':' . ($r['refId'] ?? 0), $results);
        $f = (new RecoFeatures())->load($keys);
        $uv = $model->userVector($userId, new RecoFeatures());
        $t = time();
        $scored = [];
        foreach ($results as $i => $r) {
            $key = $keys[$i];
            $x = $f->vector($userId, $key, $t, $ctx);
            $g = $f->groupOf($key);
            $scored[$i] = ['r' => $r, 'p' => $model->score($x, $uv, $g), 'g' => $g, 'c' => $model->contributions($x, $uv, $g)];
        }
        $byModel = self::diversify($scored, (float) config('reco.diversity', 0.15), (int) config('reco.explore_slot', 4));
        $withWhy = fn(array $s, string $team = 'B') => $s['r'] + ['score' => round($s['p'], 4), 'why' => self::why($s['c']), 'team' => $team] + (!empty($s['explore']) ? ['explore' => true] : []);
        if ($mode === 'interleave') {
            $a = array_keys($results);
            $b = array_keys($byModel);
            $out = [];
            $used = [];
            $ia = $ib = 0;
            $cntA = $cntB = 0;
            while (count($out) < count($results)) {
                $pickA = $cntA < $cntB || ($cntA === $cntB && mt_rand(0, 1) === 0);
                $list = $pickA ? $a : $b;
                $ptr = $pickA ? $ia : $ib;
                while ($ptr < count($list) && isset($used[$list[$ptr]])) {
                    $ptr++;
                }
                if ($ptr >= count($list)) {
                    $pickA = !$pickA;
                    $list = $pickA ? $a : $b;
                    $ptr = $pickA ? $ia : $ib;
                    while ($ptr < count($list) && isset($used[$list[$ptr]])) {
                        $ptr++;
                    }
                }
                $idx = $list[$ptr];
                $used[$idx] = true;
                $out[] = $pickA ? $results[$idx] + ['team' => 'A'] : $withWhy($scored[$idx]);
                if ($pickA) {
                    $cntA++;
                    $ia = $ptr + 1;
                } else {
                    $cntB++;
                    $ib = $ptr + 1;
                }
            }

            return ['results' => $out, 'policy' => 'interleave'];
        }

        return ['results' => array_values(array_map(fn($s) => $withWhy($s), $byModel)), 'policy' => 'model'];
    }

    /** MMR: điểm − phạt theo số nơi cùng nhóm đã đứng trên; chèn một nơi khác nhóm, điểm khá, vào ô "thử khác". */
    private static function diversify(array $scored, float $lambda, int $exploreSlot): array
    {
        $left = $scored;
        $out = [];
        $seen = [];
        while ($left) {
            $best = null;
            $bestV = -INF;
            foreach ($left as $i => $s) {
                $v = $s['p'] - $lambda * ($seen[$s['g']] ?? 0);
                if ($v > $bestV) {
                    [$best, $bestV] = [$i, $v];
                }
            }
            $out[$best] = $left[$best];
            $seen[$left[$best]['g']] = ($seen[$left[$best]['g']] ?? 0) + 1;
            unset($left[$best]);
        }
        if ($exploreSlot >= 0 && count($out) > $exploreSlot + 1) {
            $keys = array_keys($out);
            $top = array_unique(array_map(fn($k) => $out[$k]['g'], array_slice($keys, 0, $exploreSlot)));
            foreach (array_slice($keys, $exploreSlot) as $k) {
                if (!in_array($out[$k]['g'], $top, true)) {
                    $item = $out[$k] + ['explore' => true];
                    unset($out[$k]);
                    $keys = array_keys($out);
                    $res = [];
                    foreach ($keys as $pos => $kk) {
                        if ($pos === $exploreSlot) {
                            $res[$k] = $item;
                        }
                        $res[$kk] = $out[$kk];
                    }
                    $out = $res;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function why(array $contrib): array
    {
        $out = [];
        foreach ($contrib as $f => $v) {
            if ($v <= 0.05 || count($out) >= 3) {
                continue;
            }
            if (str_starts_with($f, 'user_')) {
                $out[] = 'Bạn hay chọn nhóm này';
            } elseif (str_starts_with($f, 'g_')) {
                $out[] = 'Hợp nhóm nơi bạn hay chọn';
            } elseif (isset(self::WHY[$f])) {
                $out[] = self::WHY[$f];
            }
        }

        return array_values(array_unique($out));
    }
}
