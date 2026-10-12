<?php

/**
 * Bài viết blog (nội dung mẫu, chưa có bảng trong DB).
 * Muốn thêm bài: copy một khối bên dưới, đổi 'slug' (không dấu, nối bằng gạch ngang) và nội dung.
 * 'image' là tên file trong public/clients/assets/images/blog/
 * Về sau chuyển sang bảng tbl_blog thì chỉ cần đổi nguồn dữ liệu trong 2 controller Blog*.
 */
return [
    'categories' => ['Kinh nghiệm du lịch', 'Điểm đến', 'Ẩm thực', 'Mẹo hay'],

    'posts' => [
        [
            'slug' => '5-bai-bien-dep-nhat-viet-nam',
            'title' => '5 bãi biển đẹp nhất Việt Nam không thể bỏ lỡ',
            'excerpt' => 'Từ đảo ngọc Phú Quốc đến Côn Đảo hoang sơ: năm bãi biển đáng để bạn dành trọn một kỳ nghỉ.',
            'category' => 'Điểm đến',
            'image' => 'blog1.jpg',
            'date' => '2026-09-28',
            'read' => 5,
            'featured' => true,
            'intro' => 'Việt Nam có hơn 3.000 km bờ biển, mỗi miền một vẻ riêng. Dưới đây là năm điểm biển được nhiều du khách yêu thích, kèm vài gợi ý nhỏ để chuyến đi của bạn trọn vẹn hơn.',
            'quote' => 'Biển không chỉ là một điểm đến, mà là nơi chữa lành tâm hồn.',
            'sections' => [
                ['Phú Quốc – Đảo ngọc của Việt Nam', [
                    'Phú Quốc nổi tiếng với nước biển trong, cát mịn và hoàng hôn rực rỡ. Bãi Sao, bãi Khem và vùng biển phía Nam đảo là những nơi nhiều người chọn để nghỉ dưỡng.',
                    'Mùa khô thường kéo dài từ khoảng tháng 11 đến tháng 4, biển êm và ít mưa, thuận tiện cho các hoạt động lặn ngắm san hô hay đi cano giữa các đảo nhỏ.',
                ]],
                ['Nha Trang – Vịnh biển sôi động', [
                    'Nha Trang vừa có thành phố náo nhiệt, vừa có những hòn đảo yên tĩnh chỉ cách bờ một chuyến tàu ngắn. Du khách thường kết hợp tắm biển với tour đảo, lặn biển và thưởng thức hải sản.',
                ]],
                ['Đà Nẵng – Biển, núi và thành phố', [
                    'Bãi biển Mỹ Khê trải dài ngay cạnh trung tâm thành phố, thuận tiện cho cả nhóm bạn lẫn gia đình có trẻ nhỏ. Từ đây bạn có thể đi tiếp đến bán đảo Sơn Trà hoặc phố cổ Hội An chỉ trong nửa ngày.',
                ]],
                ['Quy Nhơn – Vẻ đẹp còn yên tĩnh', [
                    'Quy Nhơn có Eo Gió, Kỳ Co và những bãi đá nhỏ phù hợp với người thích chụp ảnh và thích không khí thư thả. Nếu đi cuối tuần, hãy đến sớm để tránh đông khách.',
                ]],
                ['Côn Đảo – Hoang sơ và lắng đọng', [
                    'Côn Đảo thu hút bởi biển trong, rừng nguyên sinh và nhịp sống chậm. Đây là lựa chọn hợp với những ai muốn một chuyến đi yên tĩnh, kết hợp nghỉ dưỡng và tìm hiểu lịch sử địa phương.',
                ]],
            ],
        ],
        [
            'slug' => 'kinh-nghiem-du-lich-phu-quoc-lan-dau',
            'title' => 'Kinh nghiệm du lịch Phú Quốc cho lần đầu đến đảo',
            'excerpt' => 'Nên ở đâu, đi mùa nào, di chuyển thế nào: những điều cần biết trước khi đặt vé ra đảo.',
            'category' => 'Kinh nghiệm du lịch',
            'image' => 'blog2.jpg',
            'date' => '2026-09-24',
            'read' => 4,
            'featured' => false,
            'intro' => 'Lần đầu ra đảo, bạn sẽ có khá nhiều câu hỏi. Bài viết này gom lại những điều thực tế nhất để bạn lên kế hoạch dễ hơn.',
            'quote' => null,
            'sections' => [
                ['Chọn thời điểm', ['Mùa khô thường thuận lợi nhất cho tắm biển và đi tour đảo. Mùa mưa vẫn đi được nhưng hãy theo dõi dự báo thời tiết và chọn lịch trình linh hoạt.']],
                ['Chọn khu vực lưu trú', ['Khu vực Dương Đông thuận tiện ăn uống, mua sắm. Phía Nam đảo yên tĩnh hơn, phù hợp nghỉ dưỡng. Hãy chọn theo kiểu chuyến đi bạn muốn.']],
                ['Di chuyển trên đảo', ['Thuê xe máy là cách phổ biến, nhưng nếu không tự tin bạn nên đặt xe có tài xế hoặc đi theo tour. Luôn đội mũ bảo hiểm và mang theo giấy tờ cần thiết.']],
            ],
        ],
        [
            'slug' => 'goi-y-lich-trinh-mien-trung-4-ngay',
            'title' => 'Gợi ý lịch trình miền Trung 4 ngày: Đà Nẵng – Hội An – Huế',
            'excerpt' => 'Một vòng miền Trung gọn gàng cho người ít ngày nghỉ, vẫn đủ biển, phố cổ và di sản.',
            'category' => 'Kinh nghiệm du lịch',
            'image' => 'blog3.jpg',
            'date' => '2026-09-20',
            'read' => 6,
            'featured' => false,
            'intro' => 'Chỉ với bốn ngày, bạn vẫn có thể đi qua ba điểm đến nổi bật của miền Trung nếu sắp xếp hợp lý.',
            'quote' => null,
            'sections' => [
                ['Ngày 1 – Đà Nẵng', ['Đến Đà Nẵng, nhận phòng và dành buổi chiều cho biển Mỹ Khê. Tối ghé cầu Rồng hoặc dạo bờ sông Hàn.']],
                ['Ngày 2 – Hội An', ['Đi Hội An từ sáng, dạo phố cổ, thử các món địa phương và ngắm đèn lồng khi trời tối.']],
                ['Ngày 3–4 – Huế', ['Di chuyển ra Huế, tham quan Đại Nội và các lăng tẩm. Dành thời gian còn lại để thưởng thức ẩm thực Huế trước khi về.']],
            ],
        ],
        [
            'slug' => 'am-thuc-mien-nam-nen-thu',
            'title' => 'Những món ăn miền Nam nên thử một lần',
            'excerpt' => 'Từ hủ tiếu, bánh xèo đến cá lóc nướng trui: vài món gợi ý cho chuyến đi về miền Tây.',
            'category' => 'Ẩm thực',
            'image' => 'blog-list1.jpg',
            'date' => '2026-09-15',
            'read' => 4,
            'featured' => false,
            'intro' => 'Ẩm thực miền Nam đậm đà, phóng khoáng và gắn liền với sông nước. Đây là vài món bạn khó bỏ qua.',
            'quote' => null,
            'sections' => [
                ['Bánh xèo và các món cuốn', ['Bánh xèo giòn ăn kèm rau sống và nước mắm chua ngọt là món quen thuộc ở nhiều tỉnh miền Tây.']],
                ['Hủ tiếu và các món nước', ['Mỗi địa phương có một kiểu hủ tiếu riêng. Hãy thử ở quán địa phương để cảm nhận sự khác biệt.']],
                ['Trái cây theo mùa', ['Đi đúng mùa, bạn sẽ có dịp thưởng thức trái cây ngay tại vườn với giá hợp lý và độ tươi trọn vẹn.']],
            ],
        ],
        [
            'slug' => 'meo-chuan-bi-hanh-ly-di-bien',
            'title' => 'Chuẩn bị hành lý đi biển: danh sách gọn mà đủ',
            'excerpt' => 'Mang gì, bỏ gì để vali nhẹ hơn mà chuyến đi vẫn thoải mái.',
            'category' => 'Mẹo hay',
            'image' => 'blog-list2.jpg',
            'date' => '2026-09-10',
            'read' => 3,
            'featured' => false,
            'intro' => 'Đi biển không cần mang quá nhiều. Một danh sách gọn sẽ giúp bạn nhẹ vai và đỡ quên đồ.',
            'quote' => null,
            'sections' => [
                ['Đồ cần thiết', ['Kem chống nắng, mũ rộng vành, kính râm, dép đi biển và một chiếc túi chống nước nhỏ cho điện thoại, giấy tờ.']],
                ['Đồ dễ quên', ['Sạc dự phòng, thuốc cá nhân, túi đựng đồ ướt và bản sao giấy tờ tùy thân thường bị quên nhất.']],
            ],
        ],
        [
            'slug' => 'ngam-hoang-hon-dep-o-dau',
            'title' => 'Ngắm hoàng hôn ở đâu đẹp nhất trong chuyến đi biển?',
            'excerpt' => 'Vài điểm ngắm hoàng hôn quen thuộc cùng mẹo chọn giờ và vị trí để có khoảnh khắc đẹp.',
            'category' => 'Mẹo hay',
            'image' => 'blog-list3.jpg',
            'date' => '2026-09-05',
            'read' => 3,
            'featured' => false,
            'intro' => 'Hoàng hôn ở biển luôn có sức hút riêng. Một chút chuẩn bị sẽ giúp bạn không bỏ lỡ khoảnh khắc đẹp nhất.',
            'quote' => null,
            'sections' => [
                ['Chọn đúng hướng biển', ['Bờ biển hướng Tây như Phú Quốc có lợi thế rõ rệt khi ngắm hoàng hôn. Với các bãi hướng Đông, hãy đi bình minh.']],
                ['Đến sớm hơn dự định', ['Hãy có mặt trước giờ mặt trời lặn khoảng 30–45 phút để chọn chỗ và đón trọn ánh sáng vàng.']],
            ],
        ],
    ],
];
