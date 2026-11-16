<?php

/**
 * Cá nhân hóa gợi ý (Đợt E, mức 5). Mô hình học bằng `php artisan reco:train` (lịch chạy hằng đêm).
 *   rules      : xếp như cũ (luật), vẫn ghi lần hiện để học
 *   model      : xếp lại theo mô hình (chung + riêng từng người), có đa dạng hóa và một ô "thử khác"
 *   interleave : trộn xen kẽ kết quả luật và mô hình (team-draft) để so sánh với người dùng thật (reco:interleave-report)
 */
return [
    'mode' => env('RECO_MODE', 'rules'),
    'min_train' => (int) env('RECO_MIN_TRAIN', 200),   // ít hơn số lần hiện có nhãn này thì không thay mô hình
    'diversity' => 0.15,                                // MMR: phạt nơi cùng nhóm với các nơi đã xếp trên
    'explore_slot' => 4,                                // vị trí (từ 0) của ô "thử khác"; -1 để tắt
    'surfaces' => ['browse', 'around', 'suggest'],      // danh sách được xếp lại (tìm kiếm có chữ giữ thứ tự khớp chữ)
];
