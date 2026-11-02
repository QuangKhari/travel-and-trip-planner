<?php

/*
 * Thanh toán trực tuyến: MoMo, ZaloPay và QR chuyển khoản ngân hàng (VietQR).
 * Mọi khóa lấy từ .env, KHÔNG ghi cứng. Cổng nào thiếu khóa thì tự ẩn khỏi trang đặt tour.
 *
 * Nguồn xác nhận thanh toán (theo thứ tự tin cậy):
 *   1. Thông báo máy chủ → máy chủ: MoMo IPN, ZaloPay callback, webhook SePay (QR). Có chữ ký, kiểm số tiền, gửi lặp chỉ ghi một lần.
 *   2. Hỏi lại cổng (query) khi khách quay về trang kết quả, khi khách bấm "Kiểm tra lại", và mỗi phút cho đơn sắp hết hạn giữ chỗ.
 *   3. Admin xác nhận tay (QR chuyển khoản khi chưa nối SePay).
 * MoMo và ZaloPay nhận địa chỉ IPN / callback ngay trong mỗi lần tạo giao dịch, nên KHÔNG phải nhờ cổng khai báo trước.
 */
return [
    // Thứ tự hiển thị trên trang đặt tour
    'methods' => ['momo', 'zalopay', 'qr'],

    // Địa chỉ công khai mà MoMo / ZaloPay gọi IPN về (để trống = APP_URL). Dùng khi chạy sau devtunnels / ngrok.
    'public_url' => env('PAYMENT_PUBLIC_URL'),

    'timeout' => (int) env('PAYMENT_TIMEOUT', 15),   // giây cho mỗi lệnh gọi cổng (MoMo khuyên ≥ 30 giây cho create, 15 là đủ ở sandbox)
    'timezone' => 'Asia/Ho_Chi_Minh',

    'momo' => [
        'enabled' => (bool) env('MOMO_ENABLED', false),
        'partner_code' => env('MOMO_PARTNER_CODE', ''),
        'access_key' => env('MOMO_ACCESS_KEY', ''),
        'secret_key' => env('MOMO_SECRET_KEY', ''),
        'endpoint' => env('MOMO_ENDPOINT', 'https://test-payment.momo.vn'),
        // captureWallet: quét QR / mở app MoMo ; payWithATM: thẻ ATM nội địa (thẻ test của sandbox) ; payWithMethod: trang chọn phương thức
        'request_type' => env('MOMO_REQUEST_TYPE', 'captureWallet'),
        'hold_minutes' => (int) env('MOMO_HOLD_MINUTES', 15),
    ],

    'zalopay' => [
        'enabled' => (bool) env('ZALOPAY_ENABLED', false),
        'app_id' => env('ZALOPAY_APP_ID', ''),
        'key1' => env('ZALOPAY_KEY1', ''),   // ký khi tạo đơn và khi hỏi trạng thái
        'key2' => env('ZALOPAY_KEY2', ''),   // kiểm chữ ký callback và redirect
        'endpoint' => env('ZALOPAY_ENDPOINT', 'https://sb-openapi.zalopay.vn'),
        'hold_minutes' => (int) env('ZALOPAY_HOLD_MINUTES', 15),
    ],

    'qr' => [
        'enabled' => (bool) env('QR_ENABLED', false),
        // Mã ngân hàng theo VietQR (vd VCB, TCB, MB, ACB, BIDV, TPB) hoặc mã BIN 6 số
        'bank_id' => env('QR_BANK_ID', ''),
        'bank_name' => env('QR_BANK_NAME', ''),
        'account_no' => env('QR_ACCOUNT_NO', ''),
        'account_name' => env('QR_ACCOUNT_NAME', ''),
        'image_url' => env('QR_IMAGE_URL', 'https://img.vietqr.io/image'),
        'template' => env('QR_TEMPLATE', 'compact2'),
        // Webhook SePay báo tiền về tài khoản: tự xác nhận đơn. Để trống = admin xác nhận tay.
        'sepay_api_key' => env('SEPAY_API_KEY', ''),
        'hold_minutes' => (int) env('QR_HOLD_MINUTES', 30),
    ],
];
