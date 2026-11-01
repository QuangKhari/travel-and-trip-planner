<?php

namespace App\Services\Ai\Llm;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Gói dự phòng: model chính hết hạn mức (HTTP 429), quá tải (500 / 503) hoặc quá giờ thì gọi model dự phòng.
 * Hạn mức của Gemini tính theo DỰ ÁN + MODEL, nên một model khác (vd gemini-3.1-flash-lite) cùng khóa đã có hạn mức riêng.
 * Model chính hết hạn mức thì đi thẳng sang dự phòng trong ai.fallback.cooldown giây (không tốn thêm một lượt 429).
 * model() trả model ĐÃ trả lời lượt vừa rồi, nên tbl_ai_runs ghi đúng model nào làm việc.
 * Lỗi schema / JSON hỏng KHÔNG chuyển sang dự phòng: đó là lỗi của lời đáp, không phải của nhà cung cấp.
 */
final class FallbackLlm implements StructuredLlm
{
    private string $used;

    public function __construct(private StructuredLlm $primary, private StructuredLlm $backup, private int $cooldown = 600)
    {
        $this->used = $primary->model();
    }

    public function name(): string
    {
        return $this->primary->name() . ' > ' . $this->backup->name();
    }

    public function model(): string
    {
        return $this->used;
    }

    public function available(): bool
    {
        return $this->primary->available() || $this->backup->available();
    }

    public function generate(string $system, string $user): array
    {
        return $this->run(fn(StructuredLlm $l) => $l->generate($system, $user));
    }

    public function generateJson(string $system, string $user, array $schema, array $opt = []): array
    {
        return $this->run(fn(StructuredLlm $l) => $l->generateJson($system, $user, $schema, $opt));
    }

    /** Lỗi phía nhà cung cấp, đáng thử model khác. */
    public static function shouldFallBack(string $msg): bool
    {
        return str_starts_with($msg, 'timeout') || (bool) preg_match('/HTTP (429|500|502|503|504|529)\b/', $msg);
    }

    private function run(callable $call): array
    {
        $down = 'ai:llm:down:' . $this->primary->model();
        if ($this->primary->available() && !Cache::has($down)) {
            try {
                $this->used = $this->primary->model();

                return $call($this->primary);
            } catch (RuntimeException $e) {
                if (!self::shouldFallBack($e->getMessage()) || !$this->backup->available()) {
                    throw $e;
                }
                if (str_contains($e->getMessage(), 'HTTP 429')) {
                    Cache::put($down, true, $this->cooldown);
                }
            }
        }
        $this->used = $this->backup->model();

        return $call($this->backup);
    }
}
