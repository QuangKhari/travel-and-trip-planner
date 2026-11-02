<?php

namespace App\Services\Ai\Llm;

use App\Services\Ai\SchemaViolation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chuẩn POST {base_url}/chat/completions (OpenAI, Gemini bản tương thích, Ollama...).
 * JSON lấy bằng response_format json_schema (strict = false để chấp nhận schema có kiểu null lồng nhau);
 * nhà cung cấp không hỗ trợ json_schema thì đặt AI_OPENAI_JSON_MODE=object để dùng json_object.
 */
final class OpenAiJsonLlm implements StructuredLlm
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com/v1',
        private int $maxTokens = 900,
        private float $temperature = 0.1,
        private int $timeout = 20,
        private string $jsonMode = 'schema',
        private ?string $reasoning = null,   // low | medium | high (model có suy luận, vd Gemini 3.x); null = không gửi
    ) {}

    public function name(): string
    {
        return 'openai:' . $this->model;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    public function generate(string $system, string $user): array
    {
        $r = $this->post($system, $user, [], $this->timeout, $this->maxTokens);

        return ['text' => trim((string) ($r['choices'][0]['message']['content'] ?? '')),
            'input_tokens' => $r['usage']['prompt_tokens'] ?? null, 'output_tokens' => $r['usage']['completion_tokens'] ?? null];
    }

    public function generateJson(string $system, string $user, array $schema, array $opt = []): array
    {
        $format = $this->jsonMode === 'object'
            ? ['type' => 'json_object']
            : ['type' => 'json_schema', 'json_schema' => ['name' => $opt['name'] ?? 'ket_qua', 'schema' => $schema, 'strict' => false]];
        if ($this->jsonMode === 'object') {
            $system .= "\n\nChỉ trả về MỘT đối tượng JSON theo schema sau, không thêm chữ nào khác:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE);
        }
        $timeout = (int) ($opt['timeout'] ?? $this->timeout);
        $maxTokens = (int) ($opt['max_tokens'] ?? $this->maxTokens);
        // Nhà cung cấp từ chối json_schema của tác vụ này (vd Gemini: "Request contains an invalid argument" vì từ khóa schema không hỗ trợ)
        // -> dùng json_object + schema trong lời nhắc; nhớ 1 ngày để không tốn thêm một lượt gọi lỗi mỗi lần.
        $memo = 'ai:llm:objmode:' . $this->model . ':' . ($opt['name'] ?? 'ket_qua');
        if ($this->jsonMode !== 'object' && Cache::get($memo)) {
            $r = $this->postObject($system, $user, $schema, $timeout, $maxTokens);
        } else {
            try {
                $r = $this->post($system, $user, ['response_format' => $format], $timeout, $maxTokens);
            } catch (RuntimeException $e) {
                if ($this->jsonMode === 'object' || !self::schemaRejected($e->getMessage())) {
                    throw $e;
                }
                Cache::put($memo, true, 86400);
                $r = $this->postObject($system, $user, $schema, $timeout, $maxTokens);
            }
        }
        $raw = (string) ($r['choices'][0]['message']['content'] ?? '');
        $data = json_decode(self::stripFence($raw), true);
        if (!is_array($data)) {
            $finish = (string) ($r['choices'][0]['finish_reason'] ?? '');
            throw new SchemaViolation($finish === 'length'
                ? 'JSON bị cắt vì hết max_tokens (' . $maxTokens . '): tăng AI_MAX_TOKENS_* của tính năng này.'
                : 'Không đọc được JSON từ mô hình' . ($finish !== '' && $finish !== 'stop' ? ' (finish_reason=' . $finish . ')' : '') . ': ' . mb_substr($raw, 0, 60));
        }

        return ['data' => $data, 'input_tokens' => $r['usage']['prompt_tokens'] ?? null, 'output_tokens' => $r['usage']['completion_tokens'] ?? null, 'raw' => $raw];
    }

    private function postObject(string $system, string $user, array $schema, int $timeout, int $maxTokens): array
    {
        $system .= "\n\nChỉ trả về MỘT đối tượng JSON theo schema sau, không thêm chữ nào khác:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE);

        return $this->post($system, $user, ['response_format' => ['type' => 'json_object']], $timeout, $maxTokens);
    }

    /** HTTP 400 vì response_format / schema (không phải vì khóa, model hay hạn mức). */
    public static function schemaRejected(string $msg): bool
    {
        // 400 do model / khóa / tham số khác thì thử lại cũng vô ích
        // (vd Groq: "json_schema is not supported with this model" -> vẫn thử lại; "model is not specified" -> không)
        return str_starts_with($msg, 'LLM HTTP 400')
            && !preg_match('/model (is )?not (specified|found)|invalid model|does not exist|api[ _-]?key|max_tokens|reasoning|temperature|quota/i', $msg);
    }

    /** Một số mô hình vẫn bọc JSON trong ```json ... ```. */
    public static function stripFence(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $s, $m)) {
            return $m[1];
        }

        return $s;
    }

    private function post(string $system, string $user, array $extra, int $timeout, int $maxTokens): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Thiếu khóa API (RAG_LLM_KEY).');
        }
        try {
            $res = Http::withToken($this->apiKey)->acceptJson()->connectTimeout(5)->timeout($timeout)
                ->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'temperature' => $this->temperature,
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                ] + ($this->reasoning ? ['reasoning_effort' => $this->reasoning] : []) + $extra);
        } catch (ConnectionException $e) {
            throw new RuntimeException('timeout: ' . mb_substr($e->getMessage(), 0, 120), 0, $e);
        }
        if (!$res->successful()) {
            // lấy đúng câu báo lỗi của nhà cung cấp (OpenAI: {error:{message}}, Gemini: [{error:{message}}]) cho log dễ đọc
            $j = $res->json();
            $msg = is_array($j) ? ($j['error']['message'] ?? $j[0]['error']['message'] ?? null) : null;
            $msg = preg_replace('/\s+/u', ' ', (string) ($msg ?? $res->body())) ?? '';
            // Groq: bỏ "in organization `org_…` service tier `…`" để phần quan trọng (TPD / TPM, "try again in 7m12s") còn lọt trong 160 ký tự
            $msg = preg_replace('/ in organization `[^`]*`( service tier `[^`]*`)?/u', '', $msg) ?? $msg;
            throw new RuntimeException('LLM HTTP ' . $res->status() . ': ' . mb_substr($msg, 0, 160));
        }

        return (array) $res->json();
    }
}
