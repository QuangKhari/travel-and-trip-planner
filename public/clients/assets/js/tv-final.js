/* tv-final.js — hành vi nhỏ cho trang "Giới thiệu" + "Điểm đến".
   JS thuần, không cần thư viện, không sửa tv-destination.js.
   Nạp với `defer` ở cuối trang (hoặc trong footer). Lỗi ở đây không làm hỏng trang. */
(function () {
    "use strict";

    var root = document.querySelector(".tvf");
    if (!root) return;

    var reduce =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var hasIO = "IntersectionObserver" in window;
    function $$(s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    }

    /* 1. Hiện dần khi cuộn (.tvf-rv) ------------------------------------ */
    var rv = $$(".tvf-rv");
    if (rv.length) {
        if (!hasIO || reduce) {
            rv.forEach(function (el) {
                el.classList.add("is-in");
            });
        } else {
            var io = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (e) {
                        if (e.isIntersecting) {
                            e.target.classList.add("is-in");
                            io.unobserve(e.target);
                        }
                    });
                },
                { rootMargin: "0px 0px -8% 0px", threshold: 0.08 },
            );
            rv.forEach(function (el) {
                io.observe(el);
            });
            // an toàn: sau 2.5s luôn hiện hết (tránh trường hợp observer không chạy)
            setTimeout(function () {
                rv.forEach(function (el) {
                    el.classList.add("is-in");
                });
            }, 2500);
        }
    }

    /* 2. Đếm số liệu (data-count) khi cuộn tới ------------------------- */
    var nums = $$("[data-count]");
    function fmt(n) {
        try {
            return Math.round(n).toLocaleString("vi-VN");
        } catch (e) {
            return String(Math.round(n));
        }
    }
    function run(el) {
        var to = parseFloat(el.getAttribute("data-count"));
        if (!isFinite(to) || to <= 0) return;
        var t0 = null,
            dur = 1100;
        function step(t) {
            if (t0 === null) t0 = t;
            var k = Math.min(1, (t - t0) / dur);
            k = 1 - Math.pow(1 - k, 3); // easeOutCubic
            el.textContent = fmt(to * k);
            if (k < 1) requestAnimationFrame(step);
            else el.textContent = fmt(to);
        }
        requestAnimationFrame(step);
    }
    if (nums.length && hasIO && !reduce) {
        var io2 = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) {
                        run(e.target);
                        io2.unobserve(e.target);
                    }
                });
            },
            { threshold: 0.6 },
        );
        nums.forEach(function (el) {
            io2.observe(el);
        });
    }

    /* 3. Trang Điểm đến ------------------------------------------------- */
    var dest = document.getElementById("tvpDest");
    if (!dest) return;

    // 3a. Thanh lọc dính: thêm bóng + bo tròn khi đang "dính" dưới header
    var bar = dest.querySelector(".tvp-chipbar");
    var sentinel = dest.querySelector(".tvf-sentinel");
    if (bar && sentinel && hasIO) {
        new IntersectionObserver(
            function (en) {
                bar.classList.toggle(
                    "is-stuck",
                    !en[0].isIntersecting && en[0].boundingClientRect.top < 0,
                );
            },
            { rootMargin: "-90px 0px 0px 0px", threshold: 0 },
        ).observe(sentinel);
    }

    // 3b. Chip được chọn luôn nằm giữa thanh cuộn ngang (điện thoại)
    var chips = document.getElementById("tvpChips");
    if (chips) {
        chips.addEventListener("click", function () {
            requestAnimationFrame(function () {
                var on = chips.querySelector(".tvp-chip.on");
                if (on && on.scrollIntoView) {
                    on.scrollIntoView({
                        inline: "center",
                        block: "nearest",
                        behavior: reduce ? "auto" : "smooth",
                    });
                }
            });
        });
    }

    // 3c. Gợi ý tìm nhanh: bấm là tìm luôn, không cần gõ
    var q = document.getElementById("tvpQ");
    var form = document.getElementById("tvpSearch");
    $$("[data-q]", dest).forEach(function (b) {
        b.addEventListener("click", function () {
            if (!q || !form) return;
            q.value = b.getAttribute("data-q");
            form.dispatchEvent(
                new Event("submit", { cancelable: true, bubbles: true }),
            );
        });
    });

    // 3d. Phím tắt: "/" để vào ô tìm kiếm, Esc để xóa
    document.addEventListener("keydown", function (e) {
        if (!q) return;
        var t = e.target,
            typing =
                t &&
                (t.tagName === "INPUT" ||
                    t.tagName === "TEXTAREA" ||
                    t.tagName === "SELECT" ||
                    t.isContentEditable);
        if (e.key === "/" && !typing && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            q.focus();
            q.select();
        } else if (e.key === "Escape" && t === q && q.value) {
            q.value = "";
            q.dispatchEvent(new Event("input", { bubbles: true }));
        }
    });
})();
