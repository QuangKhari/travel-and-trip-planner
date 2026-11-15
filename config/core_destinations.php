<?php

/**
 * Các điểm đến LÕI của đồ án (mục 0 của kế hoạch v6): 6 điểm đến phải đạt mức Planner-ready (tuần 4) và Coverage >= 80% (đóng băng nội dung).
 * Điền slug khi bạn đã chốt (tbl_destinations.slug). Chạy `php artisan content:coverage --core` để xem tình trạng.
 * Gợi ý chọn: miền Bắc, Trung, Nam đều có; mỗi nơi đã có tháng + khí hậu + nhiều tour; chọn nơi dễ có dữ liệu nguồn.
 */
return [
    // 8 điểm đến lõi chốt 16/11/2026 (Hội An – Đà Nẵng tính là một cụm, hai slug)
    'slugs' => [
        'ha-noi',
        'sa-pa',
        'hue',
        'da-nang',
        'hoi-an',
        'da-lat',
        'nha-trang',
        'phu-quoc',
        'can-tho',
    ],
    // Chuẩn dữ liệu tối thiểu cho mỗi điểm đến lõi (places:audit, demo:check), khớp chuẩn "đủ demo" của planner:coverage
    'min_sight' => 20,
    'min_food' => 10,
    'min_indoor' => 5,   // nơi tham quan trong nhà: đủ để TripWatch đổi lịch ngày mưa
];
