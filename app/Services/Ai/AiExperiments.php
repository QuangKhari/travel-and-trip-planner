<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A/B thử nghiệm cho AI (đổi model, nhiệt độ, mức suy luận, tự sửa khuôn) — đo bằng số liệu thật thay vì cảm giác.
 *
 * config('ai.experiments'):
 *   'copilot-model' => ['feature' => 'copilot', 'active' => true, 'split' => 50, 'started' => '2026-11-20',
 *                       'variants' => ['A' => [], 'B' => ['model' => 'gemini-3.5-flash']]]
 *
 * - Chia nhóm cố định theo NGƯỜI DÙNG (băm tên thử nghiệm + userId): một người luôn ở một nhóm, không nhảy nhóm giữa các lượt.
 *   Khách chưa đăng nhập không vào thử nghiệm. A = nhóm đối chứng (cấu hình hiện tại).
 * - AiGateway áp cấu hình của nhóm cho đúng lệnh gọi đó rồi trả lại, ghi "tên:nhóm" vào tbl_ai_runs.variant.
 * - report(): mỗi nhóm — số lượt, tỷ lệ gọi thành công, JSON sai khuôn, P50/P95, chi phí / lượt, tỷ lệ áp dụng đề xuất (Copilot),
 *   tỷ lệ "đúng ý" (chip đánh giá); kiểm định hai tỷ lệ (z-test) cho chỉ số chính để biết chênh lệch có ý nghĩa thống kê không.
 */
final class AiExperiments
{
    public const KEYS = ['model', 'temperature', 'reasoning_effort', 'schema_repair', 'max_tokens'];

    /** @return ?array{name:string,variant:string,cfg:array} nhóm của người dùng cho tính năng này (null = không thử nghiệm) */
    public static function pick(string $feature, ?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }
        foreach ((array) config('ai.experiments', []) as $name => $e) {
            if (empty($e['active']) || ($e['feature'] ?? null) !== $feature || empty($e['variants']['B'])) {
                continue;
            }
            $v = self::assign((string) $name, $userId, (int) ($e['split'] ?? 50));

            return ['name' => (string) $name, 'variant' => $v, 'cfg' => array_intersect_key((array) ($e['variants'][$v] ?? []), array_flip(self::KEYS))];
        }

