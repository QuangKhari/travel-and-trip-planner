<?php

/*
 * Chính sách hủy và hoàn tiền (đặc tả §7.5). Ngày tính bằng startOfDay theo múi giờ ứng dụng (Asia/Ho_Chi_Minh).
 *   Đơn chưa thanh toán: khách hủy tự do trước ngày khởi hành, nhả chỗ.
 *   Đơn đã thanh toán : còn >= 14 ngày hoàn 100%, 7–13 ngày hoàn 50%, < 7 ngày không hủy trên web (liên hệ Travela).
 * Admin hủy đơn đã thanh toán luôn tạo yêu cầu hoàn tiền; mặc định theo bảng dưới, admin được chỉnh % (vd Travela hủy tour -> 100%).
 * Hoàn tiền ở mức đồ án: ghi nhận refund_pending, admin chuyển tiền rồi bấm "Đã hoàn" -> refunded (không gọi API hoàn tiền).
 */
return [
    // Bậc hoàn tiền: [số ngày tối thiểu trước khởi hành => % hoàn], xét từ trên xuống
    'refund_tiers' => [
        14 => 100,
        7 => 50,
    ],

    // Đơn đã thanh toán còn ít hơn số ngày này thì khách không tự hủy trên web được
    'paid_cancel_min_days' => (int) env('BOOKING_PAID_CANCEL_MIN_DAYS', 7),

    // Đơn chưa thanh toán: khách hủy được đến trước ngày khởi hành bấy nhiêu ngày (0 = đến hết hôm trước ngày đi)
    'unpaid_cancel_min_days' => (int) env('BOOKING_UNPAID_CANCEL_MIN_DAYS', 1),

    // Mã giảm giá chỉ được nhả lại cho đơn đã thanh toán khi % hoàn >= giá trị này (100 = chỉ khi hoàn đủ; 0 = luôn nhả như cũ)
    'coupon_release_min_percent' => (int) env('BOOKING_COUPON_RELEASE_MIN_PERCENT', 100),

    // Lịch khởi hành còn <= số ngày này mà chưa đủ khách tối thiểu (và đã có khách) thì gắn cờ "thiếu khách" cho admin
    'shortfall_flag_days' => (int) env('BOOKING_SHORTFALL_FLAG_DAYS', 10),

    // Danh sách chờ: giờ giữ chỗ cho người được mời (không quá 0h ngày khởi hành)
    'waitlist_offer_hours' => (int) env('BOOKING_WAITLIST_OFFER_HOURS', 12),

    // Khách tự đổi số người của đơn được đến trước ngày khởi hành bấy nhiêu ngày (admin đổi được đến hết hôm trước ngày đi)
    'adjust_min_days' => (int) env('BOOKING_ADJUST_MIN_DAYS', 7),

    // Khiếu nại sau tour: số ngày được gửi kể từ khi chuyến đi kết thúc
    'complaint_days' => (int) env('BOOKING_COMPLAINT_DAYS', 30),
];
