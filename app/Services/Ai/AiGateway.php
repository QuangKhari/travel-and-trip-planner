<?php

namespace App\Services\Ai;

use App\Services\Ai\Llm\AiLlmFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Một cổng cho mọi lệnh gọi LLM của Trip Planner (tài liệu v2.2, mục 6.4). Thứ tự:
 *   cờ tính năng -> có LLM -> ngắt mạch -> hạn mức/người dùng -> gọi (timeout cứng) -> ghi tbl_ai_runs -> báo lý do để lùi.
 * Gateway KHÔNG quyết định đường lùi cụ thể: dịch vụ (BriefService, ExplainService) tự lùi về luật / câu mẫu
 * khi nhận ['ok' => false, 'reason' => ...]. Nhờ vậy trang không bao giờ "treo chờ AI".
 */
final class AiGateway
{
    public const FEATURES = ['brief', 'explain', 'copilot', 'packing', 'booking_read'];

    public function __construct(private AiLlmFactory $factory) {}

    /** null = gọi được; còn lại là lý do: disabled | no_llm | circuit | quota. */
    public function blocked(string $feature, ?int $userId): ?string
    {
        if (!config('ai.enabled') || !config('ai.features.' . $feature)) {
            return 'disabled';
        }
        if (!$this->factory->make($feature)) {
            return 'no_llm';
        }
        if (self::openUntil($feature) > time()) {
            return 'circuit';
        }
        if ($userId && $this->remaining($userId) <= 0) {
            return 'quota';
        }

        return null;
    }

    /** Có bật tính năng cho giao diện không (ẩn nút / hiện "chế độ cơ bản"). */
    public function featureOn(string $feature): bool
    {
        return (bool) config('ai.enabled') && (bool) config('ai.features.' . $feature);
    }

    public function remaining(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }
        $limit = (int) config('ai.limits.daily_per_user', 40);
        $runs = $this->hasLog() ? DB::table('tbl_ai_runs')->where('userId', $userId)->where('engine', 'llm')->where('createdAt', '>=', now()->startOfDay())->count() : 0;
        $q = self::quotaReady() ? DB::table('tbl_ai_quota')->where(['userId' => $userId, 'day' => now()->toDateString()])->first() : null;
        $reserved = $q && !self::staleReservation($q) ? (int) $q->reserved : 0;

