<?php

/*
|--------------------------------------------------------------------------
| Chính sách lưu trữ ảnh (Travela 2.0 – Tổng hợp v8.6, §6.5)
|--------------------------------------------------------------------------
| Ảnh upload là DỮ LIỆU, không phải source code: lưu ở storage/app/public
| (disk "public"), KHÔNG lưu trong thư mục assets của public/, KHÔNG commit vào Git/ZIP.
| Mọi giới hạn chỉnh được bằng .env, không phải sửa code.
*/

return [

    // Disk lưu ảnh. Đổi sang 's3' (S3/R2) sau này chỉ cần đổi biến này + khai báo disk.
    'disk' => env('MEDIA_DISK', 'public'),

    // Thư mục gốc của ảnh tour trong disk: tours/{tourId}/{stem}-{width}.webp
    'tours_dir' => 'tours',

    // Các cỡ sinh ra khi upload (px, theo chiều rộng, không phóng to ảnh nhỏ).
    'sizes' => [320, 800, 1600],

    // Chất lượng WebP (0-100).
    'webp_quality' => (int) env('MEDIA_WEBP_QUALITY', 80),

    'limits' => [
        // Dung lượng tối đa của 1 file upload (KB).
        'max_file_kb' => (int) env('MEDIA_MAX_FILE_KB', 8192),
        // Tổng số điểm ảnh tối đa (tránh "ảnh bom" làm cạn RAM khi giải mã bằng GD).
        'max_pixels' => (int) env('MEDIA_MAX_PIXELS', 16000000),
        // Cạnh dài tối đa (px).
        'max_side' => (int) env('MEDIA_MAX_SIDE', 8000),
        // Số ảnh tối đa cho 1 tour.
        'max_images_per_tour' => (int) env('MEDIA_MAX_IMAGES_PER_TOUR', 12),
        // Tổng dung lượng thư mục ảnh tour (MB). Vượt thì từ chối upload.
        'max_total_mb' => (int) env('MEDIA_QUOTA_MB', 2048),
    ],

    // MIME thật (kiểm tra bằng nội dung file, không tin đuôi file) được chấp nhận.
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Dọn ảnh mồ côi: chỉ xóa file chưa được tbl_images tham chiếu và cũ hơn N giờ
    // (để không xóa nhầm ảnh vừa upload trong wizard chưa bấm Hoàn tất).
    'orphan_min_age_hours' => (int) env('MEDIA_ORPHAN_MIN_AGE_HOURS', 24),
];