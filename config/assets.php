<?php

/*
 * Gộp CSS / JS dùng chung của trang khách (header / footer) thành ít tệp hơn: cùng nội dung, ĐÚNG THỨ TỰ cũ, nên giao diện không đổi.
 * Trước: ~50 tệp CSS + ~17 tệp JS chặn hiển thị trên mọi trang (Lighthouse trang chuyến 52–56).
 *
 *   php artisan assets:bundle      -> public/clients/assets/build/{nhóm}.{hash}.css|js + manifest.json
 *
 * Sửa một tệp CSS / JS trong danh sách: chạy lại lệnh. Khi APP_DEBUG=true, nếu tệp nguồn mới hơn bản gộp thì tự dùng lại
 * từng tệp lẻ (không bao giờ hiện giao diện cũ vì quên gộp). ASSET_BUNDLES=false: luôn dùng tệp lẻ.
 * Tệp ngoài (Google Fonts, boxicons, toastr) giữ nguyên chỗ cũ giữa các nhóm để thứ tự đè CSS không đổi.
 * 16/11/2026: gộp các lớp theme thành explore / theme-pages / theme-tv / shell (giữ thứ tự), trang chủ cũng dùng nhóm gộp,
 * bỏ jQuery UI / slick / magnific-popup (không trang nào dùng), plugin của trang Hướng dẫn viên tách sang team.js.
 */
return [
    'enabled' => env('ASSET_BUNDLES', true),
    'dir' => 'clients/assets/build',
    'bundles' => [
        // ===== Trang con (blocks/header.blade.php + blocks/footer.blade.php) =====
        'site-a.css' => [
            'clients/assets/css/flaticon.min.css', 'clients/assets/css/fontawesome-5.14.0.min.css', 'clients/assets/css/bootstrap.min.css',
            'clients/assets/css/nice-select.min.css', 'clients/assets/css/aos.css', 'clients/assets/css/style.css', 'clients/assets/css/user-profile.css',
        ],
        // (boxicons từ unpkg nằm giữa a và b)
        'site-b.css' => [
            'clients/assets/css/css-login/fonts/material-icon/css/material-design-iconic-font.min.css', 'clients/assets/css/css-login/style.css',
            'clients/assets/css/custom-css.css', 'clients/assets/css/explore.css', 'clients/assets/css/gx-guide.css', 'clients/assets/css/gx-pages.css',
        ],
        // (toastr + Google Fonts nằm giữa b và c)
        'site-c.css' => [
            'clients/assets/css/travel-theme.css', 'clients/assets/css/theme-pages.css', 'clients/assets/css/inspiration.css',
            'clients/assets/css/theme-tv.css', 'clients/assets/css/shell.css', 'clients/assets/css/tv3-pages.css',
        ],
        'site-d.css' => [
            'clients/assets/css/travela-nature-unified.css', 'clients/assets/css/travela-nature-all-pages.css',
        ],
        // ===== Trang chủ (blocks/header_home.blade.php + blocks/footer_home.blade.php): đúng thứ tự cũ của trang chủ =====
        'home-a.css' => [
            'clients/assets/css/flaticon.min.css', 'clients/assets/css/fontawesome-5.14.0.min.css', 'clients/assets/css/bootstrap.min.css',
            'clients/assets/css/nice-select.min.css', 'clients/assets/css/aos.css', 'clients/assets/css/style.css',
            'clients/assets/css/jquery.datetimepicker.min.css', 'clients/assets/css/custom-css.css',
        ],
        // (boxicons nằm giữa)
        'home-b.css' => ['clients/assets/css/css-login/style.css'],
        // (toastr + Google Fonts nằm giữa)
        'home-c.css' => [
            'clients/assets/css/travel-theme.css', 'clients/assets/css/inspiration.css', 'clients/assets/css/inspiration-hub.css',
            'clients/assets/css/tv-weather.css', 'clients/assets/css/gx-guide.css', 'clients/assets/css/shell.css', 'clients/assets/css/tv3-home.css',
            'clients/assets/css/travela-nature-unified.css', 'clients/assets/css/travela-nature-all-pages.css',
        ],
        // Trang lập lịch trình /len-ke-hoach/{id} (nạp sau gói chung, giữ thứ tự cũ)
        'planner.css' => [
            'clients/assets/css/planner.css', 'clients/assets/css/planner-ai.css', 'clients/assets/css/planner-ux.css',
            'clients/assets/css/nature-luxury-planner.css', 'clients/assets/css/travela-nature-all-pages.css',
            'clients/assets/css/planner-group.css', 'clients/assets/css/planner-focus.css', 'clients/assets/css/planner-media.css',
        ],
        // JS cuối trang: chạy đồng bộ theo đúng thứ tự cũ (jQuery trước plugin)
        'site-a.js' => [
            'clients/assets/js/jquery-3.6.0.min.js', 'clients/assets/js/bootstrap.min.js', 'clients/assets/js/jquery.nice-select.min.js',
            'clients/assets/js/aos.js', 'clients/assets/js/script.js', 'clients/assets/js/custom-js.js', 'clients/assets/js/travel-theme.js',
            'clients/assets/js/page-motion.js', 'clients/assets/js/travel-chat.js', 'clients/assets/js/jquery.datetimepicker.full.min.js',
        ],
        // (toastr từ cdnjs nằm giữa)
        'site-b.js' => ['clients/assets/js/ux-pass.js'],
        // Trang chủ: hai nhóm, giữa là mobile_tabbar / trip_boot như cũ
        'home-a.js' => [
            'clients/assets/js/jquery-3.6.0.min.js', 'clients/assets/js/bootstrap.min.js', 'clients/assets/js/jquery.nice-select.min.js',
            'clients/assets/js/aos.js', 'clients/assets/js/script.js', 'clients/assets/js/custom-js.js', 'clients/assets/js/travel-theme.js',
        ],
        'home-b.js' => ['clients/assets/js/tv-home.js', 'clients/assets/js/travel-chat.js', 'clients/assets/js/jquery.datetimepicker.full.min.js'],
        // Trang "Hướng dẫn viên" (/travel-guides): plugin chỉ trang này dùng (thanh kỹ năng, bộ đếm, lọc isotope)
        'team.js' => [
            'clients/assets/js/appear.min.js', 'clients/assets/js/skill.bars.jquery.min.js', 'clients/assets/js/imagesloaded.pkgd.min.js',
            'clients/assets/js/isotope.pkgd.min.js', 'clients/assets/js/team-page.js',
        ],
    ],
    // Tệp JS LẺ được rút gọn (minify) bằng esbuild khi chạy assets:bundle (cần "npm ci"; không có esbuild thì bỏ qua, trang dùng tệp gốc).
    // Trang gọi \App\Support\AssetBundle::js('clients/assets/js/planner.js', 50): có bản rút gọn còn khớp nguồn thì dùng, không thì tệp gốc ?v=50.
    'minify' => [
        'clients/assets/js/planner.js', 'clients/assets/js/planner-ai-copilot.js', 'clients/assets/js/planner-ai-chat.js', 'clients/assets/js/planner-ai-explain.js',
        'clients/assets/js/planner-bookings.js', 'clients/assets/js/planner-expenses.js', 'clients/assets/js/planner-share.js', 'clients/assets/js/planner-watch.js',
        'clients/assets/js/planner-ready.js', 'clients/assets/js/planner-ux.js', 'clients/assets/js/planner-history.js', 'clients/assets/js/booking-wizard.js',
    ],
];
