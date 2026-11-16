<?php

/*
 * Đợt F: buổi thử người dùng (F1, F2 vòng 1, F4 vòng 2).
 * Năm nhiệm vụ cố định, giống nhau ở hai vòng để so trước / sau. Hệ thống tự bấm giờ và tự chấm "đạt"
 * bằng cách kiểm tra dữ liệu thật (đơn tour, chuyến, lời mời, yêu cầu báo giá), không dựa vào người ghi chép.
 * Bật bằng STUDY_ENABLED=true trong lúc thử; tắt thì thanh nhiệm vụ không hiện với ai.
 */
return [
    'enabled' => (bool) env('STUDY_ENABLED', false),

    // mật khẩu chung của tài khoản người thử (study:setup in ra; đổi trước buổi thử)
    'password' => env('STUDY_PASSWORD', 'Thu@2026'),

    // tài khoản "bạn đồng hành" để người thử mời ở nhiệm vụ 4
    'companion' => ['username' => 'ban_dong_hanh', 'email' => 'ban.dong.hanh@travela.test', 'name' => 'Bạn Đồng Hành'],

    // chuyến có sẵn lỗi cho nhiệm vụ 3 (nhân bản từ chuyến demo Đà Lạt của demo_khach nếu có)
    'fix_trip_source' => ['username' => 'demo_khach', 'title' => 'Đà Lạt 3 ngày'],

    // quá thời gian này mà chưa xong thì thanh nhắc "có thể bỏ qua" (không tự dừng)
    'soft_limit_sec' => 600,

    'tasks' => [
        'tour_book' => [
            'title' => 'Đặt một tour',
            'prompt' => 'Bạn muốn đi một tour trong nước cho 2 người lớn. Hãy chọn một tour bất kỳ còn chỗ và đặt tour (chưa cần thanh toán).',
            'done' => 'Có đơn đặt tour mới của bạn.',
            'flow' => 'Đặt tour',
        ],
        'trip_create' => [
            'title' => 'Lên lịch chuyến Đà Nẵng',
            'prompt' => 'Bạn đi Đà Nẵng 3 ngày với 1 người bạn. Hãy tạo chuyến đi và xếp ít nhất 2 nơi cho mỗi ngày (tự xếp hoặc nhờ AI đều được).',
            'done' => 'Có chuyến Đà Nẵng 3 ngày với ít nhất 6 mục, ngày nào cũng có mục.',
            'flow' => 'Tạo chuyến → lên lịch',
        ],
        'trip_fix' => [
            'title' => 'Sửa chuyến Đà Lạt',
            'prompt' => 'Trong "Chuyến đi của tôi" có chuyến "Đà Lạt 3 ngày (thử)" đang có chỗ không đi được. Hãy sửa để không còn cảnh báo đỏ (tự sửa hoặc nhờ La bàn).',
            'done' => 'Chuyến Đà Lạt (thử) không còn lỗi nặng: đóng cửa, không kịp giờ, trùng giờ.',
            'flow' => 'La bàn',
        ],
        'trip_invite' => [
            'title' => 'Mời bạn đồng hành',
            'prompt' => 'Mời bạn của bạn (email ban.dong.hanh@travela.test) cùng chỉnh chuyến Đà Nẵng, hoặc tạo liên kết mời để gửi cho họ.',
            'done' => 'Bạn đồng hành đã được mời, hoặc chuyến có liên kết mời.',
            'flow' => 'Chia sẻ',
        ],
        'quote' => [
            'title' => 'Hỏi giá trọn gói',
            'prompt' => 'Bạn muốn Travela báo giá trọn gói cho chuyến Đà Nẵng. Hãy gửi yêu cầu báo giá.',
            'done' => 'Có yêu cầu báo giá mới.',
            'flow' => 'Báo giá',
        ],
    ],

    // Phiếu SUS tiếng Việt (Brooke 1996; bản dịch dùng trong docs/ai/THU_NGHIEM_NGUOI_DUNG.md)
    'sus' => [
        'Tôi nghĩ tôi sẽ muốn dùng hệ thống này thường xuyên.',
        'Tôi thấy hệ thống phức tạp không cần thiết.',
        'Tôi thấy hệ thống dễ dùng.',
        'Tôi nghĩ tôi sẽ cần người hỗ trợ kỹ thuật mới dùng được hệ thống.',
        'Tôi thấy các chức năng của hệ thống được kết nối tốt với nhau.',
        'Tôi thấy hệ thống có quá nhiều chỗ không nhất quán.',
        'Tôi nghĩ phần lớn mọi người sẽ học dùng hệ thống này rất nhanh.',
        'Tôi thấy hệ thống rất rườm rà, khó dùng.',
        'Tôi thấy rất tự tin khi dùng hệ thống.',
        'Tôi cần học nhiều thứ trước khi dùng được hệ thống.',
    ],

    'consent' => [
        'Buổi thử nhằm đánh giá phần mềm Travela cho đồ án tốt nghiệp, không đánh giá bạn.',
        'Hệ thống ghi thời gian làm từng nhiệm vụ, kết quả đạt / chưa đạt, các thao tác bạn làm trên Travela (bấm gợi ý, thêm vào lịch...) và câu trả lời phiếu đánh giá.',
        'Không ghi âm, không quay màn hình, không thu thông tin cá nhân ngoài mã người thử. Dữ liệu chỉ dùng cho đồ án và được xóa sau khi bảo vệ.',
        'Bạn có thể dừng bất cứ lúc nào mà không cần giải thích.',
    ],
];
