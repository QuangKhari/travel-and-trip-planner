<?php

/**
 * Chấm điểm mức đủ nội dung của từng điểm đến (mục 9 và 0.2 của kế hoạch v6). MỘT nguồn duy nhất cho CLI, Admin và test.
 *
 * Mỗi tiêu chí: 'max' = số điểm tối đa, 'target' = số lượng đạt điểm tối đa. Điểm tiêu chí = max × min(1, có / target).
 * Tổng 'max' của các tiêu chí = 100. % = làm tròn( điểm đạt / tổng điểm các tiêu chí ÁP DỤNG × 100 ), làm tròn về số nguyên.
 */
return [
    'criteria' => [
        'months' => ['label' => '12 tháng (mức đẹp)', 'max' => 10, 'target' => 12],
        'climate' => ['label' => 'Khí hậu 12 tháng', 'max' => 5, 'target' => 12],
        'places' => ['label' => 'Địa điểm đã duyệt', 'max' => 20, 'target' => 30],
        'foods' => ['label' => 'Món đã duyệt', 'max' => 15, 'target' => 10],
        'events' => ['label' => 'Lễ hội đã duyệt', 'max' => 10, 'target' => 3],
        'experiences' => ['label' => 'Trải nghiệm đã duyệt', 'max' => 15, 'target' => 5],
        'itineraries' => ['label' => 'Lịch trình một điểm đến', 'max' => 10, 'target' => 1],
        'tours' => ['label' => 'Tour liên kết', 'max' => 5, 'target' => 1],
        'cover' => ['label' => 'Ảnh bìa + ghi công', 'max' => 5, 'target' => 2],
        'intro' => ['label' => 'Giới thiệu', 'max' => 5, 'target' => 1],
    ],

    // Độ dài tối thiểu (ký tự) của phần giới thiệu điểm đến (description hoặc wikiExtract)
    'intro_min_chars' => 80,

    // Điểm đến không có tour phù hợp: bỏ tiêu chí "tour" khỏi mẫu số (mẫu số 95 thay vì 100). Ghi slug => lý do.
    'tour_waiver' => [
        // 'con-dao' => 'Chưa có tour phù hợp',
    ],

    // Điểm đến không có lễ hội đáng kể: được miễn điều kiện "≥1 lễ hội" của Planner-ready. Ghi slug => lý do (bắt buộc có lý do).
    'event_waiver' => [
        // 'phu-quoc' => 'Không có lễ hội đáng kể; ghi rõ nguồn khi xin miễn',
    ],

    // Mức Planner-ready (mục 0.2). Ngưỡng là đề xuất, chỉnh sau khi chạy Planner trên dữ liệu thật.
    'planner_ready' => [
        'months' => 12,
        'climate' => 12,
        'places_with_coords' => 20,
        'places_eat_with_coords' => 4,
        'foods' => 6,
        'experiences' => 4,        // đã duyệt và đủ độ khó + thời lượng
        'events' => 1,             // đã duyệt và tính ra được ngày
        'itineraries' => 1,
    ],

    // Dữ liệu quá hạn
    'guide_stale_days' => 365,
];
