<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Cache;

/**
 * Xóa mọi bộ nhớ đệm hiển thị nội dung công khai sau khi admin đổi nội dung:
 * chỉ mục tìm kiếm, sitemap, dải trang chủ, hero (bưu ảnh), danh sách thực thể của Hỏi đáp.
 * Đồng thời đánh dấu "chỉ mục RAG cần dựng lại" (lịch rag:build chạy mỗi 10 phút nếu có cờ này).
 *
 * Khóa cache phải khớp với nơi tạo ra chúng:
 *   HomeFeedService     'homefeed.v2.m{tháng}.{Ymd}'
 *   HeroPostcardService "postcards.v4.m{tháng}.l{giới hạn}"
 *   QueryAnalyzer       'rag.entities.v1'
 * Đổi phiên bản khóa ở các service đó thì phải đổi ở đây.
 */
final class PublicCache
{
    public static function flush(bool $markRag = true): void
    {
        SearchCatalog::forget();
        Cache::forget('sitemap.xml.v1');
        Cache::forget('rag.entities.v1');
        Cache::forever('monthbrief.gen', (string) microtime(true));   // đổi thế hệ: vô hiệu mọi DestinationMonthBrief đã cache

        $limit = (int) config('postcards.limit', 8);
        $days = [date('Ymd'), date('Ymd', strtotime('-1 day'))];   // phòng khi vừa qua nửa đêm
        for ($m = 1; $m <= 12; $m++) {
            foreach ($days as $d) {
                Cache::forget("homefeed.v2.m{$m}.{$d}");
            }
            Cache::forget("postcards.v4.m{$m}.l{$limit}");
        }

        if ($markRag) {
            Cache::put('rag.dirty', 1, 86400);
        }
    }
}
