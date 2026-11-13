<?php

/*
 * Thanh toán trực tuyến: VNPay, MoMo và QR chuyển khoản ngân hàng (VietQR).
 * Mọi khóa lấy từ .env, KHÔNG ghi cứng. Cổng nào thiếu khóa thì tự ẩn khỏi trang đặt tour.
 *
 * Nguồn xác nhận thanh toán (theo thứ tự tin cậy):
 *   1. Thông báo máy chủ → máy chủ: VNPay IPN, MoMo IPN, webhook SePay (QR). Có chữ ký, kiểm số tiền, gửi lặp chỉ ghi một lần.
 *   2. Hỏi lại cổng (query) khi khách quay về trang kết quả, khi khách bấm "Kiểm tra lại", và mỗi phút cho đơn sắp hết hạn giữ chỗ.
 *   3. Admin xác nhận tay (QR chuyển khoản khi chưa nối SePay).
 * MoMo nhận địa chỉ IPN trong mỗi lần tạo giao dịch; VNPay cần khai báo URL IPN một lần trong trang quản trị merchant.
 */
return [
    // Thứ tự hiển thị trên trang đặt tour
    'methods' => ['vnpay', 'momo', 'qr'],

    // Địa chỉ công khai mà VNPay / MoMo gọi IPN về (để trống = APP_URL). Dùng khi chạy sau devtunnels / ngrok.
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

    // VNPay 2.1.0: khóa sandbox đăng ký tại sandbox.vnpayment.vn/devreg. URL IPN khai báo trong trang quản trị merchant
    // (VNPay KHÔNG nhận IPN URL theo từng giao dịch): {PAYMENT_PUBLIC_URL}/thanh-toan/thong-bao/vnpay
    'vnpay' => [
        'enabled' => (bool) env('VNPAY_ENABLED', false),
        'tmn_code' => env('VNPAY_TMN_CODE', ''),
        'hash_secret' => env('VNPAY_HASH_SECRET', ''),
        'pay_url' => env('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
        'api_url' => env('VNPAY_API_URL', 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'),
        'bank_code' => env('VNPAY_BANK_CODE', ''),   // để trống = khách chọn ngân hàng trên trang VNPay; VNBANK / INTCARD / VNPAYQR
        'hold_minutes' => (int) env('VNPAY_HOLD_MINUTES', 15),
    ],

    // Hoàn tiền qua API của cổng (VNPay, MoMo; QR chuyển khoản luôn hoàn tay). Áp dụng cho cả hoàn khi hủy đơn và hoàn phần dư khi giảm số khách.
    // enabled: bật nút "Hoàn qua cổng" ở trang Hoàn tiền (admin bấm, có xác nhận). MẶC ĐỊNH TẮT vì đây là tiền thật.
    // auto: hệ thống tự gửi lệnh hoàn lần đầu cho hồ sơ chờ hoàn sau auto_delay_minutes (cần enabled). Lỗi / bị từ chối thì chờ người xử lý.
    'refund' => [
        'enabled' => (bool) env('PAYMENT_REFUND_ENABLED', false),
        'auto' => (bool) env('PAYMENT_REFUND_AUTO', false),
        'auto_delay_minutes' => (int) env('PAYMENT_REFUND_AUTO_DELAY', 10),
        'max_attempts' => (int) env('PAYMENT_REFUND_MAX_ATTEMPTS', 3),
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
