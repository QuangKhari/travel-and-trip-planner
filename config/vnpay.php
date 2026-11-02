<?php

/*
 * VNPay (đặc tả §7.1, §7.6). Khóa lấy từ .env, KHÔNG ghi cứng.
 * Sandbox: đăng ký tại https://sandbox.vnpayment.vn/devreg/ để nhận TmnCode và HashSecret.
 * IPN URL khai báo với VNPay: {APP_URL}/thanh-toan/vnpay/ipn (khi dev cần tunnel HTTPS: ngrok / cloudflared).
 */
return [
    'enabled' => (bool) env('VNPAY_ENABLED', false),
    'tmn_code' => env('VNPAY_TMN_CODE', ''),
    'hash_secret' => env('VNPAY_HASH_SECRET', ''),
    'pay_url' => env('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
    'api_url' => env('VNPAY_API_URL', 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'),
    'version' => '2.1.0',
    'locale' => 'vn',
    // Đơn chọn VNPay được giữ chỗ bấy nhiêu phút (A2, C-06). Cũng là vnp_ExpireDate gửi sang VNPay.
    'hold_minutes' => (int) env('VNPAY_HOLD_MINUTES', 15),
    // Gọi API querydr tối đa bấy nhiêu giây
    'timeout' => (int) env('VNPAY_TIMEOUT', 8),
    // Ghi đè địa chỉ trả về / IPN khi chạy sau proxy hoặc tunnel (để trống = theo APP_URL)
    'return_url' => env('VNPAY_RETURN_URL'),
    'timezone' => 'Asia/Ho_Chi_Minh',
];