        // đã dùng = lớn hơn của bộ đếm và nhật ký (nhật ký lỗi ghi thì bộ đếm vẫn đúng; dữ liệu trước khi có bộ đếm vẫn được tính)
        return max(0, $limit - max($runs, (int) ($q->used ?? 0)) - $reserved);
    }

    private static ?bool $quotaReady = null;

    public static function quotaReady(): bool
    {
        return self::$quotaReady ??= \Illuminate\Support\Facades\Schema::hasTable('tbl_ai_quota');
    }

    /** Lượt đặt trước quá 5 phút chưa trả (tiến trình chết giữa chừng): không giữ chỗ mãi. */
    private static function staleReservation(object $q): bool
    {
        return (int) $q->reserved > 0 && $q->reservedAt && now()->diffInSeconds(\Carbon\Carbon::parse($q->reservedAt), true) > 300;
    }

    /**
     * Gọi LLM một lượt trả JSON. Mọi lỗi được bắt lại và trả về dạng dữ liệu.
     * @return array{ok:bool,data?:array,reason?:string,error?:string,ms:int,in:?int,out:?int,model:?string}
     */
    public function json(string $feature, ?int $userId, string $system, string $user, array $schema, array $opt = []): array
    {
        $t0 = microtime(true);
        $why = $this->blocked($feature, $userId);
        if ($why !== null) {
            return ['ok' => false, 'reason' => $why, 'ms' => 0, 'in' => null, 'out' => null, 'model' => null];
        }
        // Đặt trước một lượt (nguyên tử): N request đồng thời khi còn 1 lượt không cùng lọt qua kiểm tra hạn mức
        if (!$this->reserve($userId)) {
            return ['ok' => false, 'reason' => 'quota', 'ms' => 0, 'in' => null, 'out' => null, 'model' => null];
        }
        $llm = $this->factory->make($feature);
        $consumed = false;
        try {
            try {
                $r = $llm->generateJson($system, $user, $schema, $opt + [
                    'name' => $feature,
                    'timeout' => (int) config('ai.timeout.' . $feature, 20),
                    'max_tokens' => (int) config('ai.max_tokens.' . $feature, 900),
                ]);
                Cache::forget(self::cbKey('fails', $feature));
                $consumed = true;

                return ['ok' => true, 'data' => $r['data'], 'ms' => self::ms($t0), 'in' => $r['input_tokens'], 'out' => $r['output_tokens'], 'model' => $llm->model()];
            } catch (SchemaViolation $e) {
                $this->fail($feature);
                $consumed = true;   // model đã trả lời (tốn token), chỉ là sai khuôn

                return ['ok' => false, 'reason' => 'invalid', 'error' => mb_substr($e->getMessage(), 0, 200), 'ms' => self::ms($t0), 'in' => null, 'out' => null, 'model' => $llm->model()];
            } catch (Throwable $e) {
                $this->fail($feature);
                $timeout = str_starts_with($e->getMessage(), 'timeout');

                return ['ok' => false, 'reason' => $timeout ? 'timeout' : 'error', 'error' => mb_substr($e->getMessage(), 0, 200), 'ms' => self::ms($t0), 'in' => null, 'out' => null, 'model' => $llm->model()];
            }
        } finally {
            $this->release($userId, $consumed);
        }
    }

    /**
     * Ghi một lượt xử lý (không ghi nguyên văn câu người dùng trừ khi bật ai.log_text).
     * @param array{feature:string,userId?:?int,tripId?:?int,engine:string,outcome:string,model?:?string,inputChars?:int,
     *              in?:?int,out?:?int,ms?:int,flags?:array,error?:?string,text?:?string} $row
     */
    public function log(array $row): ?int
    {
        if (!$this->hasLog()) {
            return null;
        }
        try {
            return (int) DB::table('tbl_ai_runs')->insertGetId([
                'userId' => $row['userId'] ?? null,
                'tripId' => $row['tripId'] ?? null,
                'feature' => $row['feature'],
                'model' => $row['model'] ?? null,
                'engine' => $row['engine'],
                'promptVersion' => (string) config('ai.prompt_version.' . $row['feature'], 'v0'),
                'inputChars' => min(65000, (int) ($row['inputChars'] ?? 0)),
                'inputTokens' => $row['in'] ?? null,
                'outputTokens' => $row['out'] ?? null,
                'latencyMs' => (int) ($row['ms'] ?? 0),
                'outcome' => $row['outcome'],
                'guardFlags' => !empty($row['flags']) ? json_encode($row['flags'], JSON_UNESCAPED_UNICODE) : null,
                'inputText' => config('ai.log_text') && isset($row['text']) ? mb_substr((string) $row['text'], 0, 500) : null,
                'error' => isset($row['error']) ? mb_substr((string) $row['error'], 0, 160) : null,
                'createdAt' => now(),
            ]);
        } catch (Throwable) {
            return null;   // log là phụ: lỗi ghi log không được làm hỏng trải nghiệm
        }
    }

    /** Lượt đang gọi model của người dùng trong ngày (chỉ dùng khi chưa có bảng tbl_ai_quota). */
    private static function inflightKey(int $userId): string
    {
        return 'ai:quota:inflight:' . $userId . ':' . now()->format('Ymd');
    }

    /**
     * Đặt trước một lượt, nguyên tử: khóa dòng (userId, ngày) của tbl_ai_quota rồi kiểm (đã dùng + đang đặt trước) < hạn mức.
     * Khóa chỉ giữ trong một transaction ngắn, không giữ trong lúc chờ model. Khách (userId null) không có hạn mức theo người.
     */
    private function reserve(?int $userId): bool
    {
        if (!$userId) {
            return true;
        }
        if (self::quotaReady()) {
            $limit = (int) config('ai.limits.daily_per_user', 40);
            $day = now()->toDateString();

            return DB::transaction(function () use ($userId, $day, $limit) {
                DB::table('tbl_ai_quota')->insertOrIgnore(['userId' => $userId, 'day' => $day, 'used' => 0, 'reserved' => 0]);
                $q = DB::table('tbl_ai_quota')->where(['userId' => $userId, 'day' => $day])->lockForUpdate()->first();
                $reserved = self::staleReservation($q) ? 0 : (int) $q->reserved;
                $runs = $this->hasLog() ? DB::table('tbl_ai_runs')->where('userId', $userId)->where('engine', 'llm')->where('createdAt', '>=', now()->startOfDay())->count() : 0;
                if (max($runs, (int) $q->used) + $reserved >= $limit) {
                    return false;
                }
                DB::table('tbl_ai_quota')->where(['userId' => $userId, 'day' => $day])->update(['reserved' => $reserved + 1, 'reservedAt' => now()]);

                return true;
            });
        }
        $lock = Cache::lock('ai:quota:lock:' . $userId, 5);
        if (!$lock->block(3)) {
            return false;
        }
        try {
            $left = $this->remaining($userId);
            $key = self::inflightKey($userId);
            $inflight = (int) Cache::get($key, 0);
            if ($left !== null && $left - $inflight <= 0) {
                return false;
            }
            Cache::put($key, $inflight + 1, 300);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Trả lượt đặt trước: $consumed = model đã trả lời (tính một lượt); gọi lỗi / quá thời gian thì trả lại lượt. */
    private function release(?int $userId, bool $consumed): void
    {
        if (!$userId) {
            return;
        }
        if (self::quotaReady()) {
            DB::transaction(function () use ($userId, $consumed) {
                $day = now()->toDateString();
                $q = DB::table('tbl_ai_quota')->where(['userId' => $userId, 'day' => $day])->lockForUpdate()->first();
                if ($q) {
                    DB::table('tbl_ai_quota')->where(['userId' => $userId, 'day' => $day])->update(['reserved' => max(0, (int) $q->reserved - 1), 'used' => (int) $q->used + ($consumed ? 1 : 0)]);
                }
            });

            return;
        }
        $lock = Cache::lock('ai:quota:lock:' . $userId, 5);
        if ($lock->block(3)) {
            try {
                $key = self::inflightKey($userId);
                $n = (int) Cache::get($key, 0) - 1;
                $n > 0 ? Cache::put($key, $n, 300) : Cache::forget($key);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Ngắt mạch THEO TỪNG TÍNH NĂNG: Copilot (model lớn) lỗi liên tiếp không được khóa luôn Brief / Explain (model nhỏ, có thể khác nhà cung cấp).
     * Khóa chung "ai:cb:until" vẫn được tôn trọng để quản trị / kiểm thử chủ động ngắt toàn bộ.
     */
    public static function cbKey(string $kind, ?string $feature = null): string
    {
        return 'ai:cb:' . $kind . ($feature !== null ? ':' . $feature : '');
    }

    /** Thời điểm (unix) tính năng còn bị ngắt mạch; 0 = không. */
    public static function openUntil(?string $feature = null): int
    {
        $global = (int) Cache::get(self::cbKey('until'), 0);

        return $feature === null ? $global : max($global, (int) Cache::get(self::cbKey('until', $feature), 0));
    }

    /** Các tính năng đang bị ngắt mạch: [feature => giây còn lại]. */
    public static function openCircuits(): array
    {
        $out = [];
        foreach (array_merge(['*' => null], array_combine(self::FEATURES, self::FEATURES)) as $label => $f) {
            $left = ($f === null ? (int) Cache::get(self::cbKey('until'), 0) : (int) Cache::get(self::cbKey('until', $f), 0)) - time();
            if ($left > 0) {
                $out[$label] = $left;
            }
        }

        return $out;
    }

    /** Xóa trạng thái ngắt mạch (mọi tính năng + khóa chung): dùng cho bộ đánh giá, không để lần chạy trước chặn lần sau. */
    public static function resetCircuits(): void
    {
        Cache::forget(self::cbKey('until'));
        Cache::forget(self::cbKey('fails'));
        foreach (self::FEATURES as $f) {
            Cache::forget(self::cbKey('until', $f));
            Cache::forget(self::cbKey('fails', $f));
        }
    }

    private function fail(string $feature): void
    {
        $n = (int) Cache::get(self::cbKey('fails', $feature), 0) + 1;
        Cache::put(self::cbKey('fails', $feature), $n, 600);
        if ($n >= (int) config('ai.cooldown.fail_threshold', 3)) {
            Cache::put(self::cbKey('until', $feature), time() + (int) config('ai.cooldown.seconds', 90), 600);
            Cache::forget(self::cbKey('fails', $feature));
        }
    }

    private ?bool $hasLog = null;

    private function hasLog(): bool
    {
        return $this->hasLog ??= Schema::hasTable('tbl_ai_runs');
    }

    private static function ms(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /** Lý do lùi, viết cho người dùng. */
    public static function reasonText(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'disabled', 'no_llm' => 'Đang dùng chế độ cơ bản (không AI).',
            'circuit' => 'Dịch vụ AI đang bận, tạm dùng chế độ cơ bản trong ít phút.',
            'quota' => 'Bạn đã dùng hết lượt AI hôm nay, tạm dùng chế độ cơ bản.',
            'timeout' => 'AI phản hồi quá lâu nên Travela dùng chế độ cơ bản.',
            default => 'AI chưa trả lời được lúc này nên Travela dùng chế độ cơ bản.',
        };
    }
}
