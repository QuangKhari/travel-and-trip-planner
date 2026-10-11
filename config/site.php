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
    'facebook' => env('SITE_FACEBOOK'),
    'youtube'  => env('SITE_YOUTUBE'),
    'instagram' => env('SITE_INSTAGRAM'),
];
