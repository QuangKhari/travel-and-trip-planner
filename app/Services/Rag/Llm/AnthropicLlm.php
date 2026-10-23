<?php

namespace App\Services\Rag\Llm;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Claude qua Messages API: POST {base_url}/v1/messages. */
final class AnthropicLlm implements Llm
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com',
        private string $version = '2023-06-01',
        private int $maxTokens = 600,
        private float $temperature = 0.2,
        private int $timeout = 45,
    ) {}

    public function name(): string
    {
        return 'anthropic:' . $this->model;
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    public function generate(string $system, string $user): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Thiếu ANTHROPIC_API_KEY.');
        }
        $res = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
            ->acceptJson()->timeout($this->timeout)
            ->retry(2, 700, fn($e) => !($e instanceof \Illuminate\Http\Client\RequestException) || $e->response->status() >= 429, throw: false)
            ->post(rtrim($this->baseUrl, '/') . '/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);
        if (!$res->successful()) {
            throw new RuntimeException('Anthropic trả lỗi HTTP ' . $res->status() . ': ' . mb_substr($res->body(), 0, 200));
        }
        $text = '';
        foreach ((array) $res->json('content') as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return [
            'text' => trim($text),
            'input_tokens' => $res->json('usage.input_tokens'),
            'output_tokens' => $res->json('usage.output_tokens'),
        ];
    }
}
