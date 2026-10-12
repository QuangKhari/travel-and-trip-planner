/* travel-motion.js (UI p16) — hiệu ứng khi cuộn trang. JS thuần, tự tắt khi người dùng bật "giảm chuyển động".
   1) Hiện dần nội dung khi cuộn tới (có độ trễ lần lượt)
   2) Thanh tiến độ cuộn ở mép trên
   3) Header tự ẩn khi cuộn xuống, hiện lại khi cuộn lên
   4) Ảnh hero trôi nhẹ (parallax)
   5) Số liệu đếm lên (data-count)
   6) Mục lục bài blog sáng theo phần đang đọc
   7) Bấm "điểm nhấn" ở trang tour sẽ mở đúng ngày trong lịch trình
   Cần thẻ: <script>document.documentElement.classList.add('px-js')</script> ở đầu <head>. */
(function () {
    "use strict";

    var doc = document.documentElement;
    var reduce =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function $$(s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    }
    function ready(fn) {
        if (document.readyState === "loading")
            document.addEventListener("DOMContentLoaded", fn);
        else fn();
    }

    ready(function () {
        /* ---------- 7. Điểm nhấn -> mở ngày ---------- */
        $$("[data-open-day]").forEach(function (a) {
            a.addEventListener("click", function () {
                var d = document.getElementById(
                    "ngay-" + a.getAttribute("data-open-day"),
                );
                if (d && d.tagName === "DETAILS") d.open = true;
            });
        });

        /* ---------- 5. Đếm số ---------- */
        var counters = $$("[data-count]");
        var runCount = function (el) {
            var to = parseFloat(el.getAttribute("data-count")) || 0;
            var dec = parseInt(el.getAttribute("data-decimals") || "0", 10);
            if (reduce) {
                el.textContent = to.toFixed(dec);
                return;
            }
            var t0 = null,
                dur = 1400;
            var step = function (t) {
                if (t0 === null) t0 = t;
                var p = Math.min((t - t0) / dur, 1);
                var e = 1 - Math.pow(1 - p, 3);
                el.textContent = (to * e).toFixed(dec);
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        if (counters.length) {
            if ("IntersectionObserver" in window) {
                var cio = new IntersectionObserver(
                    function (en) {
                        en.forEach(function (x) {
                            if (x.isIntersecting) {
                                runCount(x.target);
                                cio.unobserve(x.target);
                            }
                        });
                    },
                    { threshold: 0.4 },
                );
                counters.forEach(function (c) {
                    c.textContent = "0";
                    cio.observe(c);
                });
            }
        }

        /* ---------- 6. Mục lục blog ---------- */
        var toc = $$(".px-toc a");
        if (toc.length && "IntersectionObserver" in window) {
            var map = {};
            toc.forEach(function (a) {
                map[a.getAttribute("href").slice(1)] = a;
            });
            var tio = new IntersectionObserver(
                function (en) {
                    en.forEach(function (x) {
                        if (x.isIntersecting) {
                            toc.forEach(function (a) {
                                a.classList.remove("is-on");
                            });
                            if (map[x.target.id])
                                map[x.target.id].classList.add("is-on");
                        }
                    });
                },
                { rootMargin: "-25% 0px -60% 0px" },
            );
            Object.keys(map).forEach(function (id) {
                var s = document.getElementById(id);
                if (s) tio.observe(s);
            });
        }

        if (reduce) return; // từ đây trở xuống là chuyển động

        /* ---------- 1. Hiện dần khi cuộn ---------- */
        var SEL = [
            ".px-infobar",
            ".px-hl__item",
            ".px-sec",
            ".px-side > *",
            ".px-related",
            ".px-feature",
            ".px-card",
            ".px-box",
            ".px-search",
            ".px-chips",
            ".px-article",
            ".px-article__sec",
            ".px-blog__related",
            ".px-quote",
            ".px-why__card",
            ".px-flow li",
            ".px-dest__card",
            ".px-stat",
            ".px-ahead",
            ".px-contact__chatwrap",
            ".px-contact__side > section",
            ".tv-grid > *",
            ".px-days > li",
        ];
        var targets = [];
        $$(SEL.join(",")).forEach(function (el) {
            if (targets.indexOf(el) === -1) targets.push(el);
        });
        $$("[data-rv]").forEach(function (el) {
            if (targets.indexOf(el) === -1) targets.push(el);
        });

        // độ trễ lần lượt theo vị trí trong cùng cha
        var perParent = new Map();
        targets.forEach(function (el) {
            var n = perParent.get(el.parentNode) || 0;
            perParent.set(el.parentNode, n + 1);
            el.style.setProperty("--d", Math.min(n, 5) * 80 + "ms");
            el.classList.add("px-rv");
        });

        if ("IntersectionObserver" in window) {
            var io = new IntersectionObserver(
                function (en) {
                    en.forEach(function (x) {
                        if (!x.isIntersecting) return;
                        var el = x.target;
                        el.classList.add("is-in");
                        io.unobserve(el);
                        // xong hiệu ứng thì trả lại style gốc để hover/transition riêng hoạt động
                        setTimeout(function () {
                            el.classList.remove("px-rv", "is-in");
                            el.style.removeProperty("--d");
                        }, 1100);
                    });
                },
                { threshold: 0.1, rootMargin: "0px 0px -6% 0px" },
            );
            targets.forEach(function (el) {
                io.observe(el);
            });
        } else {
            targets.forEach(function (el) {
                el.classList.remove("px-rv");
            });
        }

        /* ---------- 2. Thanh tiến độ ---------- */
        var bar = document.createElement("div");
        bar.className = "px-progress";
        bar.setAttribute("aria-hidden", "true");
        document.body.appendChild(bar);

        /* ---------- 3 + 4. Header ẩn/hiện, parallax ---------- */
        var header = document.getElementById("tvHeader");
        var heroImgs = $$(".px-hero__img, .px-ahero__img");
        var lastY = window.pageYOffset,
            ticking = false;

        var setTop = function (hidden) {
            doc.style.setProperty("--px-top", hidden ? "14px" : "84px");
        };
        setTop(false);

        var menuOpen = function () {
            return !!document.querySelector(
                "#tvNav.is-open, .tv-hsearch.is-open, .tv-user.is-open",
            );
        };

        var frame = function () {
            ticking = false;
            var y = window.pageYOffset;
            var max = doc.scrollHeight - window.innerHeight;
            bar.style.transform =
                "scaleX(" + (max > 0 ? Math.min(y / max, 1) : 0) + ")";

            if (header) {
                var dy = y - lastY;
                if (Math.abs(dy) > 6) {
                    var hide = dy > 0 && y > 220 && !menuOpen();
                    header.classList.toggle("is-hidden", hide);
                    setTop(hide);
                    lastY = y;
                }
                if (y < 60) {
                    header.classList.remove("is-hidden");
                    setTop(false);
                }
            }

            if (y < window.innerHeight * 1.3) {
                heroImgs.forEach(function (img) {
                    img.style.setProperty("--py", Math.round(y * 0.16) + "px");
                });
            }
        };
        var onScroll = function () {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(frame);
            }
        };
        window.addEventListener("scroll", onScroll, { passive: true });
        window.addEventListener("resize", onScroll);
        frame();
    });
})();
