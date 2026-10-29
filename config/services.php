<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Bản đồ Google Maps cho trình soạn chuyến đi. Để trống GOOGLE_MAPS_KEY thì tự dùng OpenStreetMap (Leaflet).
    // Khóa trình duyệt (Maps JavaScript API + Places API New), nên giới hạn theo tên miền (HTTP referrer) trong Google Cloud Console.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID', 'DEMO_MAP_ID'),
    ],

    // Bản đồ vector (MapLibre). Thứ tự ưu tiên: Google (nếu có GOOGLE_MAPS_KEY) -> MAP_STYLE_URL -> MapTiler -> Goong -> OpenFreeMap (không cần khóa).
    // Không tải được bản đồ vector (trình duyệt không hỗ trợ WebGL, lỗi mạng) thì tự dùng Leaflet/OpenStreetMap.
    'map' => [
        'style_url' => env('MAP_STYLE_URL'),
        'maptiler_key' => env('MAPTILER_KEY'),
        'maptiler_style' => env('MAPTILER_STYLE', 'streets-v2'),
        'goong_maptiles_key' => env('GOONG_MAPTILES_KEY'),
        'free_style_url' => env('MAP_FREE_STYLE_URL', 'https://tiles.openfreemap.org/styles/liberty'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
