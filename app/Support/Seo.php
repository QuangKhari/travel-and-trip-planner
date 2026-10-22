<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Seo
{
    public const DEFAULT_DESCRIPTION = 'Travela: cẩm nang điểm đến, ẩm thực, lễ hội và lịch trình du lịch Việt Nam, kèm tour đặt trực tuyến theo mùa đẹp.';

    /** Văn bản thuần, gọn, tối đa $max ký tự (dùng cho meta description). */
    public static function text(?string $html, int $max = 158): string
    {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');

        return Str::limit($t, $max, '…');
    }

    /** JSON an toàn để nhúng vào <script type="application/ld+json">. */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /** URL chuẩn: đường dẫn hiện tại không kèm bộ lọc; chỉ giữ ?page khi từ trang 2 trở đi. */
    public static function canonical(): string
    {
        $url = url()->current();
        $page = (int) request()->query('page', 1);

        return $page > 1 ? $url . '?page=' . $page : $url;
    }

    /** Có bộ lọc nào đang bật không ($filters là mảng giá trị lọc của trang danh sách). */
    public static function hasFilter(mixed $filters): bool
    {
        return is_array($filters) && collect($filters)->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty();
    }
}
