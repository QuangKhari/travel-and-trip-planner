<?php

namespace App\Services\Rag\Embedding;

use InvalidArgumentException;

final class EmbedderFactory
{
    public const DRIVERS = ['local', 'openai', 'voyage'];

    public function make(?string $driver = null): Embedder
    {
        $driver = $driver ?: (string) config('rag.embed.driver', 'local');
        $batch = (int) config('rag.embed.batch', 32);
        $timeout = (int) config('rag.embed.timeout', 40);

        return match ($driver) {
            'local' => new LocalHashEmbedder((int) config('rag.embed.local.dims', 512)),
            'openai' => new HttpEmbedder('openai', config('rag.embed.openai.base_url'), config('rag.embed.openai.key'),
                config('rag.embed.openai.model'), $batch, $timeout, config('rag.embed.openai.dimensions')),
            'voyage' => new HttpEmbedder('voyage', config('rag.embed.voyage.base_url'), config('rag.embed.voyage.key'),
                config('rag.embed.voyage.model'), $batch, $timeout, null, true),
            default => throw new InvalidArgumentException("Bộ nhúng không hợp lệ: {$driver} (chọn: " . implode(', ', self::DRIVERS) . ')'),
        };
    }
}
