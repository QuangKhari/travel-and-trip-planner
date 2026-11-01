<?php

namespace App\Services\Ai\Ask;

use App\Services\Content\SearchCatalog;

/**
 * Chia câu gửi La bàn thành "hỏi" (F5: trả lời có nguồn, không đổi lịch) hay "sửa" (F3 Copilot). Luật, không gọi LLM.
 *   - Có động từ sửa lịch (chuyển, thêm, đổi, dời, bỏ, xếp lại, nhẹ hơn...) thì là SỬA, kể cả khi viết dạng câu hỏi
 *     ("thêm quán cà phê được không?").
 *   - Còn lại: có dấu "?" hoặc từ để hỏi thì là HỎI.
 *   - Mơ hồ thì để Copilot (nó cũng trả lời được, chỉ là không kèm nguồn cẩm nang).
 * Kiểm cả bản CÓ DẤU (chính xác: "chuyển" khác "chuyến", "thấy" khác "thay") lẫn cụm KHÔNG DẤU không nhập nhằng.
 */
final class QuestionRouter
{
    private const EDIT_VI = ['chuyển', 'thêm', 'đổi', 'dời', 'bớt', 'bỏ', 'xóa', 'xoá', 'xếp', 'thay bằng', 'thay thế', 'thay cho', 'nhẹ hơn', 'gọn hơn',
        'cho gọn', 'đưa', 'cho vào', 'sửa', 'tối ưu', 'cân bằng', 'dự phòng', 'đặt giờ', 'lùi', 'sớm hơn', 'muộn hơn', 'hoán', 'gộp', 'tách'];
    private const EDIT_ASCII = ['chuyen sang', 'chuyen qua', 'chuyen ve', 'them vao', 'them mot', 'doi sang', 'doi lai', 'doi gio', 'doi cho', 'doi ngay',
        'nhe hon', 'xep lai', 'sap xep', 'thay bang', 'bo bot', 'bot di', 'bot mot', 'toi uu', 'can bang', 'dua sang', 'dua ve', 'som hon', 'muon hon'];
    private const ASK = ['co', 'khong', 'nen', 'can', 'la gi', 'bao nhieu', 'o dau', 'may gio', 'luc nao', 'the nao', 'gi', 'nao', 'sao', 'mo cua',
        'thoi tiet', 'mang', 'an gi', 'phai thu', 'dac san', 'bao lau', 'mua', 'nong', 'lanh'];

    public static function isQuestion(string $text): bool
    {
        $low = ' ' . mb_strtolower($text) . ' ';
        foreach (self::EDIT_VI as $w) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '(?![\p{L}])/u', $low)) {
                return false;
            }
        }
        $f = ' ' . SearchCatalog::fold($text) . ' ';
        foreach (self::EDIT_ASCII as $w) {
            if (str_contains($f, ' ' . $w . ' ')) {
                return false;
            }
        }
        if (str_contains($text, '?')) {
            return true;
        }
        foreach (self::ASK as $w) {
            if (str_contains($f, ' ' . $w . ' ')) {
                return true;
            }
        }

        return false;
    }
}
