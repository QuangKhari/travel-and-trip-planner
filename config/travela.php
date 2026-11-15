<?php

return [
    // Đơn mới (trạng thái n) được giữ chỗ trong bấy nhiêu giờ; quá hạn mà chưa xác nhận thì tự hủy
    'hold_hours' => (int) env('BOOKING_HOLD_HOURS', 48),

    // Mỗi tài khoản được dùng một mã giảm giá tối đa bấy nhiêu lần trên các đơn chưa hủy (0 = không giới hạn)
    'coupon_max_uses_per_user' => (int) env('COUPON_MAX_USES_PER_USER', 1),

    'pii_current_key' => env('DATA_ENCRYPTION_KEY_ID', 'k1'),
    'pii_keys' => [
        'k1' => env('DATA_ENCRYPTION_KEY'),
    ],
    // Đăng nhập hai lớp bắt buộc cho các vai trò quản trị này (vd. "admin" hoặc "admin,manager"); trống = tự chọn bật
    'admin_2fa_required' => env('ADMIN_2FA_REQUIRED', ''),
];
