<?php

namespace App\Services\Ai\Llm;

use App\Services\Rag\Llm\Llm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/**
 * Chuỗi dự phòng: model chính -> dự phòng 1 (vd Groq) -> dự phòng 2 (vd Cerebras).
 * Một mắt xích báo hết hạn mức (HTTP 429), quá tải (5xx) hoặc quá giờ thì gọi mắt xích sau.
 * Gemini / Groq tính hạn mức theo dự án + model, nên model khác hoặc hãng khác đã có hạn mức riêng.
 * Mắt xích hết hạn mức thì bị bỏ qua trong $cooldown giây (không tốn thêm một lượt 429);
 * quá giờ / quá tải thì bỏ qua tối đa 2 phút (không bắt người dùng chờ hết thời gian chờ ở mỗi lượt).
 * Mắt xích cuối luôn được thử. model() trả model ĐÃ trả lời lượt vừa rồi (ghi đúng vào tbl_ai_runs).
 * Lỗi schema / JSON hỏng KHÔNG chuyển mắt xích: đó là lỗi của lời đáp, không phải của nhà cung cấp.
 * Dùng cho cả La bàn (generateJson) lẫn Hỏi đáp / F5 (generate, các Llm của RAG).
 */
final class FallbackLlm implements StructuredLlm
{
    /** @var list<Llm> */
    private array $chain;
    private string $used;

    /** @param Llm|list<Llm> $backups */
    public function __construct(Llm $primary, Llm|array $backups, private int $cooldown = 600)
    {
        $this->chain = array_values(array_merge([$primary], is_array($backups) ? $backups : [$backups]));
        $this->used = self::modelOf($primary);
    }

    /** Tên ổn định (dùng trong khóa cache câu trả lời của Hỏi đáp): theo model chính. */
    public function name(): string
    {
        return $this->chain[0]->name() . ' (+' . (count($this->chain) - 1) . ' dự phòng)';
    }

    public function model(): string
    {
        return $this->used;
    }

    public function available(): bool
    {
        foreach ($this->chain as $l) {
            if ($l->available()) {
                return true;
            }
        }

        return false;
    }

    public function generate(string $system, string $user): array
    {
        return $this->run(fn(Llm $l) => $l->generate($system, $user));
    }

    public function generateJson(string $system, string $user, array $schema, array $opt = []): array
    {
        return $this->run(function (Llm $l) use ($system, $user, $schema, $opt) {
            if (!$l instanceof StructuredLlm) {
                throw new LogicException($l->name() . ' không hỗ trợ JSON theo schema.');
            }

            return $l->generateJson($system, $user, $schema, $opt);
        });
    }

    /** Lỗi phía nhà cung cấp, đáng thử mắt xích khác. */
    public static function shouldFallBack(Throwable|string $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }
        $msg = is_string($e) ? $e : $e->getMessage();

        return str_starts_with($msg, 'timeout') || str_contains($msg, 'cURL error 28')
            || (bool) preg_match('/HTTP (429|500|502|503|504|529)\b/', $msg);
    }

    private static function modelOf(Llm $l): string
    {
        return $l instanceof StructuredLlm ? $l->model() : (string) preg_replace('/^[a-z]+:/', '', $l->name());
    }

    private function run(callable $call): array
    {
        $last = count($this->chain) - 1;
        $error = null;
        foreach ($this->chain as $i => $l) {
            // theo VỊ TRÍ trong chuỗi + tên: cùng một model ở hai dự án / hai khóa (vd dự phòng = cùng model, khóa khác) vẫn được tính riêng
            $down = 'ai:llm:down:' . $i . ':' . $l->name();
            if (!$l->available() || ($i < $last && Cache::has($down))) {
                continue;
            }
            try {
                $this->used = self::modelOf($l);

                return $call($l);
            } catch (Throwable $e) {
                if (!self::shouldFallBack($e) || $i === $last) {
                    throw $e;
                }
                $error = $e;
                Cache::put($down, true, str_contains($e->getMessage(), 'HTTP 429') ? $this->cooldown : min($this->cooldown, 120));
            }
        }
        if ($error) {
            throw $error;
        }
        // mọi mắt xích đều đang "nghỉ" mà mắt xích cuối không dùng được: thử lại mắt xích đầu tiên có khóa
        foreach ($this->chain as $l) {
            if ($l->available()) {
                $this->used = self::modelOf($l);

                return $call($l);
            }
        }
        throw new \RuntimeException('Không có LLM nào dùng được.');
    }
}
