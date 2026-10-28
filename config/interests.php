<?php

/**
 * Sở thích của người dùng (mục 0.1-E của kế hoạch v6) và cách mỗi loại nội dung "khớp" với sở thích.
 * Chỉ khớp theo DỮ LIỆU CÓ THẬT: nhóm kind của địa điểm (config/planner.php 'kind_groups'), danh mục (category) và tên của trải nghiệm/món.
 * Không khớp thì không cộng điểm, không bịa lý do. Từ khóa viết thường, không dấu cũng được (so khớp trên bản đã bỏ dấu).
 */
return [
    'nature' => [
        'label' => 'Thiên nhiên',
        'place_groups' => ['nature'],
        'aliases' => ['canh dep', 'ngam bien', 'tam bien'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['thien nhien', 'thuyen', 'nui', 'bien', 'hang dong', 'thac', 'ho ', 'rung', 'dao'],
    ],
    'culture' => [
        'label' => 'Văn hóa, lịch sử',
        'place_groups' => ['culture'],
        'aliases' => ['van hoa', 'lich su', 'di san'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['van hoa', 'lich su', 'bieu dien', 'lang nghe', 'lop hoc', 'di tich', 'bao tang', 'chua', 'den '],
    ],
    'food' => [
        'label' => 'Ẩm thực',
        'place_groups' => ['eat'],
        'aliases' => ['an uong', 'am thuc', 'dac san', 'mon ngon'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['am thuc', 'nau an', 'cho dem', 'cho '],
        'foods' => true,
    ],
    'adventure' => [
        'label' => 'Phiêu lưu, vận động',
        'place_groups' => [],
        'aliases' => ['phieu luu', 'leo nui'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['trekking', 'leo nui', 'lan', 'chay bo', 'phieu luu', 'cheo', 'dap xe', 'luot'],
        'difficulty' => ['medium', 'hard'],
        'difficulty_match' => true,     // trải nghiệm độ khó vừa/khó được coi là hợp sở thích này
    ],
    'relax' => [
        'label' => 'Thư giãn, ngắm cảnh',
        'place_groups' => ['see'],
        'aliases' => ['thu gian', 'nghi duong', 'ngam canh'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['ngam canh', 'dao bo', 'thu gian', 'spa', 'suoi khoang', 'ca phe'],
    ],
    'family' => [
        'label' => 'Gia đình, trẻ nhỏ',
        'place_groups' => ['fun'],
        'aliases' => ['gia dinh', 'tre em', 'tre nho'],   // cụm đã bỏ dấu để Search Intent nhận ra sở thích
        'keywords' => ['gia dinh', 'tre em', 'cong vien', 'thu', 'thuy cung'],
    ],
];
