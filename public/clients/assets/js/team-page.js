/* Trang "Hướng dẫn viên" (/travel-guides): thanh kỹ năng, bộ đếm, lọc isotope, hiện mô tả khi rê chuột.
   Tách từ script.js của template (16/11/2026); nạp qua nhóm team.js cùng các plugin appear / skillBars / imagesLoaded / isotope. */
(function ($) {
    "use strict";

    $(document).ready(function () {
        // ## SkillBar
        if ($(".skillbar").length) {
            $(".skillbar").appear(function () {
                $(".skillbar").skillBars({
                    from: 0,
                    speed: 4000,
                    interval: 100,
                });
            });
        }

        // ## Fact Counter
        if ($(".counter-text-wrap").length) {
            $(".counter-text-wrap").appear(
                function () {
                    var $t = $(this),
                        n = $t.find(".count-text").attr("data-stop"),
                        r = parseInt($t.find(".count-text").attr("data-speed"), 10);

                    if (!$t.hasClass("counted")) {
                        $t.addClass("counted");
                        $({
                            countNum: $t.find(".count-text").text(),
                        }).animate(
                            {
                                countNum: n,
                            },
                            {
                                duration: r,
                                easing: "linear",
                                step: function () {
                                    $t.find(".count-text").text(Math.floor(this.countNum));
                                },
                                complete: function () {
                                    $t.find(".count-text").text(this.countNum);
                                },
                            },
                        );
                    }
                },
                {
                    accY: 0,
                },
            );
        }

        // ## Destinations Filter (isotope)
        $(".destinations-active").imagesLoaded(function () {
            var items = $(".destinations-active").isotope({
                itemSelector: ".item",
                percentPosition: true,
            });
            $(".destinations-nav").on("click", "li", function () {
                items.isotope({
                    filter: $(this).attr("data-filter"),
                });
            });
            $(".destinations-nav li").on("click", function (event) {
                $(this).siblings(".active").removeClass("active");
                $(this).addClass("active");
                event.preventDefault();
            });
        });

        // ## Hover Content
        $(".hover-content").hover(
            function () {
                $(this).find(".inner-content").slideDown();
            },
            function () {
                $(this).find(".inner-content").slideUp();
            },
        );
    });
})(window.jQuery);
