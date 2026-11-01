<?php

namespace App\Services\Ai\Llm;

use App\Services\Ai\SchemaViolation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude qua Messages API. JSON lấy bằng cách ÉP gọi đúng một tool có input_schema = schema
 * (tool_choice = tool). Cách này chạy trên mọi model hỗ trợ tool use, không phụ thuộc tính năng beta.
 */
final class AnthropicJsonLlm implements StructuredLlm
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com',
        private string $version = '2023-06-01',
        private int $maxTokens = 900,
        private float $temperature = 0.1,
        private int $timeout = 20,
    ) {}

    public function name(): string
    {
        return 'anthropic:' . $this->model;
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
        $text = '';
        foreach ((array) ($r['content'] ?? []) as $b) {
            if (($b['type'] ?? null) === 'text') {
                $text .= $b['text'] ?? '';
            }
        }

        return ['text' => trim($text), 'input_tokens' => $r['usage']['input_tokens'] ?? null, 'output_tokens' => $r['usage']['output_tokens'] ?? null];
    }

    public function generateJson(string $system, string $user, array $schema, array $opt = []): array
    {
        $tool = $opt['name'] ?? 'ket_qua';
        $r = $this->post($system, $user, [
            'tools' => [['name' => $tool, 'description' => 'Trả kết quả có cấu trúc.', 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'tool', 'name' => $tool],
        ], (int) ($opt['timeout'] ?? $this->timeout), (int) ($opt['max_tokens'] ?? $this->maxTokens));

        foreach ((array) ($r['content'] ?? []) as $b) {
            if (($b['type'] ?? null) === 'tool_use' && is_array($b['input'] ?? null)) {
                return ['data' => $b['input'], 'input_tokens' => $r['usage']['input_tokens'] ?? null,
                    'output_tokens' => $r['usage']['output_tokens'] ?? null, 'raw' => json_encode($b['input'], JSON_UNESCAPED_UNICODE)];
            }
        }
        throw new SchemaViolation('Mô hình không trả về khối tool_use.');
    }

    private function post(string $system, string $user, array $extra, int $timeout, int $maxTokens): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Thiếu ANTHROPIC_API_KEY.');
        }
        try {
            // Không retry ở đây: AiGateway quyết định lùi về luật thay vì để người dùng chờ thêm.
            $res = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
                ->acceptJson()->connectTimeout(5)->timeout($timeout)
                ->post(rtrim($this->baseUrl, '/') . '/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'temperature' => $this->temperature,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ] + $extra);
        } catch (ConnectionException $e) {
            throw new RuntimeException('timeout: ' . mb_substr($e->getMessage(), 0, 120), 0, $e);
        }
        if (!$res->successful()) {
            throw new RuntimeException('Anthropic HTTP ' . $res->status() . ': ' . mb_substr($res->body(), 0, 160));
        }

        return (array) $res->json();
    }
}
