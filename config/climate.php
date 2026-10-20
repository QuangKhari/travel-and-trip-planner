<?php

/**
 * A5 - Khí hậu Open-Meteo (lưu trữ ERA5) và luật sinh tbl_destination_months.
 * Dữ liệu: Open-Meteo.com, CC BY 4.0 (phi thương mại). Ngưỡng luật chỉnh ở đây, không sửa code.
 */
return [
    'archive_endpoint' => env('OPEN_METEO_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
    'timezone' => 'Asia/Bangkok',
    'years' => 5,            // số năm gần nhất (đủ năm) để lấy trung bình
    'delay_us' => 1500000,   // nghỉ giữa các điểm đến
    'rainy_day_mm' => 1.0,   // ngày có từ ngần này mm trở lên tính là ngày mưa

    'rules' => [
        // Điểm = 100 - các khoản trừ. Mỗi khoản có trần để một yếu tố không áp đảo.
        'cold_below' => 18,        // nhiệt độ trung bình (°C) dưới mức này bị trừ
        'cold_per_deg' => 4, 'cold_cap' => 40,
        'very_cold_tmin' => 10,    // nhiệt độ thấp nhất trung bình dưới mức này trừ thêm
        'very_cold_penalty' => 15,
        'hot_above' => 33,         // nhiệt độ cao nhất trung bình (°C) trên mức này bị trừ
        'hot_per_deg' => 6, 'hot_cap' => 40,
        'rainy_share_weight' => 60,   // trừ = tỉ lệ ngày mưa x trọng số
        'heavy_rain_mm' => 300,       // tổng mưa tháng trên mức này trừ thêm
        'heavy_rain_per_10mm' => 1, 'heavy_rain_cap' => 30,
        // Phân loại
        'best_min_score' => 78,
        'avoid_max_score' => 40,
        'max_best' => 7,   // tối đa bấy nhiêu tháng "best" mỗi điểm đến (lấy điểm cao nhất)
        'min_best' => 1,   // luôn có ít nhất bấy nhiêu tháng "best" (tháng tốt nhất tương đối)
    ],
];
