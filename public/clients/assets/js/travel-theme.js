/* Travel Theme v2 — hiệu ứng động bổ sung (JS thuần, không cần jQuery).
   AOS / slick / counter của giao diện cũ vẫn chạy bình thường. */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* 1. Thanh tiến trình cuộn */
    var bar = document.createElement("div");
    bar.className = "tv-progress";
    document.body.appendChild(bar);
    var busy = false;
    function onScroll() {
        if (busy) return;
        busy = true;
        requestAnimationFrame(function () {
            var y = window.pageYOffset || 0;
            var h = document.documentElement.scrollHeight - innerHeight;
            bar.style.width = (h > 0 ? (y / h) * 100 : 0) + "%";
            busy = false;
        });
    }
    addEventListener("scroll", onScroll, { passive: true });
    onScroll();

    /* 1b. Hiện dần nhẹ khi cuộn tới (một lần, chỉ cho phần tử nằm dưới màn hình đầu tiên).
        Bỏ qua phần tử đã có AOS (data-aos) để không chạy hai hiệu ứng chồng nhau. */
    if (!reduce && "IntersectionObserver" in window) {
        var revealSel =
            ".tv-order, .tv-mini--card";
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

    /* 2. Header: đổi nền khi cuộn, mở/đóng ô tìm kiếm, menu tài khoản, menu điện thoại */
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

    /* 3. Hiện/ẩn mật khẩu (nút có data-toggle-pass nằm cạnh ô nhập) */
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

    /* 4. Hộp thoại mẫu: [data-modal-open="id"] / [data-modal-close] (thẻ <dialog>) */
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
