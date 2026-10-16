<?php

/*
|--------------------------------------------------------------------------
| "Khám phá theo cảm hứng" - nguồn dữ liệu ngoài
|--------------------------------------------------------------------------
| Nguyên tắc: web KHÔNG gọi API ngoài khi người dùng mở trang. Dữ liệu được nạp bằng lệnh
| artisan (discover:*), lưu vào DB, trang chỉ đọc DB.
*/

return [

    // Wikimedia bắt buộc User-Agent định danh được (kèm email/website liên hệ), nếu không dễ bị chặn.
    'user_agent' => env('DISCOVER_USER_AGENT', 'TravelaGraduationProject/1.0 (contact: ' . env('SITE_EMAIL', 'you@example.com') . ')'),

    'http_timeout' => 20,

    'wikipedia' => [
        'lang' => env('WIKIPEDIA_LANG', 'vi'),
        // Cỡ ảnh bìa tải về (px, chiều rộng) và chất lượng WebP.
        'image_width' => 1400,
        'webp_quality' => 82,
        // Chỉ nhận ảnh có giấy phép mở (chuỗi bắt đầu bằng một trong các tiền tố này).
        'free_licenses' => ['CC BY', 'CC0', 'CC-BY', 'Public domain', 'PD', 'GFDL', 'FAL', 'Attribution'],
        // Nghỉ giữa 2 request (micro giây) để lịch sự với máy chủ.
        'delay_us' => 300000,
    ],

    'weather' => [
        // Open-Meteo: miễn phí cho phi thương mại (CC BY 4.0). Nếu web chạy thương mại phải mua gói API.
        'endpoint' => env('OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
        'apikey' => env('OPEN_METEO_KEY'), // bỏ trống nếu dùng bản miễn phí
        'timezone' => 'Asia/Bangkok',
        'days' => 5,
        'chunk' => 20,        // số điểm đến trong 1 request
        'fresh_minutes' => 180, // quá hạn này thì coi là "cũ" (API trả stale=true), lịch chạy lại mỗi 3 giờ
    ],

    'places' => [
        // overpass (OpenStreetMap, không cần key) | geoapify (cần GEOAPIFY_KEY, kiểm tra điều khoản lưu trữ)
        'provider' => env('DISCOVER_POI_PROVIDER', 'overpass'),
        'radius_m' => (int) env('DISCOVER_POI_RADIUS', 12000),
        // Số địa điểm giữ lại cho mỗi nhóm của mỗi điểm đến
        'per_category' => ['see' => 12, 'culture' => 6, 'eat' => 8],
        'delay_us' => 2000000,
        'overpass_url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'geoapify_key' => env('GEOAPIFY_KEY'),
    ],

    // Tên bài Wikipedia khi khác tên điểm đến (slug => tiêu đề). Có thể sửa cột wikiTitle trong DB thay thế.
    'wiki_titles' => [
        // 'ly-son' => 'Lý Sơn',
    ],
];
