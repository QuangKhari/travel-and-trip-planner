<?php

/*
 * Thông tin liên hệ hiển thị trên footer và trang Liên hệ.
 * Điền trong file .env (ví dụ SITE_EMAIL=...). Mục nào để trống sẽ tự ẩn, không hiện thông tin giả.
 */
return [
    'name'     => env('SITE_NAME', 'Travel'),
    'email'    => env('SITE_EMAIL'),
    'phone'    => env('SITE_PHONE'),
    'address'  => env('SITE_ADDRESS'),
    'zalo'      => env('SITE_ZALO'),       // số điện thoại Zalo, ví dụ 0901234567
    'messenger' => env('SITE_MESSENGER'),  // tên trang Facebook dùng cho m.me/<tên>, ví dụ travel.vn
    'facebook' => env('SITE_FACEBOOK'),
    'youtube'  => env('SITE_YOUTUBE'),
    'instagram' => env('SITE_INSTAGRAM'),
];
