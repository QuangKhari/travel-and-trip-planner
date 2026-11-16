<?php

namespace App\Services\Planner\Reco;

/**
 * Người dùng giả lập (Đợt E, L9) — CHỈ để kiểm tra code học đúng, KHÔNG dùng làm bằng chứng hiệu quả trong quyển.
 * Mỗi người giả có sở thích thật theo nhóm loại nơi (biết trước); mỗi phiên thấy 10 nơi ngẫu nhiên và chọn nơi theo xác suất
 * tăng theo sở thích + độ nổi bật, giảm theo vị trí. Nếu mô hình học đúng, nó phải: AUC cao hơn "độ nổi bật", và độ lệch riêng
 * học được cùng dấu với sở thích thật.
 */
final class RecoSimulator
{
    /** @return array{lab:list<array>, features:RecoFeatures, truth:array<int,array<string,float>>} */
    public static function generate(int $users = 40, int $sessions = 8, int $items = 120, int $seed = 3): array
    {
        mt_srand($seed);
        $groups = array_keys((array) config('planner.kind_groups', []));
        $kindOf = [];
        foreach ((array) config('planner.kind_groups', []) as $g => $kinds) {
            foreach ($kinds as $k) {   // loại nơi mà PlaceSignals::groupOf trả đúng nhóm này
                if (\App\Services\Planner\PlaceSignals::groupOf('place', $k) === $g) {
                    $kindOf[$g] = $k;
                    break;
                }
            }
        }
        $groups = array_keys($kindOf);
        $f = new RecoFeatures();
        $catalog = [];
        for ($i = 1; $i <= $items; $i++) {
            $g = $groups[mt_rand(0, count($groups) - 1)];
            $rank = mt_rand(1, 10);
            $key = 'place:' . (900000 + $i);
            $f->withItem($key, (object) ['type' => 'place', 'name' => "Nơi $i $g", 'category' => 'sight', 'kind' => $kindOf[$g], 'price' => mt_rand(0, 3) * 50000,
                'indoor' => mt_rand(0, 1), 'dur' => 60, 'rank' => $rank, 'lat' => null, 'lng' => null, 'text' => "noi $g " . $kindOf[$g]]);
            $catalog[] = ['key' => $key, 'g' => $g, 'pop' => $rank / 10];
        }
        $truth = [];
        $lab = [];
        $signals = [];
        $t = strtotime('2026-11-01 08:00:00');
        for ($u = 1; $u <= $users; $u++) {
            foreach ($groups as $g) {
                $truth[$u][$g] = mt_rand(-150, 150) / 100;
            }
        }
        for ($s = 0; $s < $sessions; $s++) {
            for ($u = 1; $u <= $users; $u++) {
                $t += 60;
                $shown = array_rand($catalog, 10);
                $list = 'sim' . $u . '-' . $s;
                $util = [];
                foreach ($shown as $pos => $c) {
                    $util[$pos] = $truth[$u][$catalog[$c]['g']] + 1.0 * $catalog[$c]['pop'] - 0.15 * $pos;
                }
                // chọn 1 nơi theo softmax, đôi khi không chọn gì
                $z = array_sum(array_map('exp', $util)) + exp(0.5);
                $r = mt_rand() / mt_getrandmax() * $z;
                $pick = null;
                foreach ($util as $pos => $v) {
                    $r -= exp($v);
                    if ($r <= 0) {
                        $pick = $pos;
                        break;
                    }
                }
                foreach ($shown as $pos => $c) {
                    [$type, $id] = explode(':', $catalog[$c]['key']);
                    $lab[] = ['impressionId' => count($lab) + 1, 'listId' => $list, 'userId' => $u, 'tripId' => null, 'surface' => 'browse', 'policy' => 'rules',
                        'refType' => $type, 'refId' => (int) $id, 'position' => $pos, 'ctx' => [], 'createdAt' => date('Y-m-d H:i:s', $t), 'label' => $pos === $pick ? 1 : 0];
                }
                if ($pick !== null) {
                    $c = $catalog[$shown[$pick]];
                    $signals[] = ['userId' => $u, 't' => $t + 5, 'key' => $c['key'], 'grp' => $c['g'], 'signal' => 'added'];
                }
            }
        }
        $f->withSignals($signals)->rebuild();

        return ['lab' => $lab, 'features' => $f, 'truth' => $truth];
    }
}
