<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi phí AI tính từ token đã ghi (tbl_ai_runs cho La bàn, tbl_chat_logs cho Hỏi đáp / F5) và bảng giá config('ai.pricing').
 * Tính khi đọc báo cáo (không lưu cột tiền): đổi giá thì số liệu cũ được tính lại theo giá mới, ghi rõ ngày kiểm giá.
 */
final class AiCost
{
    /** @return ?array{in:float,out:float,long?:array{over:int,x:float}} giá theo model; null = chưa có giá */
    public static function priceOf(?string $model): ?array
    {
        $table = (array) config('ai.pricing', []);
        $m = strtolower(trim((string) $model));
        if ($m !== '') {
            if (isset($table[$m])) {
                return $table[$m];
            }
            $bare = preg_replace('#^(models/|openai/|google/|anthropic/|meta-llama/)#', '', $m) ?? $m;
            if (isset($table[$bare])) {
                return $table[$bare];
            }
            // bản có hậu tố ngày / preview: "gemini-3.5-flash-lite-preview-09-2026" -> khóa dài nhất là tiền tố
            $best = null;
            foreach ($table as $k => $p) {
                if (str_starts_with($bare, (string) $k) && ($best === null || strlen((string) $k) > strlen($best))) {
                    $best = (string) $k;
                }
            }
            if ($best !== null) {
                return $table[$best];
            }
        }
        if (config('ai.price_in') !== null && config('ai.price_out') !== null) {
            return ['in' => (float) config('ai.price_in'), 'out' => (float) config('ai.price_out')];
        }

        return null;
    }

    /** USD cho một lượt (hoặc tổng token của nhiều lượt cùng model khi $perCallIn = số token vào TB mỗi lượt). null = chưa có giá. */
    public static function usd(?string $model, int $in, int $out, ?int $perCallIn = null): ?float
    {
        $p = self::priceOf($model);
        if ($p === null) {
            return null;
        }
        $x = isset($p['long']) && ($perCallIn ?? $in) > (int) $p['long']['over'] ? (float) $p['long']['x'] : 1.0;

        return ($in * (float) $p['in'] + $out * (float) $p['out']) * $x / 1e6;
    }

    /**
     * Bảng chi phí theo ngày × chức năng (La bàn + Hỏi đáp).
     * @return array{rows:list<array{day:string,feature:string,model:string,calls:int,in:int,out:int,usd:?float}>, unpriced:list<string>,
     *               byFeature:array<string,array{calls:int,in:int,out:int,usd:float,unpriced:int}>, byDay:array<string,float>, total:float, checked:string}
     */
    public static function report(?string $from = null, ?string $to = null, ?string $feature = null): array
    {
        $rows = [];
        if (Schema::hasTable('tbl_ai_runs')) {
            // lượt có gọi model: engine llm, hoặc lượt lỗi khuôn / lùi về luật nhưng model đã trả lời (có token)
            $q = DB::table('tbl_ai_runs')->where(fn($w) => $w->where('engine', 'llm')->orWhere('inputTokens', '>', 0));
            self::range($q, $from, $to);
            if ($feature !== null && $feature !== 'ask') {
                $q->where('feature', $feature);
            }
            if ($feature !== 'ask') {
                foreach ($q->selectRaw('DATE(createdAt) d, feature, COALESCE(model, "") model, COUNT(*) n, COALESCE(SUM(inputTokens),0) tin, COALESCE(SUM(outputTokens),0) tout')
                    ->groupByRaw('DATE(createdAt), feature, COALESCE(model, "")')->get() as $r) {
                    $rows[] = ['day' => (string) $r->d, 'feature' => (string) $r->feature, 'model' => (string) $r->model, 'calls' => (int) $r->n, 'in' => (int) $r->tin, 'out' => (int) $r->tout];
                }
            }
        }
        if (($feature === null || $feature === 'ask') && Schema::hasTable('tbl_chat_logs') && Schema::hasColumn('tbl_chat_logs', 'inputTokens')) {
            $q = DB::table('tbl_chat_logs')->where('engine', 'llm')->where('cached', 0);
            self::range($q, $from, $to);
            foreach ($q->selectRaw('DATE(createdAt) d, COALESCE(model, "") model, COUNT(*) n, COALESCE(SUM(inputTokens),0) tin, COALESCE(SUM(outputTokens),0) tout')
                ->groupByRaw('DATE(createdAt), COALESCE(model, "")')->get() as $r) {
                $rows[] = ['day' => (string) $r->d, 'feature' => 'ask', 'model' => (string) $r->model, 'calls' => (int) $r->n, 'in' => (int) $r->tin, 'out' => (int) $r->tout];
            }
        }
        $unpriced = [];
        $byFeature = [];
        $byDay = [];
        $total = 0.0;
        foreach ($rows as &$r) {
            $r['usd'] = self::usd($r['model'], $r['in'], $r['out'], $r['calls'] ? intdiv($r['in'], $r['calls']) : 0);
            $f = &$byFeature[$r['feature']];
            $f ??= ['calls' => 0, 'in' => 0, 'out' => 0, 'usd' => 0.0, 'unpriced' => 0];
            $f['calls'] += $r['calls'];
            $f['in'] += $r['in'];
            $f['out'] += $r['out'];
            if ($r['usd'] === null) {
                $f['unpriced'] += $r['calls'];
                $unpriced[$r['model'] !== '' ? $r['model'] : '(không rõ model)'] = true;
            } else {
                $f['usd'] += $r['usd'];
                $byDay[$r['day']] = ($byDay[$r['day']] ?? 0) + $r['usd'];
                $total += $r['usd'];
            }
            unset($f);
        }
        unset($r);
        usort($rows, fn($a, $b) => strcmp($b['day'], $a['day']) ?: strcmp($a['feature'], $b['feature']));
        krsort($byDay);

        return ['rows' => $rows, 'unpriced' => array_keys($unpriced), 'byFeature' => $byFeature, 'byDay' => $byDay, 'total' => $total,
            'checked' => (string) config('ai.pricing_checked', '')];
    }

    private static function range($q, ?string $from, ?string $to): void
    {
        $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        if ($ok($from)) {
            $q->where('createdAt', '>=', $from);
        }
        if ($ok($to)) {
            $q->where('createdAt', '<', date('Y-m-d', strtotime($to . ' +1 day')));
        }
    }
}
