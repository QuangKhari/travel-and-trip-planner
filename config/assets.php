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
 */
return [
    'enabled' => env('ASSET_BUNDLES', true),
    'dir' => 'clients/assets/build',
    'bundles' => [
        'site-a.css' => [
            'clients/assets/css/flaticon.min.css', 'clients/assets/css/fontawesome-5.14.0.min.css', 'clients/assets/css/bootstrap.min.css',
            'clients/assets/css/magnific-popup.min.css', 'clients/assets/css/nice-select.min.css', 'clients/assets/css/aos.css', 'clients/assets/css/slick.min.css',
            'clients/assets/css/style.css', 'clients/assets/css/jquery-ui.min.css', 'clients/assets/css/user-profile.css',
        ],
        // (boxicons từ unpkg nằm giữa a và b)
        'site-b.css' => [
            'clients/assets/css/css-login/fonts/material-icon/css/material-design-iconic-font.min.css', 'clients/assets/css/css-login/style.css',
            'clients/assets/css/custom-css.css', 'clients/assets/css/explore-kit.css', 'clients/assets/css/explore-c.css',
            'clients/assets/css/gx-discover.css', 'clients/assets/css/gx-guide.css', 'clients/assets/css/gx-pages.css',
        ],
        // (toastr + Google Fonts nằm giữa b và c)
        'site-c.css' => [
            'clients/assets/css/travel-theme.css', 'clients/assets/css/sea-theme.css', 'clients/assets/css/client-ui-polish.css', 'clients/assets/css/travel-pages.css',
            'clients/assets/css/inspiration.css', 'clients/assets/css/tv-pages.css', 'clients/assets/css/tv-pages-10.css', 'clients/assets/css/tv-watercolor.css',
            'clients/assets/css/tv-watercolor-refine.css', 'clients/assets/css/tv-final.css', 'clients/assets/css/tv-tours-10.css', 'clients/assets/css/ux-pass.css',
            'clients/assets/css/tv-decor-fix.css', 'clients/assets/css/ux-watercolor.css', 'clients/assets/css/journal.css', 'clients/assets/css/mobile-nav.css',
            'clients/assets/css/site-ux.css', 'clients/assets/css/tv3.css', 'clients/assets/css/tv3-pages.css',
        ],
        'site-d.css' => [
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
            'clients/assets/js/jquery-3.6.0.min.js', 'clients/assets/js/bootstrap.min.js', 'clients/assets/js/appear.min.js', 'clients/assets/js/slick.min.js',
            'clients/assets/js/jquery.magnific-popup.min.js', 'clients/assets/js/jquery.nice-select.min.js', 'clients/assets/js/imagesloaded.pkgd.min.js',
            'clients/assets/js/skill.bars.jquery.min.js', 'clients/assets/js/isotope.pkgd.min.js', 'clients/assets/js/aos.js', 'clients/assets/js/script.js',
            'clients/assets/js/custom-js.js', 'clients/assets/js/travel-theme.js', 'clients/assets/js/sea-theme.js', 'clients/assets/js/client-ui-polish.js',
            'clients/assets/js/travel-chat.js', 'clients/assets/js/jquery.datetimepicker.full.min.js',
        ],
        // (toastr từ cdnjs nằm giữa)
        'site-b.js' => ['clients/assets/js/ux-pass.js'],
    ],
];
