/* Khởi tạo chung cho mọi trang khách (trước đây là script.js của template Ravelo, 500 dòng).
   16/11/2026: bỏ các khối nhắm vào phần tử không còn trang nào có (menu template, slider slick, popup magnific,
   ô tìm kiếm/sidebar template, thanh trượt giá jQuery UI, nút lên đầu trang của header cũ).
   Phần chỉ trang "Hướng dẫn viên" cần (thanh kỹ năng, bộ đếm, lọc isotope, hover) chuyển sang team-page.js. */
(function ($) {
    "use strict";

    $(document).ready(function () {
        // ## Nice Select: mọi <select> trừ class .no-nice
        $("select").not(".no-nice").niceSelect();

        // ## AOS Animation (data-aos)
        AOS.init();
    });

    // ## Preloader
    $(window).on("load", function () {
        if ($(".preloader").length) {
            $(".preloader").delay(200).fadeOut(500);
        }
    });
})(window.jQuery);
