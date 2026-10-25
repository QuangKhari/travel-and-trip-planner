<?php

/**
 * Trip Planner.
 * osrm_url: địa chỉ máy chủ OSRM để vẽ đường đi theo đường bộ trên bản đồ (vd https://osrm.example.com).
 * Để trống thì bản đồ vẽ đường thẳng nối các điểm. Máy chủ demo công cộng của OSRM chỉ để thử, không dùng khi bảo vệ/triển khai.
 */
return [
    'osrm_url' => env('OSRM_URL'),
];
