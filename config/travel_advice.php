<?php

/**
 * "Thời điểm đi": ngưỡng và lời khuyên dựng từ số liệu khí hậu trung bình nhiều năm (Open-Meteo, CC BY 4.0).
 * Chỉnh ngưỡng và câu chữ ở đây, không sửa code. Mọi lời khuyên đều suy ra từ số liệu, không bịa thêm.
 */
return [
    'hot_tmax' => 33,          // nhiệt độ cao nhất trung bình (°C) trên mức này: nắng nóng
    'cold_tmin' => 12,         // nhiệt độ thấp nhất trung bình (°C) dưới mức này: lạnh
    'rainy_share_high' => 0.40, // tỉ lệ ngày mưa từ mức này: mưa nhiều
    'rainy_share_low' => 0.20,  // dưới mức này: ít mưa
    'heavy_rain_mm' => 300,    // tổng mưa tháng trên mức này: có thể mưa lớn

    'suitable' => [
        'pleasant' => ['Tham quan và đi bộ khám phá ngoài trời', 'Chụp ảnh phong cảnh'],
        'trek' => ['Trekking nhẹ, đi xe đạp hoặc đi thuyền'],
        'hot' => ['Biển, hồ bơi', 'Đi sớm hoặc chiều muộn, nghỉ trưa trong nhà'],
        'rainy' => ['Bảo tàng, di tích có mái che', 'Ẩm thực, quán cà phê, spa'],
        'cold' => ['Ngắm cảnh mây sương, săn mây', 'Ẩm thực nóng, suối khoáng'],
    ],

    'packing' => [
        'pleasant' => ['Quần áo nhẹ và một áo khoác mỏng cho buổi tối'],
        'hot' => ['Mũ, kính râm, kem chống nắng', 'Bình nước cá nhân', 'Quần áo thoáng, nhanh khô'],
        'rainy' => ['Áo mưa mỏng hoặc ô gấp', 'Giày hoặc dép chống trượt', 'Túi chống nước cho điện thoại'],
        'cold' => ['Áo ấm nhiều lớp', 'Khăn quàng, găng tay mỏng'],
    ],
];
