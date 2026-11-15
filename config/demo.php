<?php

/**
 * Bộ dữ liệu demo cố định cho buổi bảo vệ (Đợt D mục D5): `php artisan demo:reset` dựng lại đúng trạng thái này,
 * `php artisan demo:check` kiểm tra các tài khoản có mặt. Chỉ đụng tới dữ liệu của các tài khoản dưới đây.
 */
return [
    'password' => env('DEMO_PASSWORD', 'Demo@2026'),
    'accounts' => [
        ['type' => 'user', 'username' => 'demo_khach', 'email' => 'demo_khach@travela.test', 'name' => 'Nguyễn Minh An', 'label' => 'Khách (chủ chuyến)'],
        ['type' => 'user', 'username' => 'demo_ban', 'email' => 'demo_ban@travela.test', 'name' => 'Trần Thu Hà', 'label' => 'Bạn đồng hành'],
        ['type' => 'admin', 'username' => 'demo_admin', 'email' => 'demo_admin@travela.test', 'name' => 'Lê Quản Trị', 'role' => 'admin', 'label' => 'Quản trị viên'],
        ['type' => 'admin', 'username' => 'demo_quanly', 'email' => 'demo_quanly@travela.test', 'name' => 'Phạm Quản Lý', 'role' => 'manager', 'label' => 'Quản lý'],
        ['type' => 'admin', 'username' => 'demo_nhanvien', 'email' => 'demo_nhanvien@travela.test', 'name' => 'Võ Nhân Viên', 'role' => 'staff', 'label' => 'Nhân viên'],
    ],
    // ghi chú lô nhập địa điểm của bộ demo (để demo:reset nhận ra và xóa)
    'import_note' => '[demo] Lô mẫu chờ duyệt',
];
