/* Travel Theme v2 — hiệu ứng động bổ sung (JS thuần, không cần jQuery).
   AOS / slick / counter của giao diện cũ vẫn chạy bình thường. */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;
    var fine =
        window.matchMedia &&
        matchMedia("(hover: hover) and (pointer: fine)").matches;

    /* 1. Thanh tiến trình cuộn + 2. Parallax ảnh hero */
    var bar = document.createElement("div");
    bar.className = "tv-progress";
    document.body.appendChild(bar);
    var heroBg = document.querySelector(".tv-hero__bg");
    var busy = false;
    function onScroll() {
        if (busy) return;
        busy = true;
        requestAnimationFrame(function () {
            var y = window.pageYOffset || 0;
            var h = document.documentElement.scrollHeight - innerHeight;
            bar.style.width = (h > 0 ? (y / h) * 100 : 0) + "%";
            if (heroBg && !reduce && y < 1000)
                heroBg.style.transform =
                    "translate3d(0," + (y * 0.22).toFixed(1) + "px,0)";
            busy = false;
        });
    }
    addEventListener("scroll", onScroll, { passive: true });
    onScroll();

    /* 3. Hiện dần nhẹ khi cuộn tới (một lần, chỉ cho phần tử nằm dưới màn hình đầu tiên).
        Bỏ qua phần tử đã có AOS (data-aos) để không chạy hai hiệu ứng chồng nhau. */
    if (!reduce && "IntersectionObserver" in window) {
        var revealSel =
            ".tv-card, .tv-dest__item, .tv-steps li, .tv-order, .tv-mini--card, .tv-contact__list li, .tc-faq details";
        var items = [].slice
            .call(document.querySelectorAll(revealSel))
            .filter(function (n) {
                return (
                    !n.closest("[data-aos]") &&
                    n.getBoundingClientRect().top > window.innerHeight * 0.92
                );
            });
        var rio = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    var t = en.target;
                    t.classList.add("is-in");
                    rio.unobserve(t);
                    setTimeout(function () {
                        t.classList.remove("tv-reveal", "is-in");
                        t.style.transitionDelay = "";
                    }, 900);
                });
            },
            { rootMargin: "0px 0px -8% 0px" },
        );
        items.forEach(function (n, i) {
            n.classList.add("tv-reveal");
            n.style.transitionDelay = (i % 3) * 70 + "ms";
            rio.observe(n);
        });
        // phòng hờ: sau 6 giây phần tử nào chưa hiện thì hiện luôn (tránh bị ẩn mãi)
        setTimeout(function () {
            items.forEach(function (n) {
                if (
                    n.classList.contains("tv-reveal") &&
                    !n.classList.contains("is-in")
                )
                    n.classList.add("is-in");
            });
        }, 6000);
    }

    /* 4. Nút tim (hiện chỉ đổi màu, chưa lưu DB) */
    document.addEventListener("click", function (e) {
        var h = e.target.closest && e.target.closest(".tv-heart");
        if (!h) return;
        e.preventDefault();
        h.classList.toggle("is-liked");
    });

    /* 6. Nhớ thẻ cảm hứng bấm gần nhất (localStorage) */
    var KEY = "travel:interest";
    function safe(fn) {
        try {
            return fn();
        } catch (err) {
            return null;
        }
    }
    var last = safe(function () {
        return localStorage.getItem(KEY);
    });
    document.querySelectorAll(".tv-chip[data-interest]").forEach(function (c) {
        if (last && c.dataset.interest === last) c.classList.add("is-recent");
        c.addEventListener("click", function () {
            safe(function () {
                localStorage.setItem(KEY, c.dataset.interest);
            });
        });
    });

    /* 7. Header: đổi nền khi cuộn, mở/đóng ô tìm kiếm, menu tài khoản, menu điện thoại */
    var header = document.getElementById("tvHeader");
    if (header) {
        var syncHeader = function () {
            header.classList.toggle(
                "is-scrolled",
                header.classList.contains("is-solid") ||
                    (window.pageYOffset || 0) > 40,
            );
        };
        addEventListener("scroll", syncHeader, { passive: true });
        syncHeader();

        var hs = document.getElementById("tvHsearch");
        var user = document.getElementById("tvUser");
        var burger = document.getElementById("tvBurger");
        var nav = document.getElementById("tvNav");

        document.addEventListener("click", function (e) {
            if (hs && e.target.closest(".tv-hsearch__toggle")) {
                hs.classList.add("is-open");
                var inp = hs.querySelector("input");
                if (inp)
                    setTimeout(function () {
                        inp.focus();
                    }, 120);
                return;
            }
            if (user && e.target.closest(".tv-user__btn")) {
                user.classList.toggle("is-open");
                return;
            }
            if (burger && e.target.closest("#tvBurger")) {
                var open = !nav.classList.contains("is-open");
                nav.classList.toggle("is-open", open);
                burger.classList.toggle("is-open", open);
                burger.setAttribute("aria-expanded", open ? "true" : "false");
                return;
            }
            /* bấm ra ngoài thì đóng */
            if (user && !e.target.closest("#tvUser"))
                user.classList.remove("is-open");
            if (hs && !e.target.closest("#tvHsearch")) {
                var i2 = hs.querySelector("input");
                if (!i2 || !i2.value) hs.classList.remove("is-open");
            }
        });
        document.addEventListener("keydown", function (e) {
            if (e.key !== "Escape") return;
            if (user) user.classList.remove("is-open");
            if (hs) hs.classList.remove("is-open");
        });
    }

    /* 8. Hiện/ẩn mật khẩu (nút có data-toggle-pass nằm cạnh ô nhập) */
    document.addEventListener("click", function (e) {
        var b = e.target.closest && e.target.closest("[data-toggle-pass]");
        if (!b) return;
        var input = b.parentNode.querySelector("input");
        if (!input) return;
        var show = input.type === "password";
        input.type = show ? "text" : "password";
        var ic = b.querySelector("i");
        if (ic) {
            ic.classList.toggle("fa-eye", !show);
            ic.classList.toggle("fa-eye-slash", show);
        }
    });

    /* 9. Trang "Điểm đến": lọc theo miền + nút "Xem thêm" (hiện 9 mục mỗi lần) */
    var domBar = document.getElementById("tvDomainFilter");
    var grid = document.getElementById("tvDestGrid");
    if (domBar && grid) {
        var PAGE = 9,
            limit = PAGE,
            domain = "all";
        var more = document.getElementById("tvDestMore");
        var none = document.getElementById("tvDestNone");
        var render = function () {
            var match = 0,
                shown = 0;
            grid.querySelectorAll(".tv-dest__item").forEach(function (i) {
                var ok = domain === "all" || i.dataset.domain === domain;
                if (ok) match++;
                var visible = ok && shown < limit;
                if (visible) shown++;
                i.classList.toggle("is-hidden", !visible);
            });
            if (none) none.hidden = match !== 0;
            if (more) more.hidden = match <= shown;
        };
        domBar.addEventListener("click", function (e) {
            var b = e.target.closest("[data-domain]");
            if (!b) return;
            domBar.querySelectorAll("[data-domain]").forEach(function (x) {
                x.classList.toggle("is-on", x === b);
            });
            domain = b.dataset.domain;
            limit = PAGE;
            render();
        });
        if (more)
            more.addEventListener("click", function () {
                limit += PAGE;
                render();
            });
        render();
    }

    /* 10. Hộp thoại mẫu: [data-modal-open="id"] / [data-modal-close] (thẻ <dialog>) */
    document.addEventListener("click", function (e) {
        var o = e.target.closest && e.target.closest("[data-modal-open]");
        if (o) {
            var d = document.getElementById(o.dataset.modalOpen);
            if (d && d.showModal) d.showModal();
            return;
        }
        var c = e.target.closest && e.target.closest("[data-modal-close]");
        if (c) {
            var dlg = c.closest("dialog");
            if (dlg) dlg.close();
        }
    });
})();
