<?php

namespace App\Services\Rag\Llm;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Chuẩn POST {base_url}/chat/completions (OpenAI, Gemini bản tương thích, Ollama...). */
final class OpenAiCompatLlm implements Llm
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com/v1',
        private int $maxTokens = 600,
        private float $temperature = 0.2,
        private int $timeout = 45,
    ) {}

    public function name(): string
    {
        return 'openai:' . $this->model;
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    public function generate(string $system, string $user): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Thiếu RAG_LLM_KEY.');
        }
        $res = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)
            ->retry(2, 700, fn($e) => !($e instanceof \Illuminate\Http\Client\RequestException) || $e->response->status() >= 429, throw: false)
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ]);
        if (!$res->successful()) {
            throw new RuntimeException('Dịch vụ LLM trả lỗi HTTP ' . $res->status() . ': ' . mb_substr($res->body(), 0, 200));
        }

        return [
            'text' => trim((string) $res->json('choices.0.message.content')),
            'input_tokens' => $res->json('usage.prompt_tokens'),
            'output_tokens' => $res->json('usage.completion_tokens'),
        ];
    }
}
