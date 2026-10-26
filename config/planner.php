<?php

/**
 * Cấu hình Planner (mục 0.1-B/E/K của kế hoạch v6). Một nguồn duy nhất: không rải số "ma thuật" trong code.
 * Mọi giá trị ở đây là ước lượng; mục dùng giá trị mặc định phải được ghi "ước lượng" trong lý do chọn.
 * Tên cột khớp migration 2026_10_26_000001_link_content_and_planner: durationMinutes, difficulty (easy|medium|hard), indoor.
 */
return [
    // Quỹ thời gian hoạt động mỗi ngày (phút), không tính ngủ và nghỉ trưa dài
    'day_budget_min' => 480,

    // Tốc độ di chuyển ước lượng (km/giờ) theo đường chim bay nhân hệ số đường vòng
    'travel_speed_kmh' => 25,
    'detour_factor' => 1.4,

    // Thời lượng mặc định (phút) khi mục chưa có durationMinutes. Địa điểm tra theo nhóm kind (xem kind_groups).
    'default_duration_min' => [
        'place' => ['eat' => 60, 'nature' => 120, 'culture' => 90, 'see' => 60, 'fun' => 150, 'default' => 60],
        'food' => 60,
        'experience' => 120,
        'event' => 180,
    ],

    'difficulty_rank' => ['easy' => 1, 'medium' => 2, 'hard' => 3],

    // `tbl_external_places.kind` thật trong dữ liệu (đếm ngày 25/10/2026) gom thành 5 nhóm.
    // Kind chưa có trong danh sách (OSM thêm kind mới) rơi vào nhóm 'see'. Cập nhật khi SyncPlaces mang về kind mới.
    'kind_groups' => [
        'eat' => ['restaurant', 'cafe'],
        'nature' => ['peak', 'hot_spring', 'cave_entrance', 'viewpoint', 'beach'],
        'culture' => ['historic', 'place_of_worship', 'museum', 'gallery'],
        'fun' => ['theme_park', 'zoo', 'aquarium'],
        'see' => ['attraction'],
    ],

    // "Không thích" => điều kiện loại. Địa điểm không có difficulty nên loại theo kind; trải nghiệm loại theo difficulty.
    'dislikes' => [
        'trekking' => ['experience_difficulty' => ['hard'], 'place_kinds' => ['peak', 'cave_entrance']],
        'crowds' => ['place_kinds' => ['theme_park', 'zoo', 'aquarium']],
        'outdoor' => ['experience_indoor' => [false], 'place_kinds' => ['peak', 'beach', 'viewpoint', 'cave_entrance', 'hot_spring']],
        'long_travel' => [],
    ],
];
