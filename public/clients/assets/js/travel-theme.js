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

    /* 3. Nghiêng 3D theo chuột (chỉ desktop) */
    if (fine && !reduce) {
        var SEL = ".tv-card, .tv-chip";
        document.addEventListener("pointermove", function (e) {
            var c = e.target.closest && e.target.closest(SEL);
            if (!c) return;
            var r = c.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width - 0.5,
                y = (e.clientY - r.top) / r.height - 0.5;
            c.style.transform =
                "perspective(900px) rotateX(" +
                (-y * 6).toFixed(2) +
                "deg) rotateY(" +
                (x * 8).toFixed(2) +
                "deg) translateY(-8px)";
        });
        document.addEventListener("pointerout", function (e) {
            var c = e.target.closest && e.target.closest(SEL);
            if (c && !c.contains(e.relatedTarget)) c.style.transform = "";
        });
    }

    /* 4. Ripple khi bấm nút */
    document.addEventListener("click", function (e) {
        var b = e.target.closest && e.target.closest(".theme-btn");
        if (!b || reduce) return;
        var r = b.getBoundingClientRect(),
            s = Math.max(r.width, r.height);
        var d = document.createElement("span");
        d.className = "tv-ripple";
        d.style.cssText =
            "width:" +
            s +
            "px;height:" +
            s +
            "px;left:" +
            (e.clientX - r.left - s / 2) +
            "px;top:" +
            (e.clientY - r.top - s / 2) +
            "px";
        b.appendChild(d);
        setTimeout(function () {
            d.remove();
        }, 700);
    });

    /* 5. Nút tim (hiện chỉ đổi màu, chưa lưu DB) */
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
})();
