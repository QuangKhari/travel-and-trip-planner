/* Chuyển động trang con (gộp 16/11/2026 từ sea-theme.js + client-ui-polish.js). JS thuần, không cần jQuery.
   Nạp SAU travel-theme.js trong gói site-a.js; trang chủ không nạp tệp này.
   Phần 1–2: trước đây sea-theme.js (bỏ Spotlight điểm đến — không còn trang nào có #tvSpot).
   Phần 3: trước đây client-ui-polish.js. */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* 1. Parallax rất nhẹ cho ảnh banner trang con (ảnh dịch chậm hơn trang) */
    var bannerBg = document.querySelector(".tv-banner__bg");
    if (bannerBg && !reduce) {
        var busy = false;
        var onScroll = function () {
            if (busy) return;
            busy = true;
            requestAnimationFrame(function () {
                var y = window.pageYOffset || 0;
                if (y < 700)
                    bannerBg.style.transform =
                        "translate3d(0," +
                        (Math.min(y, 350) * 0.14).toFixed(1) +
                        "px,0) scale(1.06)";
                busy = false;
            });
        };
        addEventListener("scroll", onScroll, { passive: true });
        onScroll();
    }

    /* 2. Hiện dần khi cuộn tới cho các khối chưa có hiệu ứng.
          Thêm/bớt selector ở đây nếu muốn. */
    if (!reduce && "IntersectionObserver" in window) {
        var sel = ".td-day, .tv-cta__box";
        var els = [].slice
            .call(document.querySelectorAll(sel))
            .filter(function (n) {
                return (
                    !n.closest("[data-aos]") &&
                    n.getBoundingClientRect().top > window.innerHeight * 0.95
                );
            });
        var io = new IntersectionObserver(
            function (list) {
                list.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    en.target.classList.add("is-in");
                    io.unobserve(en.target);
                });
            },
            { rootMargin: "0px 0px -6% 0px" },
        );
        els.forEach(function (n, i) {
            n.classList.add("sea-reveal");
            n.style.transitionDelay = (i % 3) * 60 + "ms";
            io.observe(n);
        });
        // phòng hờ: sau 5 giây phần tử nào chưa hiện thì hiện luôn
        setTimeout(function () {
            els.forEach(function (n) {
                n.classList.add("is-in");
            });
        }, 5000);
    }
})();

/* 3. Hiện dần các khối trang tour / trống (trước đây client-ui-polish.js) */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce) return;

    /* Reveal only elements that do not already use AOS/sea-theme reveal. */
    if ("IntersectionObserver" in window) {
        var selectors = [
            ".td-gallery",
            ".tv-empty",
        ];
        var nodes = document.querySelectorAll(selectors.join(","));
        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add("ui-polish-in");
                    io.unobserve(entry.target);
                });
            },
            { rootMargin: "0px 0px -8% 0px", threshold: 0.08 },
        );
        nodes.forEach(function (node) {
            if (node.closest("[data-aos]")) return;
            node.classList.add("ui-polish-reveal");
            io.observe(node);
        });
    }
})();
