<?php

namespace App\Services\Rag\Embedding;

use App\Services\Content\SearchCatalog;
use App\Services\Rag\VectorMath;

/**
 * Nhúng "bỏ túi" không cần mạng: băm từ đơn và cặp từ (đã bỏ dấu) vào vector 512 chiều có dấu rồi chuẩn hóa.
 * Đây thực chất là so khớp từ vựng chứ không hiểu ngữ nghĩa, nên dùng để:
 *  - chạy thử toàn bộ đường ống khi chưa có khóa API;
 *  - kiểm thử tự động (kết quả cố định, không gọi mạng);
 *  - làm MỐC SO SÁNH trong rag:bench (mô hình thật phải hơn mốc này thì mới đáng dùng).
 */
final class LocalHashEmbedder implements Embedder
{
    public function __construct(private int $dims = 512) {}

    public function name(): string
    {
        return 'local-hash-' . $this->dims;
    }

    public function key(): string
    {
        return 'local';
    }

    public function embed(array $texts, string $kind = 'document'): array
    {
        return array_map(fn(string $t) => $this->one($t), $texts);
    }

    private function one(string $text): array
    {
        $tokens = array_values(array_filter(explode(' ', SearchCatalog::fold($text)), fn($t) => $t !== ''));
        $v = array_fill(0, $this->dims, 0.0);
        $add = function (string $feature, float $w) use (&$v) {
            $h = crc32($feature);
            $idx = $h % $this->dims;
            $sign = (($h >> 16) & 1) ? 1.0 : -1.0;
            $v[$idx] += $sign * $w;
        };
        foreach ($tokens as $i => $t) {
            $add('u:' . $t, 1.0);
            if (isset($tokens[$i + 1])) {
                $add('b:' . $t . ' ' . $tokens[$i + 1], 0.7);
            }
        }

        return VectorMath::normalize($v);
    }
}
