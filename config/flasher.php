<?php

/*
 * PHP Flasher: chỉ ghi đè một khóa, các khóa khác giữ mặc định của gói.
 * flash_bag: tự đổi session('success' / 'error'...) thành thông báo nổi. Khi chạy test (phpunit.xml đặt FLASHER_FLASH_BAG=false)
 * tắt đi để assertSessionHas('success') kiểm tra được thông báo mà controller trả về.
 */
return [
    'flash_bag' => (bool) env('FLASHER_FLASH_BAG', true),
];
