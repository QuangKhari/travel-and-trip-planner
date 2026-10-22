<?php

namespace App\Services\Content;

use App\Services\EventDateService;

/**
 * A7 / tài liệu mục 8.6: điều kiện để một bản ghi được chuyển sang "published".
 * Trả về danh sách lý do chưa đạt (rỗng = đạt).
 */
final class PublishGate
{
    /** @return list<string> */
    public static function problems(string $kind, object $row): array
    {
        if ($kind === 'places') {
            return trim((string) ($row->name ?? '')) === '' ? ['Thiếu tên địa điểm'] : [];
        }

        $p = [];
        $text = match ($kind) {
            'guides' => $row->body ?? '',
            'itineraries' => $row->summary ?? '',
            default => $row->description ?? '',
        };
        if (mb_strlen(trim((string) $text)) < 30) {
            $p[] = 'Nội dung/mô tả quá ngắn (dưới 30 ký tự)';
        }
        if (empty($row->license)) {
            $p[] = 'Thiếu giấy phép nội dung';
        } elseif ($row->license !== 'Tự viết' && empty($row->sourceUrl)) {
            $p[] = 'Thiếu liên kết nguồn (sourceUrl)';
        }
        if (!empty($row->coverImage) && empty($row->imageCredit)) {
            $p[] = 'Có ảnh nhưng thiếu ghi công ảnh';
        }
        if ($kind === 'guides' && empty($row->updatedOn)) {
            $p[] = 'Bài cẩm nang phải có ngày "Cập nhật lần cuối" (lưu bài một lần trong màn hình sửa là tự có)';
        }
        if ($kind === 'foods' && empty($row->destinationId)) {
            $p[] = 'Món ăn phải gắn với một điểm đến';
        }
        if ($kind === 'events' && !(new EventDateService())->upcoming($row, new \DateTimeImmutable('today'), 1)) {
            $p[] = 'Quy tắc ngày không tính ra được ngày nào (kiểm tra tháng/ngày âm hoặc dương)';
        }

        return $p;
    }
}
