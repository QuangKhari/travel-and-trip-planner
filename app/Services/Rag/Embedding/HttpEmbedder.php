<?php

namespace App\Services\Rag\Embedding;

use App\Services\Rag\VectorMath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Nhúng qua HTTP theo chuẩn "POST {base_url}/embeddings" -> {data:[{index, embedding:[...]}]}.
 * Dùng chung cho OpenAI, Voyage AI, Gemini (điểm cuối tương thích OpenAI), Ollama...
 * Voyage có thêm tham số input_type (document|query) giúp tăng chất lượng truy xuất.
 */
final class HttpEmbedder implements Embedder
{
    public function __construct(
        private string $key,          // khóa cấu hình: openai | voyage
        private string $baseUrl,
        private ?string $apiKey,
        private string $model,
        private int $batch = 32,
        private int $timeout = 40,
        private ?int $dimensions = null,
        private bool $inputType = false,
    ) {}

    public function name(): string
    {
        return $this->key . ':' . $this->model . ($this->dimensions ? '@' . $this->dimensions : '');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function embed(array $texts, string $kind = 'document'): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException("Thiếu khóa API cho bộ nhúng '{$this->key}' (xem .env: RAG_EMBED_KEY hoặc VOYAGE_API_KEY).");
        }
        $out = [];
        foreach (array_chunk($texts, max(1, $this->batch)) as $part) {
            $body = ['model' => $this->model, 'input' => array_values($part)];
            if ($this->dimensions) {
                $body['dimensions'] = $this->dimensions;
            }
            if ($this->inputType) {
                $body['input_type'] = $kind === 'query' ? 'query' : 'document';
            }
            $res = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)
                ->retry(3, 800, fn($e) => !($e instanceof \Illuminate\Http\Client\RequestException) || $e->response->status() >= 429, throw: false)
                ->post(rtrim($this->baseUrl, '/') . '/embeddings', $body);
            if (!$res->successful()) {
                throw new RuntimeException("Bộ nhúng '{$this->key}' trả lỗi HTTP " . $res->status() . ': ' . mb_substr($res->body(), 0, 200));
            }
            $rows = $res->json('data');
            if (!is_array($rows) || count($rows) !== count($part)) {
                throw new RuntimeException("Bộ nhúng '{$this->key}' trả về số vector không khớp số đoạn gửi lên.");
            }
            usort($rows, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            foreach ($rows as $r) {
                $out[] = VectorMath::normalize($r['embedding']);
            }
        }

        return $out;
    }
}
