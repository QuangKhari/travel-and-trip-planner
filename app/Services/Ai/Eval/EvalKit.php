<?php

namespace App\Services\Ai\Eval;

use App\Services\Ai\Llm\AiLlmFactory;
use App\Services\Ai\SchemaViolation;
use App\Services\Planner\Health\TripHealth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Đồ nghề chung cho các bộ đánh giá La bàn (tài liệu v2.2 mục 8): chạy trong "hộp cát" (giao dịch CSDL rồi hoàn tác, không để lại
 * chuyến / log / cache nào), gọi LLM có thử lại, tính phân vị, ghi CSV. Mọi số liệu đo bằng CHÍNH TripHealth của sản phẩm.
 */
final class EvalKit
{
    public function __construct(private AiLlmFactory $llms, private TripHealth $health) {}

    /**
     * Chạy $fn(userId) trong một giao dịch rồi HOÀN TÁC: chuyến tạm, tbl_ai_runs, cache F2... đều biến mất sau mỗi mẫu.
     * @template T
     * @param callable(int):T $fn
     * @return T
     */
    public function sandbox(callable $fn): mixed
    {
        DB::beginTransaction();
        try {
            $uid = (int) DB::table('tbl_users')->insertGetId(['username' => 'eval_' . bin2hex(random_bytes(4)), 'email' => 'eval_' . bin2hex(random_bytes(4)) . '@eval.local',
                'password' => 'x', 'fullName' => 'Đánh giá La bàn', 'isActive' => 'y']);

            return $fn($uid);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Lưu một chuyến tạm (chỉ dùng trong sandbox).
     * @param list<array{type:string,refId:int,day:?int,startMin?:?int,durationMin?:?int}> $items
     */
    public function saveTrip(int $userId, ?int $destId, int $days, ?string $start, int $group, array $items, string $title = 'Đánh giá'): int
    {
        $tripId = (int) DB::table('tbl_trips')->insertGetId(['userId' => $userId, 'kind' => 'trip', 'destinationId' => $destId, 'title' => $title,
            'startDate' => $start, 'days' => $days, 'groupSize' => $group, 'status' => 'planned', 'createdAt' => now(), 'updatedAt' => now()]);
        $order = [];
        $seen = [];
        $rows = [];
        foreach ($items as $it) {
            $k = $it['type'] . ':' . $it['refId'];
            if (isset($seen[$k])) {
                continue;   // tbl_trip_items unique(tripId, refType, refId): mục lặp chỉ tính một lần (và được đếm riêng ở nơi gọi)
            }
            $seen[$k] = true;
            $d = $it['day'] ?? null;
            $rows[] = ['tripId' => $tripId, 'refType' => $it['type'], 'refId' => (int) $it['refId'], 'dayNo' => $d, 'orderNo' => $order[$d ?? 0] = ($order[$d ?? 0] ?? -1) + 1,
                'startMin' => $it['startMin'] ?? null, 'durationMin' => $it['durationMin'] ?? null];
        }
        if ($rows) {
            DB::table('tbl_trip_items')->insert($rows);
        }

        return $tripId;
    }

    /** @return array{score:int,hard:int,warn:int,codes:array<string,int>,avgLoad:float,maxLoad:int,items:int,raw:array} */
    public function healthOf(int $tripId): array
    {
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        $h = $this->health->forTrip($trip);
        $codes = [];
        $hard = $warn = 0;
        foreach ($h['checks'] as $c) {
            if ($c['level'] === 'bad') {
                $hard++;
                $codes[$c['code']] = ($codes[$c['code']] ?? 0) + 1;
            } elseif ($c['level'] === 'warn') {
                $warn++;
            }
        }
        $loads = array_map(fn($d) => (int) $d['loadPct'], array_filter($h['days'], fn($d) => $d['count'] > 0));

        return ['score' => (int) $h['score'], 'hard' => $hard, 'warn' => $warn, 'codes' => $codes, 'avgLoad' => $loads ? round(array_sum($loads) / count($loads), 1) : 0.0,
            'maxLoad' => $loads ? max($loads) : 0, 'items' => array_sum(array_map(fn($d) => $d['count'], $h['days'])), 'raw' => $h];
    }

    /**
     * Một lệnh gọi LLM (bỏ qua ngắt mạch / hạn mức: đánh giá đo chất lượng model), thử lại khi lỗi.
     * @return array{ok:bool,data?:array,error?:string,ms:int,in:int,out:int,model:?string}
     */
    public function llm(string $task, string $system, string $user, array $schema, int $retry = 2, int $timeout = 60, int $maxTokens = 4000): array
    {
        $llm = $this->llms->make($task);
        if (!$llm) {
            return ['ok' => false, 'error' => 'no_llm', 'ms' => 0, 'in' => 0, 'out' => 0, 'model' => null];
        }
        $err = null;
        for ($i = 0; $i <= $retry; $i++) {
            $t0 = microtime(true);
            try {
                $r = $llm->generateJson($system, $user, $schema, ['name' => $task, 'timeout' => $timeout, 'max_tokens' => $maxTokens]);

                return ['ok' => true, 'data' => $r['data'], 'ms' => self::ms($t0), 'in' => (int) $r['input_tokens'], 'out' => (int) $r['output_tokens'], 'model' => $llm->model()];
            } catch (SchemaViolation | Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 140);
                if ($i < $retry && !app()->runningUnitTests()) {
                    sleep(5 * ($i + 1) + (str_contains($err, '429') ? 10 : 0));
                }
            }
        }

        return ['ok' => false, 'error' => $err, 'ms' => 0, 'in' => 0, 'out' => 0, 'model' => $llm->model()];
    }

    /** Phân vị theo cách "lấy phần tử" (giống bộ brief). @param list<int|float> $v */
    public static function pct(array $v, float $q): int|float
    {
        if (!$v) {
            return 0;
        }
        sort($v);

        return $v[min(count($v) - 1, (int) floor($q * count($v)))];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function csv(string $path, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        $cols = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        fputcsv($fh, $cols);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn($c) => is_array($r[$c] ?? null) ? json_encode($r[$c], JSON_UNESCAPED_UNICODE) : ($r[$c] ?? ''), $cols));
        }
        fclose($fh);
    }

    public static function ms(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /** "08:30" -> 510; sai định dạng -> null. */
    public static function minutes(mixed $hhmm): ?int
    {
        return is_string($hhmm) && preg_match('/^(\d{1,2})[:h](\d{2})$/', trim($hhmm), $m) && (int) $m[1] < 24 && (int) $m[2] < 60 ? (int) $m[1] * 60 + (int) $m[2] : null;
    }
}
