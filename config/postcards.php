<?php

/**
 * Hero "Dòng bưu ảnh" (trang chủ).
 *
 * seasons: slug điểm đến (tbl_destinations.slug) => tháng đẹp + một dòng lý do.
 * ĐÂY LÀ DỮ LIỆU TẠM do bạn tự duyệt lại. Khi có bảng tbl_destination_months
 * (giai đoạn C trong tài liệu), service sẽ đọc từ DB và file này bỏ đi.
 * Điểm đến không có trong danh sách vẫn xuất hiện, với dòng "Điểm nổi bật quanh năm".
 */
return [
    'limit'       => 8,           // số bưu ảnh (không tính bưu ảnh trắng); tài liệu: 8 đến 10
    'planner_url' => '/explore',  // đổi thành '/len-ke-hoach' khi trang Planner có

    'seasons' => [
        'ha-noi'        => ['m' => [9, 10, 11],          'w' => 'Thu Hà Nội, hoa sữa thơm'],
        'ha-long'       => ['m' => [4, 5, 9, 10, 11],    'w' => 'Biển lặng, trời trong'],
        'cat-ba'        => ['m' => [5, 6, 9, 10],        'w' => 'Biển đẹp, ít mưa'],
        'sa-pa'         => ['m' => [9, 10],              'w' => 'Lúa chín vàng trên bậc thang'],
        'ha-giang'      => ['m' => [9, 10, 11],          'w' => 'Mùa hoa tam giác mạch'],
        'ninh-binh'     => ['m' => [5, 6],               'w' => 'Lúa chín vàng quanh Tam Cốc'],
        'moc-chau'      => ['m' => [11, 12, 3],          'w' => 'Mùa hoa cải, hoa mận'],
        'ta-xua'        => ['m' => [3, 4, 10, 11],       'w' => 'Mùa săn mây'],
        'mu-cang-chai'  => ['m' => [6, 9, 10],           'w' => 'Ruộng bậc thang đổ nước hoặc lúa chín'],
        'hue'           => ['m' => [1, 2, 3, 4],         'w' => 'Trời dịu, ít mưa'],
        'da-nang'       => ['m' => [3, 4, 5, 6, 7, 8],   'w' => 'Biển đẹp, nắng ráo'],
        'hoi-an'        => ['m' => [2, 3, 4],            'w' => 'Phố cổ dịu nắng, đêm rằm đèn lồng'],
        'ly-son'        => ['m' => [3, 4, 5, 6, 7],      'w' => 'Biển êm, mùa tỏi'],
        'quy-nhon'      => ['m' => [2, 3, 4, 5, 6, 7],   'w' => 'Biển trong, ít mưa'],
        'nha-trang'     => ['m' => [3, 4, 5, 6, 7, 8],   'w' => 'Biển xanh, nắng đẹp'],
        'da-lat'        => ['m' => [11, 12, 1, 2],       'w' => 'Se lạnh, dã quỳ và hoa nở rộ'],
        'phu-quoc'      => ['m' => [11, 12, 1, 2, 3, 4], 'w' => 'Mùa biển êm, nắng ráo'],
        'con-dao'       => ['m' => [2, 3, 4, 5, 6],      'w' => 'Biển lặng, nước trong'],
        'vung-tau'      => ['m' => [11, 12, 1, 2, 3],    'w' => 'Trời mát, biển dễ chịu'],
        'can-tho'       => ['m' => [9, 10, 11],          'w' => 'Mùa nước nổi, chợ nổi nhộn nhịp'],
    ],
];
