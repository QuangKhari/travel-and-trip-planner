<?php

namespace App\Services\Ai;

use App\Services\Ai\Llm\AiLlmFactory;
use App\Services\Ai\Llm\StructuredLlm;
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
    public const FEATURES = ['brief', 'explain', 'copilot'];

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
        if ((int) Cache::get('ai:cb:until', 0) > time()) {
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
        if (!$this->hasLog()) {
            return $limit;
        }
        $used = DB::table('tbl_ai_runs')->where('userId', $userId)->where('engine', 'llm')
            ->where('createdAt', '>=', now()->startOfDay())->count();

        return max(0, $limit - $used);
    }

    /**
     * Gọi LLM một lượt trả JSON. Mọi lỗi được bắt lại và trả về dạng dữ liệu.
     * @return array{ok:bool,data?:array,reason?:string,error?:string,ms:int,in:?int,out:?int,model:?string}
     */
    public function json(string $feature, ?int $userId, string $system, string $user, array $schema): array
    {
        $t0 = microtime(true);
        $why = $this->blocked($feature, $userId);
        if ($why !== null) {
            return ['ok' => false, 'reason' => $why, 'ms' => 0, 'in' => null, 'out' => null, 'model' => null];
        }
        $llm = $this->factory->make($feature);
        try {
            $r = $llm->generateJson($system, $user, $schema, [
                'name' => $feature,
                'timeout' => (int) config('ai.timeout.' . $feature, 20),
                'max_tokens' => (int) config('ai.max_tokens.' . $feature, 900),
            ]);
            Cache::forget('ai:cb:fails');

            return ['ok' => true, 'data' => $r['data'], 'ms' => self::ms($t0), 'in' => $r['input_tokens'], 'out' => $r['output_tokens'], 'model' => $llm->model()];
        } catch (SchemaViolation $e) {
            $this->fail();

            return ['ok' => false, 'reason' => 'invalid', 'error' => mb_substr($e->getMessage(), 0, 150), 'ms' => self::ms($t0), 'in' => null, 'out' => null, 'model' => $llm->model()];
        } catch (Throwable $e) {
            $this->fail();
            $timeout = str_starts_with($e->getMessage(), 'timeout');

            return ['ok' => false, 'reason' => $timeout ? 'timeout' : 'error', 'error' => mb_substr($e->getMessage(), 0, 150), 'ms' => self::ms($t0), 'in' => null, 'out' => null, 'model' => $llm->model()];
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

    private function fail(): void
    {
        $n = (int) Cache::get('ai:cb:fails', 0) + 1;
        Cache::put('ai:cb:fails', $n, 600);
        if ($n >= (int) config('ai.cooldown.fail_threshold', 3)) {
            Cache::put('ai:cb:until', time() + (int) config('ai.cooldown.seconds', 90), 600);
            Cache::forget('ai:cb:fails');
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