        return null;
    }

    /** Nhóm cố định theo người dùng: B khi băm % 100 < split. */
    public static function assign(string $name, int $userId, int $split = 50): string
    {
        return (crc32($name . '|' . $userId) % 100) < max(0, min(100, $split)) ? 'B' : 'A';
    }

    /**
     * Áp cấu hình của nhóm vào config (cho đúng một lệnh gọi); trả hàm khôi phục.
     */
    public static function apply(string $feature, array $cfg): callable
    {
        $driver = (string) config('ai.driver', 'none');
        $keys = [
            'model' => 'ai.models.' . $driver . '.' . $feature,
            'temperature' => 'ai.temperature',
            'reasoning_effort' => 'ai.reasoning_effort',
            'schema_repair' => 'ai.schema_repair',
            'max_tokens' => 'ai.max_tokens.' . $feature,
        ];
        $old = [];
        foreach ($cfg as $k => $v) {
            if (isset($keys[$k])) {
                $old[$keys[$k]] = config($keys[$k]);
                config([$keys[$k] => $v]);
            }
        }

        return function () use ($old) {
            foreach ($old as $k => $v) {
                config([$k => $v]);
            }
        };
    }

    /**
     * @return array{name:string,feature:string,variants:array<string,array>,test:?array{metric:string,a:float,b:float,diff:float,z:float,p:float,significant:bool}}
     */
    public static function report(string $name, ?string $from = null, ?string $to = null): array
    {
        $e = (array) config('ai.experiments.' . $name, []);
        $feature = (string) ($e['feature'] ?? '');
        $out = ['name' => $name, 'feature' => $feature, 'variants' => [], 'test' => null];
        if (!Schema::hasColumn('tbl_ai_runs', 'variant')) {
            return $out;
        }
        $from ??= $e['started'] ?? null;
        foreach (['A', 'B'] as $v) {
            $q = DB::table('tbl_ai_runs')->where('variant', $name . ':' . $v);
            if ($from) {
                $q->where('createdAt', '>=', $from);
            }
            if ($to) {
                $q->where('createdAt', '<', date('Y-m-d', strtotime($to . ' +1 day')));
            }
            $rows = $q->get(['runId', 'model', 'engine', 'outcome', 'latencyMs', 'inputTokens', 'outputTokens', 'repairRounds']);
            $n = $rows->count();
            $failed = $rows->whereIn('outcome', ['error', 'timeout', 'invalid'])->count();
            $lat = $rows->where('engine', 'llm')->pluck('latencyMs')->map(fn($x) => (int) $x)->sort()->values()->all();
            $usd = 0.0;
            foreach ($rows->groupBy('model') as $m => $g) {
                $usd += (float) AiCost::usd((string) $m, (int) $g->sum('inputTokens'), (int) $g->sum('outputTokens'), (int) ($g->avg('inputTokens') ?? 0));
            }
            $applied = $rows->where('outcome', 'applied')->count();
            $decided = $rows->whereIn('outcome', ['applied', 'skipped', 'undone'])->count();
            $fb = Schema::hasTable('tbl_ai_feedback') ? DB::table('tbl_ai_feedback')->whereIn('runId', $rows->pluck('runId'))->selectRaw('COUNT(*) n, SUM(helpful = 1) yes')->first() : null;
            $out['variants'][$v] = [
                'cfg' => (array) ($e['variants'][$v] ?? []),
                'runs' => $n, 'ok' => $n - $failed, 'okRate' => $n ? round(100 * ($n - $failed) / $n, 1) : null,
                'invalid' => $rows->where('outcome', 'invalid')->count(), 'repaired' => $rows->where('repairRounds', '>', 0)->count(),
                'p50' => $lat ? $lat[(int) floor(0.5 * (count($lat) - 1))] : null, 'p95' => $lat ? $lat[(int) floor(0.95 * (count($lat) - 1))] : null,
                'usdPerRun' => $n ? $usd / $n : null,
                'applied' => $applied, 'decided' => $decided, 'appliedRate' => $decided ? round(100 * $applied / $decided, 1) : null,
                'helpful' => (int) ($fb->yes ?? 0), 'rated' => (int) ($fb->n ?? 0),
            ];
        }
        // chỉ số chính: Copilot = tỷ lệ áp dụng; tính năng khác = tỷ lệ gọi thành công
        [$metric, $k, $nk] = $feature === 'copilot' ? ['tỷ lệ áp dụng đề xuất', 'applied', 'decided'] : ['tỷ lệ gọi thành công', 'ok', 'runs'];
        $a = $out['variants']['A'];
        $b = $out['variants']['B'];
        if ($a[$nk] >= 1 && $b[$nk] >= 1) {
            $out['test'] = ['metric' => $metric] + self::ztest($a[$k], $a[$nk], $b[$k], $b[$nk]);
        }

        return $out;
    }

    /**
     * Kiểm định hai tỷ lệ (z-test, gộp phương sai). p hai phía; "significant" khi p < 0,05 VÀ mỗi nhóm >= 30 lượt.
     * @return array{a:float,b:float,diff:float,z:float,p:float,significant:bool,nA:int,nB:int}
     */
    public static function ztest(int $xa, int $na, int $xb, int $nb): array
    {
        $pa = $xa / $na;
        $pb = $xb / $nb;
        $p = ($xa + $xb) / ($na + $nb);
        $se = sqrt(max(1e-12, $p * (1 - $p) * (1 / $na + 1 / $nb)));
        $z = ($pb - $pa) / $se;
        $pv = 2 * (1 - self::phi(abs($z)));

        return ['a' => round(100 * $pa, 1), 'b' => round(100 * $pb, 1), 'diff' => round(100 * ($pb - $pa), 1), 'z' => round($z, 2), 'p' => round($pv, 4),
            'significant' => $pv < 0.05 && $na >= 30 && $nb >= 30, 'nA' => $na, 'nB' => $nb];
    }

    /** Hàm phân phối chuẩn tắc (xấp xỉ Abramowitz–Stegun 26.2.17, sai số < 7.5e-8). */
    private static function phi(float $x): float
    {
        $t = 1 / (1 + 0.2316419 * $x);
        $d = 0.3989422804014327 * exp(-$x * $x / 2);
        $q = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return 1 - $q;
    }
}
