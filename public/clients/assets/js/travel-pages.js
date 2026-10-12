/* travel-pages.js (UI p15) — JS thuần, không phụ thuộc jQuery.
   1) Xem video của tour trong cửa sổ nổi (YouTube / Vimeo / mp4)
   2) Thẻ mẹo xoay vòng ở cột ảnh trang đăng nhập / đăng ký
*/
(function () {
    "use strict";

    function ready(fn) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var $$ = function (s, r) {
            return Array.prototype.slice.call(
                (r || document).querySelectorAll(s),
            );
        };

        /* ---------- 1. Video ---------- */
        var triggers = $$("[data-video]");
        if (triggers.length) {
            var box = null,
                stage = null,
                lastFocus = null;

            var build = function () {
                box = document.createElement("div");
                box.className = "px-vid";
                box.setAttribute("role", "dialog");
                box.setAttribute("aria-modal", "true");
                box.setAttribute("aria-label", "Video giới thiệu");
                box.innerHTML =
                    '<button type="button" class="px-vid__close" aria-label="Đóng video"><i class="fal fa-times"></i></button>' +
                    '<div class="px-vid__box"></div>';
                document.body.appendChild(box);
                stage = box.querySelector(".px-vid__box");

                box.addEventListener("click", function (e) {
                    if (e.target === box || e.target.closest(".px-vid__close"))
                        close();
                });
            };

            var open = function (btn) {
                if (!box) build();
                lastFocus = document.activeElement;
                var type = btn.getAttribute("data-vtype");
                var src = btn.getAttribute("data-vsrc");
                var title = btn.getAttribute("data-vtitle") || "Video";
                if (!src || !/^https?:\/\//i.test(src)) return; // chỉ nhận http(s)
                stage.innerHTML = "";
                if (type === "file") {
                    var v = document.createElement("video");
                    v.src = src;
                    v.controls = true;
                    v.autoplay = true;
                    v.playsInline = true;
                    stage.appendChild(v);
                } else {
                    var f = document.createElement("iframe");
                    f.src = src;
                    f.title = title;
                    f.allow =
                        "autoplay; encrypted-media; picture-in-picture; fullscreen";
                    f.setAttribute("allowfullscreen", "");
                    f.referrerPolicy = "strict-origin-when-cross-origin";
                    stage.appendChild(f);
                }
                box.classList.add("is-open");
                document.body.style.overflow = "hidden";
                box.querySelector(".px-vid__close").focus();
            };

            var close = function () {
                if (!box) return;
                box.classList.remove("is-open");
                stage.innerHTML = ""; // gỡ iframe/video để dừng phát
                document.body.style.overflow = "";
                if (lastFocus && lastFocus.focus) lastFocus.focus();
            };

            triggers.forEach(function (b) {
                b.addEventListener("click", function () {
                    open(b);
                });
            });
            document.addEventListener("keydown", function (e) {
                if (
                    e.key === "Escape" &&
                    box &&
                    box.classList.contains("is-open")
                )
                    close();
            });
        }

        /* ---------- 2. Mẹo xoay vòng (trang tài khoản) ---------- */
        var tip = document.querySelector("[data-tips]");
        if (tip) {
            var items = $$(".px-aside__tip-item", tip);
            var next = tip.querySelector("[data-tips-next]");
            var idx = 0,
                timer = null;
            var reduce =
                window.matchMedia &&
                window.matchMedia("(prefers-reduced-motion: reduce)").matches;

            var show = function (n) {
                idx = (n + items.length) % items.length;
                items.forEach(function (it, i) {
                    it.classList.toggle("is-on", i === idx);
                });
            };
            var start = function () {
                if (reduce || items.length < 2) return;
                clearInterval(timer);
                timer = setInterval(function () {
                    show(idx + 1);
                }, 6000);
            };
            if (next) {
                next.addEventListener("click", function () {
                    show(idx + 1);
                    start();
                });
            }
            start();
        }
    });
})();
