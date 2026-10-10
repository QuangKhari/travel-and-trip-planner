/* Trang chi tiết tour — JS thuần: xem ảnh lớn, chia sẻ, thanh mục lục, mở/đóng lịch trình. */
(function () {
    "use strict";
    var $ = function (s, r) {
        return (r || document).querySelector(s);
    };
    var $$ = function (s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    };

    /* ---------- 1. Xem ảnh lớn ---------- */
    var items = $$("[data-gallery] .td-gallery__item");
    if (items.length) {
        var box,
            imgEl,
            countEl,
            idx = 0,
            lastFocus;

        var build = function () {
            box = document.createElement("div");
            box.className = "td-lb";
            box.setAttribute("role", "dialog");
            box.setAttribute("aria-modal", "true");
            box.setAttribute("aria-label", "Xem ảnh");
            box.innerHTML =
                '<button type="button" class="td-lb__close" aria-label="Đóng"><i class="fal fa-times"></i></button>' +
                '<button type="button" class="td-lb__nav td-lb__prev" aria-label="Ảnh trước"><i class="fal fa-chevron-left"></i></button>' +
                '<figure><img alt=""><figcaption></figcaption></figure>' +
                '<button type="button" class="td-lb__nav td-lb__next" aria-label="Ảnh sau"><i class="fal fa-chevron-right"></i></button>';
            document.body.appendChild(box);
            imgEl = $("img", box);
            countEl = $("figcaption", box);

            box.addEventListener("click", function (e) {
                if (e.target.closest(".td-lb__close") || e.target === box)
                    close();
                else if (e.target.closest(".td-lb__prev")) show(idx - 1);
                else if (e.target.closest(".td-lb__next")) show(idx + 1);
            });
        };
        var show = function (n) {
            idx = (n + items.length) % items.length;
            imgEl.src = items[idx].dataset.full;
            imgEl.alt = $("img", items[idx]).alt;
            countEl.textContent = idx + 1 + " / " + items.length;
        };
        var open = function (n) {
            if (!box) build();
            lastFocus = document.activeElement;
            show(n);
            box.classList.add("is-open");
            document.body.style.overflow = "hidden";
            $(".td-lb__close", box).focus();
        };
        var close = function () {
            box.classList.remove("is-open");
            document.body.style.overflow = "";
            if (lastFocus) lastFocus.focus();
        };
        items.forEach(function (b, i) {
            b.addEventListener("click", function () {
                open(i);
            });
        });
        var cnt = $(".td-gallery__count");
        if (cnt)
            cnt.addEventListener("click", function () {
                open(0);
            });
        document.addEventListener("keydown", function (e) {
            if (!box || !box.classList.contains("is-open")) return;
            if (e.key === "Escape") close();
            else if (e.key === "ArrowLeft") show(idx - 1);
            else if (e.key === "ArrowRight") show(idx + 1);
        });
    }

    /* ---------- 2. Chia sẻ ---------- */
    var share = $("[data-share]");
    if (share) {
        share.addEventListener("click", function () {
            var data = {
                title: share.dataset.title,
                url: location.href.split("#")[0],
            };
            var done = function () {
                var label = $("span", share),
                    old = label.textContent;
                label.textContent = "Đã sao chép liên kết";
                setTimeout(function () {
                    label.textContent = old;
                }, 2200);
            };
            if (navigator.share) {
                navigator.share(data).catch(function () {});
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(data.url).then(done, function () {
                    window.prompt("Sao chép liên kết:", data.url);
                });
            } else {
                window.prompt("Sao chép liên kết:", data.url);
            }
        });
    }

    /* ---------- 3. Mục lục: đánh dấu phần đang xem ---------- */
    var tabs = $$("#tdTabs a");
    if (tabs.length && "IntersectionObserver" in window) {
        var map = {};
        tabs.forEach(function (a) {
            map[a.getAttribute("href").slice(1)] = a;
        });
        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) {
                        tabs.forEach(function (t) {
                            t.classList.remove("is-on");
                        });
                        if (map[en.target.id])
                            map[en.target.id].classList.add("is-on");
                    }
                });
            },
            { rootMargin: "-30% 0px -60% 0px" },
        );
        Object.keys(map).forEach(function (id) {
            var s = document.getElementById(id);
            if (s) io.observe(s);
        });
    }

    /* ---------- 4. Mở / thu gọn tất cả các ngày ---------- */
    var toggle = $("#tdToggleAll");
    if (toggle) {
        toggle.addEventListener("click", function () {
            var open = toggle.dataset.open !== "true";
            $$(".td-day").forEach(function (d) {
                d.open = open;
            });
            toggle.dataset.open = open ? "true" : "false";
            toggle.textContent = open ? "Thu gọn tất cả" : "Mở tất cả";
        });
    }

    /* ---------- 5. Thanh đặt tour trên điện thoại: ẩn khi thẻ đặt tour đã hiện ---------- */
    var mbar = $("#tdMbar"),
        book = $("#tdBook");
    if (mbar && book && "IntersectionObserver" in window) {
        new IntersectionObserver(function (e) {
            mbar.classList.toggle("is-hidden", e[0].isIntersecting);
        }).observe(book);
    }
})();
