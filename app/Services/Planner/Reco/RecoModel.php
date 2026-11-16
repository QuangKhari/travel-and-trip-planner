<?php

namespace App\Services\Planner\Reco;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Mô hình cá nhân hóa (Đợt E, L3–L4): hồi quy logistic dự đoán khả năng người dùng CHỌN một gợi ý.
 *
 *   p(chọn | người u, nơi i, ngữ cảnh) = σ( w · x(u, i, t)  +  v_u · g(i) )
 *     x  : đặc trưng RecoFeatures (nơi, ngữ cảnh, sở thích theo thời điểm, độ giống nội dung, cộng đồng)
 *     w  : trọng số CHUNG, học bằng SGD + L2 trên toàn bộ lần hiện có nhãn (reco:train, chạy hằng đêm)
 *     v_u: độ lệch RIÊNG của người u theo nhóm loại nơi g(i), học từ chính dữ liệu của u với phạt L2 mạnh kéo về 0
 *          (ít tín hiệu → v_u ≈ 0 → dùng mô hình chung: người mới vẫn có gợi ý hợp lý). Tính lại khi người đó có tín hiệu mới.
 * Lúc dùng thật, đặc trưng vị trí (pos_inv) đặt bằng 0 để không ưu tiên lại thứ tự cũ (khử thiên lệch vị trí).
 */
final class RecoModel
{
    public const DIR = 'reco';
    public const USER_L2 = 1.0;

    /** @param array<string,float> $w */
    public function __construct(public array $w = [], public array $meta = []) {}

    public static function sigmoid(float $z): float
    {
        return 1 / (1 + exp(-max(-30, min(30, $z))));
    }

    /**
     * @param list<array{x:array<string,float>,y:int}> $rows
     */
    public static function train(array $rows, array $names, int $iters = 300, float $lr = 0.5, float $l2 = 0.01, int $seed = 7): self
    {
        // Hạ gradient theo lô đầy đủ (tất định, ổn định với ít dữ liệu), cân lớp vì "được chọn" hiếm hơn nhiều so với "không chọn"
        $w = array_fill_keys($names, 0.0);
        $n = max(1, count($rows));
        $pos = max(1, count(array_filter($rows, fn($r) => $r['y'] === 1)));
        $neg = max(1, $n - $pos);
        $wPos = $n / (2 * $pos);
        $wNeg = $n / (2 * $neg);
        $m = array_fill_keys($names, 0.0);   // quán tính (momentum) cho hội tụ nhanh hơn
        for ($it = 0; $it < $iters; $it++) {
            $grad = array_fill_keys($names, 0.0);
            foreach ($rows as $r) {
                $g = (self::sigmoid(self::dot($w, $r['x'])) - $r['y']) * ($r['y'] ? $wPos : $wNeg);
                foreach ($r['x'] as $f => $v) {
                    if ($v != 0.0 && isset($grad[$f])) {
                        $grad[$f] += $g * $v;
                    }
                }
            }
            foreach ($w as $f => $x) {
                $gf = $grad[$f] / $n + ($f === 'bias' ? 0 : $l2 * $x);
                $m[$f] = 0.9 * $m[$f] + $gf;
                $w[$f] = $x - $lr * $m[$f];
            }
        }

        return new self($w, ['trainedAt' => now()->toIso8601String(), 'n' => $n, 'pos' => $pos, 'iters' => $iters, 'l2' => $l2]);
    }

    public static function dot(array $w, array $x): float
    {
        $s = 0.0;
        foreach ($x as $f => $v) {
            $s += ($w[$f] ?? 0.0) * $v;
        }

        return $s;
    }

    /** Độ lệch riêng theo nhóm của một người, học từ dữ liệu của chính người đó (MAP, phạt L2 về 0). */
    public function fitUser(array $rows, array $groupsOf, int $epochs = 40, float $lr = 0.2): array
    {
        $v = [];
        for ($e = 0; $e < $epochs; $e++) {
            foreach ($rows as $k => $r) {
                $g = $groupsOf[$k] ?? null;
                if ($g === null) {
                    continue;
                }
                $p = self::sigmoid(self::dot($this->w, $r['x']) + ($v[$g] ?? 0.0));
                $v[$g] = ($v[$g] ?? 0.0) - $lr * (($p - $r['y']) + self::USER_L2 * ($v[$g] ?? 0.0) / max(1, count($rows)));
            }
        }

        return array_map(fn($x) => round(max(-3, min(3, $x)), 3), $v);
    }

    public function score(array $x, array $userVec = [], ?string $group = null): float
    {
        $x['pos_inv'] = 0.0;

        return self::sigmoid(self::dot($this->w, $x) + ($group !== null ? ($userVec[$group] ?? 0.0) : 0.0));
    }

    /** Đóng góp của từng đặc trưng (cho "Vì sao gợi ý này"). */
    public function contributions(array $x, array $userVec = [], ?string $group = null): array
    {
        $c = [];
        foreach ($x as $f => $v) {
            if ($f !== 'bias' && $f !== 'pos_inv' && $v != 0.0) {
                $c[$f] = ($this->w[$f] ?? 0.0) * $v;
            }
        }
        if ($group !== null && isset($userVec[$group])) {
            $c['user_' . $group] = $userVec[$group];
        }
        arsort($c);

        return $c;
    }

    // ---------------------------------------------------------------- lưu / nạp

    public function save(): string
    {
        $ver = now()->format('YmdHis');
        $json = json_encode(['version' => $ver, 'w' => $this->w, 'meta' => $this->meta], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Storage::disk('local')->put(self::DIR . "/model-$ver.json", $json);
        Storage::disk('local')->put(self::DIR . '/current.json', $json);
        Cache::forget('reco:model');

        return $ver;
    }

    public static function current(): ?self
    {
        return Cache::remember('reco:model', 300, function () {
            $d = Storage::disk('local')->exists(self::DIR . '/current.json') ? json_decode((string) Storage::disk('local')->get(self::DIR . '/current.json'), true) : null;

            return $d ? new self((array) $d['w'], (array) ($d['meta'] ?? []) + ['version' => $d['version'] ?? null]) : null;
        }) ?: null;
    }

    /** Có mô hình và đang bật chế độ dùng mô hình (config reco.mode = model | interleave). */
    public static function active(): bool
    {
        return in_array(config('reco.mode', 'rules'), ['model', 'interleave'], true) && self::current() !== null;
    }

    /** Vector riêng của người dùng, tính lại khi họ có tín hiệu mới (khóa bộ đệm theo số tín hiệu). */
    public function userVector(int $userId, RecoFeatures $f): array
    {
        if ($userId <= 0 || !RecoLog::ready()) {
            return [];
        }
        $n = (int) DB::table('tbl_place_signals')->where('userId', $userId)->count();

        return Cache::remember("reco:user:$userId:" . ($this->meta['version'] ?? 'x') . ":$n", 3600, function () use ($userId, $f) {
            $lab = RecoLog::labelled(null, $userId);
            if (count($lab) < 5) {
                return [];
            }
            $f->load(array_map(fn($r) => $r['refType'] . ':' . $r['refId'], $lab));
            $rows = [];
            $groups = [];
            foreach ($lab as $k => $r) {
                $key = $r['refType'] . ':' . $r['refId'];
                $rows[$k] = ['x' => $f->vector($userId, $key, strtotime($r['createdAt']), $r['ctx'] + ['position' => $r['position']]), 'y' => $r['label']];
                $groups[$k] = $f->groupOf($key);
            }

            return $this->fitUser($rows, $groups);
        });
    }
}
