<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Ask\QuestionRouter;
use PHPUnit\Framework\TestCase;

/** F5: chia câu gửi La bàn thành HỎI (trả lời có nguồn) hay SỬA (Copilot). */
class QuestionRouterTest extends TestCase
{
    public function test_questions_go_to_ask(): void
    {
        foreach (['Ngày 2 có nơi nào cho trẻ nhỏ không?', 'Cần mang áo mưa không?', 'Đồ ăn phải thử ở đây là gì?', 'Chuyến này cần mang gì?',
            'ngay 2 co mua khong', 'Văn Miếu mấy giờ mở cửa', 'Có quán ăn nào gần Văn Miếu không?'] as $q) {
            $this->assertTrue(QuestionRouter::isQuestion($q), $q);
        }
    }

    public function test_edit_requests_go_to_copilot_even_when_phrased_as_question(): void
    {
        foreach (['Thêm quán cà phê được không?', 'Ngày 1 nhẹ hơn', 'chuyen bao tang sang ngay 2', 'đổi Hồ Tây sang buổi chiều', 'ngày 1 quá dày',
            'Xếp lại ngày 2 cho gọn?'] as $q) {
            $this->assertFalse(QuestionRouter::isQuestion($q), $q);
        }
    }

    public function test_trip_noun_is_not_move_verb(): void
    {
        $this->assertTrue(QuestionRouter::isQuestion('Chuyến này có cần đặt vé trước không?'), '"chuyến" (danh từ) khác "chuyển" (động từ)');
        $this->assertTrue(QuestionRouter::isQuestion('Bạn thấy ngày 2 thế nào?'), '"thấy" khác "thay"');
    }
}
