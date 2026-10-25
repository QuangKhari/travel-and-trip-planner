<?php

/**
 * Cấu hình Planner. Một nguồn duy nhất: không rải số "ma thuật" trong code.
 * Mọi giá trị ở đây là ước lượng; mục dùng giá trị mặc định phải được ghi "ước lượng" trong selectionReasons.
 */
return [
    // Quỹ thời gian hoạt động mỗi ngày (phút), không tính ngủ và nghỉ trưa dài
    'day_budget_min' => 480,

    // Tốc độ di chuyển ước lượng (km/giờ) theo đường chim bay nhân hệ số đường vòng
    'travel_speed_kmh' => 25,
    'detour_factor' => 1.4,

    // Thời lượng mặc định (phút) khi mục chưa có durationMin
    'default_duration_min' => [
        'place' => ['see' => 60, 'culture' => 90, 'nature' => 120, 'eat' => 60, 'default' => 60],
        'food' => 60,
        'experience' => 120,
        'event' => 180,
    ],

    // Mức khó tối đa được chọn theo mức thể lực (hard loại khi người dùng không thích đi bộ đường dài)
    'difficulty_rank' => ['easy' => 1, 'moderate' => 2, 'hard' => 3],

    // "Không thích" => điều kiện loại. Địa điểm không có difficulty nên loại theo `kind`.
    // CẦN KIỂM TRA các kind thật trong tbl_external_places trước khi chốt (SELECT DISTINCT kind ...).
    'dislikes' => [
        'trekking' => ['experience_difficulty' => ['hard'], 'place_kinds' => ['peak', 'trail', 'viewpoint_hike'], 'experience_categories' => ['trekking']],
        'crowds' => ['place_kinds' => ['market', 'nightlife']],
        'long_travel' => [],
    ],
];
