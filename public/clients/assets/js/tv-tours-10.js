/* tv-tours-10.js — tương tác nhỏ dùng chung cho thẻ tour, trang /tours và /tour-detail.
   Không phụ thuộc jQuery. Xoá file + thẻ <script> là mọi thứ về như cũ (chỉ mất các tính năng bên dưới). */
(function () {
    "use strict";
    var $ = function (s, r) {
        return (r || document).querySelector(s);
    };
    var $$ = function (s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    };
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ---------- 1. Hiện dần khi cuộn (chỉ bật khi JS chạy) ---------- */
    document.documentElement.classList.add("wt-js");
    var rev = $$(".wt-reveal");
    if (rev.length) {
        if ("IntersectionObserver" in window && !reduce) {
            var io = new IntersectionObserver(
                function (es) {
                    es.forEach(function (e) {
                        if (e.isIntersecting) {
                            e.target.classList.add("is-in");
                            io.unobserve(e.target);
                        }
                    });
                },
                { threshold: 0.12 },
            );
            rev.forEach(function (n) {
                io.observe(n);
            });
        } else {
            rev.forEach(function (n) {
                n.classList.add("is-in");
            });
        }
    }

    /* ---------- 2. Yêu thích (lưu trên TRÌNH DUYỆT này bằng localStorage; chưa có tài khoản/CSDL) ---------- */
    var KEY = "wt_fav";
    var read = function () {
        try {
            return JSON.parse(localStorage.getItem(KEY) || "[]");
        } catch (e) {
            return [];
        }
    };
    var write = function (a) {
        try {
            localStorage.setItem(KEY, JSON.stringify(a));
        } catch (e) {}
    };
    function paintFav() {
        var list = read();
        $$("[data-fav]").forEach(function (b) {
            var on = list.indexOf(String(b.dataset.fav)) !== -1;
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-pressed", on ? "true" : "false");
        });
    }
    document.addEventListener("click", function (e) {
        var b = e.target.closest("[data-fav]");
        if (!b) return;
        e.preventDefault();
        e.stopPropagation();
        var id = String(b.dataset.fav),
            list = read(),
            i = list.indexOf(id);
        if (i === -1) list.push(id);
        else list.splice(i, 1);
        write(list);
        paintFav();
    });
    document.addEventListener("wt:cards", paintFav); // sau khi AJAX đổi danh sách thẻ
    paintFav();

    /* ---------- 3. Trang chi tiết: nút "Chia sẻ" thứ hai dùng lại nút chia sẻ của travel-detail.js ---------- */
    var proxy = $("[data-share-proxy]"),
        real = $(".td10-hero__tools [data-share]");
    if (proxy && real)
        proxy.addEventListener("click", function () {
            real.click();
        });

    /* ---------- 4. Thư viện ảnh: "Xem tất cả" mở ảnh lớn ---------- */
    var all = $("[data-gallery-all]");
    if (all)
        all.addEventListener("click", function () {
            var first = $("[data-gallery] .td-gallery__item");
            if (first) first.click();
        });

    /* ---------- 5. Booking box: số khách + tạm tính, gửi sang trang đặt tour ---------- */
    var form = $("#tdBookForm");
    if (form) {
        var priceA = Number(form.dataset.adult) || 0,
            priceC = Number(form.dataset.child) || 0,
            max = Number(form.dataset.max) || 1;
        var fields = {
            adults: { input: $("#tdAdults"), out: $("#tdAdultsOut"), min: 1 },
            children: {
                input: $("#tdChildren"),
                out: $("#tdChildrenOut"),
                min: 0,
            },
        };
        var totalEl = $("#tdTotal"),
            noteEl = $("#tdNote");
        var val = function (k) {
            return Number(fields[k].input.value) || 0;
        };
        var money = function (n) {
            return n.toLocaleString("vi-VN") + "đ";
        };
        var render = function () {
            var a = val("adults"),
                c = val("children"),
                sum = a + c;
            Object.keys(fields).forEach(function (k) {
                fields[k].out.textContent = val(k);
            });
            $$("[data-step]", form).forEach(function (b) {
                var k = b.dataset.for,
                    step = Number(b.dataset.step);
                b.disabled = step < 0 ? val(k) <= fields[k].min : sum >= max;
            });
            totalEl.textContent = money(a * priceA + c * priceC);
            noteEl.textContent =
                sum >= max
                    ? "Đã đạt số chỗ tối đa cho một đơn (" + max + " khách)."
                    : sum + " khách";
        };
        form.addEventListener("click", function (e) {
            var b = e.target.closest("[data-step]");
            if (!b) return;
            var k = b.dataset.for,
                next = val(k) + Number(b.dataset.step);
            if (next < fields[k].min) return;
            if (
                Number(b.dataset.step) > 0 &&
                val("adults") + val("children") >= max
            )
                return;
            fields[k].input.value = next;
            render();
        });
        render();
    }

    /* ---------- 6. Máy bay giấy bay ngang một đoạn nhỏ khi CTA xuất hiện (1 lần, không lặp) ---------- */
    var plane = $("[data-fly]");
    if (plane && "IntersectionObserver" in window && !reduce) {
        plane.style.transition =
            "transform 2.4s cubic-bezier(.2,.8,.2,1), opacity 1s";
        plane.style.transform = "translateX(-60px)";
        plane.style.opacity = "0";
        var po = new IntersectionObserver(
            function (es) {
                if (es[0].isIntersecting) {
                    plane.style.transform = "translateX(0)";
                    plane.style.opacity = "0.9";
                    po.disconnect();
                }
            },
            { threshold: 0.3 },
        );
        po.observe(plane);
    }
})();
