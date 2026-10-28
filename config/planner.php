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

    // ---- Tuần 5: PlannerEngine ----

    // Số ngày tối đa cho một lần tạo; số mục "hoạt động" mỗi ngày theo nhịp (không tính bữa ăn)
    'max_days' => 7,
    'pace' => [
        'relaxed' => ['min_per_day' => 2, 'max_per_day' => 3, 'budget_factor' => 0.7],
        'normal' => ['min_per_day' => 3, 'max_per_day' => 4, 'budget_factor' => 1.0],
        'packed' => ['min_per_day' => 4, 'max_per_day' => 6, 'budget_factor' => 1.2],
    ],
    'default_pace' => 'normal',

    // Bữa ăn: mỗi ngày tối đa bao nhiêu mục "ăn uống" (trưa + tối)
    'meals_per_day' => 2,
    'meal_minutes' => 60,

    // Độ khó tối đa theo nhóm (khi người dùng chọn): easy | medium | hard. Mặc định không giới hạn.
    'default_max_difficulty' => 'hard',

    // Điểm (soft constraint). Cộng dồn; điểm chỉ dùng để XẾP HẠNG ứng viên, không phải "độ hợp" tuyệt đối.
    'score' => [
        'must_try' => 30,           // biên tập viên đánh dấu "phải thử/ghé"
        'interest_match' => 18,     // mỗi sở thích khớp (tối đa 2 sở thích được tính)
        'interest_cap' => 2,
        'in_season' => 12,          // trải nghiệm/món/lễ hội có mùa trùng tháng đi
        'event_in_month' => 25,     // lễ hội diễn ra đúng tháng đi
        'rank_max' => 10,           // thang điểm từ trường `rank` của địa điểm (chuẩn hóa theo ứng viên cao nhất)
        'rain_indoor' => 8,         // tháng mưa nhiều: ưu tiên mục trong nhà
        'rain_outdoor' => -8,       // tháng mưa nhiều: trừ mục ngoài trời
        'hot_outdoor_hard' => -6,   // tháng nắng nóng: trừ mục ngoài trời khó
        'avoid_month_outdoor' => -4,
    ],
    'rainy_share' => 0.4,           // tỷ lệ ngày mưa từ mức này là "mưa nhiều"
    'hot_tmax' => 34,

    // Khung giờ trong ngày (giờ bắt đầu từ phút 0 = 08:00). Ranh giới đổi khung theo phút kể từ 08:00.
    'day_start_hour' => 8,
    'slot_bounds_min' => ['morning' => [0, 210], 'noon' => [210, 330], 'afternoon' => [330, 600], 'evening' => [600, 1440]],

    // Hai điểm cách nhau hơn mức này (km, đường chim bay) thì không xếp chung một ngày khi còn lựa chọn khác
    'max_leg_km' => 60,
    'max_travel_min_per_day' => 180,

    // Tour (khối riêng, không cộng vào chi phí chuyến)
    'tours_max' => 3,
    'tour_window_days' => 14,       // khi biết ngày đi: lấy tour khởi hành trong ± số ngày này
];
