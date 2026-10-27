<?php

/**
 * Lịch trình mẫu (kế hoạch v6, mục 6): khung giờ, giới hạn "không quá dày", thời lượng ước lượng khi mục chưa ghi số phút.
 * Quỹ thời gian mỗi ngày lấy từ config/planner.php (day_budget_min) để Planner và lịch trình dùng CÙNG một con số.
 */
return [
    // Khung giờ: khóa => [nhãn, thứ tự]
    'slots' => [
        'morning' => ['Sáng', 1],
        'noon' => ['Trưa', 2],
        'afternoon' => ['Chiều', 3],
        'evening' => ['Tối', 4],
    ],

    // Tối đa số điểm dừng "thật" (không tính di chuyển) trong một ngày trước khi cảnh báo quá dày
    'max_stops_per_day' => 6,

    // Thời lượng ước lượng (phút) khi một điểm dừng chưa ghi durationMin, chỉ để cảnh báo quá dày
    'default_minutes' => [
        'destination' => 60,
        'place' => 60,
        'food' => 60,
        'experience' => 120,
        'event' => 180,
        'transport' => 0,
        'free' => 60,
    ],

    // Giới hạn số phút ghi tay cho một điểm dừng
    'min_minutes' => 5,
    'max_minutes' => 1440,
];
