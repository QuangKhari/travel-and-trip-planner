<?php

/*
| API du lịch (T-36…T-38). Mọi khóa đặt trong .env. Không có khóa hoặc nhà cung cấp lỗi / hết hạn mức thì dùng
| dữ liệu giả lập có nhãn "Dữ liệu thử nghiệm" — trang chuyến đi không bao giờ hỏng vì API ngoài (N-11).
| Kết quả kiểm tra khả thi (C0) và giới hạn từng gói ghi trong docs/HUONG-DAN-CAP-NHAT.md.
*/

return [
    // true = luôn dùng giả lập (demo, chạy test, chưa có khóa). false = gọi API thật khi có khóa, lỗi thì lùi về giả lập.
    'simulate' => (bool) env('TRAVEL_API_SIMULATE', true),
    'fallback_timeout_ms' => (int) env('TRAVEL_API_TIMEOUT_MS', 5000),

    'flight_search' => [
        'provider' => 'duffel',
        'duffel_token' => env('DUFFEL_TOKEN'),                 // duffel_test_… (test mode: hãng "Duffel Airways", giá không thật)
        'duffel_url' => env('DUFFEL_URL', 'https://api.duffel.com'),
        'duffel_version' => env('DUFFEL_VERSION', 'v2'),
        'cache_minutes' => 15,
        'max_offers' => 8,
    ],

    'flight_status' => [
        // thứ tự gọi; hết lượt / lỗi thì sang nhà cung cấp sau, rồi trạng thái đã lưu, cuối cùng là giả lập
        'chain' => ['aerodatabox', 'aviationstack'],
        'aerodatabox_key' => env('AERODATABOX_RAPIDAPI_KEY'),
        'aerodatabox_host' => env('AERODATABOX_HOST', 'aerodatabox.p.rapidapi.com'),
        'aviationstack_key' => env('AVIATIONSTACK_KEY'),
        'cache_minutes' => 30,
        // lượt / tháng của gói đang dùng; dừng ở 90 % để chừa cho lúc demo
        'quota' => ['aerodatabox' => (int) env('AERODATABOX_MONTHLY_UNITS', 600), 'aviationstack' => (int) env('AVIATIONSTACK_MONTHLY_CALLS', 100)],
        'quota_stop_ratio' => 0.9,
    ],

    'hotel_search' => [
        'provider' => 'geoapify',   // Geoapify Places (accommodation.hotel): tên, địa chỉ, tọa độ; KHÔNG có giá
        'geoapify_key' => env('GEOAPIFY_KEY'),
        'cache_minutes' => 30,
        'radius_m' => 5000,
        'limit' => 10,
    ],

    // Sân bay gần điểm đến (slug tbl_destinations -> IATA) để điền sẵn ô "đến"
    'airports' => [
        'ha-noi' => 'HAN', 'ninh-binh' => 'HAN', 'ha-giang' => 'HAN', 'moc-chau' => 'HAN', 'vinh-ha-long' => 'VDO', 'hai-phong' => 'HPH', 'cat-ba' => 'HPH',
        'sa-pa' => 'HAN', 'dien-bien' => 'DIN', 'cao-bang' => 'HAN', 'hue' => 'HUI', 'quang-binh' => 'VDH', 'vinh' => 'VII', 'thanh-hoa' => 'THD',
        'da-nang' => 'DAD', 'hoi-an' => 'DAD', 'quy-nhon' => 'UIH', 'phu-yen' => 'TBB', 'nha-trang' => 'CXR', 'da-lat' => 'DLI', 'buon-ma-thuot' => 'BMV',
        'pleiku' => 'PXU', 'phan-thiet' => 'SGN', 'mui-ne' => 'SGN', 'ho-chi-minh' => 'SGN', 'vung-tau' => 'SGN', 'can-tho' => 'VCA', 'phu-quoc' => 'PQC',
        'con-dao' => 'VCS', 'ca-mau' => 'CAH', 'rach-gia' => 'VKG',
    ],
    'airport_names' => [
        'HAN' => 'Hà Nội (Nội Bài)', 'SGN' => 'TP.HCM (Tân Sơn Nhất)', 'DAD' => 'Đà Nẵng', 'CXR' => 'Nha Trang (Cam Ranh)', 'PQC' => 'Phú Quốc',
        'HPH' => 'Hải Phòng (Cát Bi)', 'VDO' => 'Vân Đồn', 'HUI' => 'Huế (Phú Bài)', 'VDH' => 'Đồng Hới', 'VII' => 'Vinh', 'THD' => 'Thanh Hóa (Thọ Xuân)',
        'UIH' => 'Quy Nhơn (Phù Cát)', 'TBB' => 'Tuy Hòa', 'DLI' => 'Đà Lạt (Liên Khương)', 'BMV' => 'Buôn Ma Thuột', 'PXU' => 'Pleiku', 'VCA' => 'Cần Thơ',
        'VCS' => 'Côn Đảo', 'CAH' => 'Cà Mau', 'VKG' => 'Rạch Giá', 'DIN' => 'Điện Biên',
    ],
];
