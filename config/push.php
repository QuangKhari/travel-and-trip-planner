<?php

// Thông báo đẩy (Web Push, VAPID). Tạo khóa: php artisan push:vapid  → chép 2 dòng vào .env
return [
    'public' => env('WEB_PUSH_PUBLIC_KEY'),       // base64url, điểm P-256 không nén (65 byte)
    'private' => env('WEB_PUSH_PRIVATE_KEY'),     // base64 của khóa riêng PEM
    'subject' => env('WEB_PUSH_SUBJECT', 'mailto:' . env('MAIL_FROM_ADDRESS', 'admin@travela.local')),
    'ttl' => 6 * 3600,
];
