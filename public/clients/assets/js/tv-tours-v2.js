/* tv-tours-v2.js — chuyển động nhẹ cho hero trang /tours. JS thuần; tự tắt khi bật "giảm chuyển động".
   1) Lá + ảnh trôi nhẹ khi cuộn (parallax, chỉ transform)
   2) Thanh công cụ kết quả: thêm bóng khi dính
   Xoá file + thẻ <script> thì trang vẫn dùng bình thường (chỉ mất hiệu ứng). */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce) return;

    var hero = document.querySelector(".tl-hero");
    if (!hero) return;
    var layers = [
        [hero.querySelector(".wt-deco.l"), 0.04],
        [hero.querySelector(".wt-deco.r"), -0.05],
        [hero.querySelector(".tl-hero__frame"), 0.02],
        [hero.querySelector(".tl-cloud--1"), 0.06],
    ].filter(function (x) {
        return x[0];
    });

    var ticking = false;
    function paint() {
        ticking = false;
        var y = window.pageYOffset;
        if (y > hero.offsetHeight + 80) return;
        layers.forEach(function (l) {
            l[0].style.translate = "0 " + (y * l[1] * -1).toFixed(1) + "px";
        });
    }
    window.addEventListener(
        "scroll",
        function () {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(paint);
            }
        },
        { passive: true },
    );
})();
