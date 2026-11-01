/*
 * Trang chủ (home.blade.php):
 *  - Hero: chuyển giữa "Chưa biết đi đâu" (câu mô tả -> Trip Planner) và "Đã biết muốn đi đâu" (ô tìm tour)
 *  - Tab "Chơi gì · Ăn gì · Lễ hội sắp tới"
 *  - Cả trang đổi theo cảm hứng đang chọn ở bản đồ (sự kiện "tv:inspiration" do inspiration-hub.js phát):
 *      màu nhấn, nhãn "Ưu tiên cho cảm hứng ...", thứ tự nơi ở khối "Tháng N nên đi đâu" và tour ở "Tour sắp khởi hành".
 * File này phải nạp TRƯỚC inspiration-hub.js (hub dùng defer) để nhận được sự kiện đầu tiên.
 */
(function () {
    "use strict";

    /* ---------- tab dùng chung (role=tablist) ---------- */
    function tabs(list) {
        if (!list) return;
        var all = [].slice.call(list.querySelectorAll('[role="tab"]'));
        function show(t, focus) {
            all.forEach(function (x) {
                var on = x === t;
                x.setAttribute("aria-selected", on ? "true" : "false");
                x.tabIndex = on ? 0 : -1;
                var p = document.getElementById(x.getAttribute("aria-controls"));
                if (p) p.hidden = !on;
            });
            if (focus) t.focus();
        }
        all.forEach(function (t, i) {
            t.addEventListener("click", function () {
                show(t);
            });
            t.addEventListener("keydown", function (e) {
                var to = e.key === "ArrowRight" ? i + 1 : e.key === "ArrowLeft" ? i - 1 : null;
                if (to === null) return;
                e.preventDefault();
                show(all[(to + all.length) % all.length], true);
            });
        });
    }

    function ready(fn) {
        if (document.readyState !== "loading") fn();
        else document.addEventListener("DOMContentLoaded", fn);
    }

    ready(function () {
        tabs(document.querySelector(".hx-tabs"));
        tabs(document.querySelector(".h3-xtabs"));

        // dải ảnh mở rộng: rê chuột hoặc Tab tới lát nào thì lát đó nở ra
        [].forEach.call(document.querySelectorAll("[data-lx]"), function (ul) {
            var items = [].slice.call(ul.querySelectorAll(".lx-item"));
            function on(it) {
                items.forEach(function (x) {
                    x.classList.toggle("is-on", x === it);
                });
            }
            items.forEach(function (it) {
                it.addEventListener("mouseenter", function () {
                    on(it);
                });
                it.addEventListener("focusin", function () {
                    on(it);
                });
            });
        });

        // gợi ý nhanh: điền câu mẫu vào ô mô tả
        var prompt = document.getElementById("hxPrompt");
        [].forEach.call(document.querySelectorAll(".hx-quick [data-q]"), function (b) {
            b.addEventListener("click", function () {
                prompt.value = b.getAttribute("data-q");
                prompt.focus();
            });
        });
        var aiForm = document.getElementById("hxAi");
        if (aiForm) {
            aiForm.addEventListener("submit", function (e) {
                if (!prompt.value.trim()) {
                    e.preventDefault();
                    prompt.focus();
                }
            });
        }
    });

    /* ---------- cả trang đổi theo cảm hứng ---------- */
    var data = null;
    function scores() {
        if (data) return data;
        var el = document.getElementById("tvhData");
        data = {};
        try {
            JSON.parse(el.textContent).destinations.forEach(function (d) {
                data[d.slug] = d.scores || {};
            });
        } catch (e) {}
        return data;
    }
    function scoreOf(slug, ins) {
        var s = scores()[slug];
        return (s && s[ins]) || 0;
    }

    // Sắp lại các phần tử theo điểm (giữ nguyên thứ tự gốc khi bằng điểm)
    function byScore(items, ins, slugOf) {
        return items.slice().sort(function (a, b) {
            var d = scoreOf(slugOf(b), ins) - scoreOf(slugOf(a), ins);
            return d || +a.getAttribute("data-n") - +b.getAttribute("data-n");
        });
    }

    function reorderTours(ins) {
        var box = document.getElementById("hxTours");
        if (!box) return;
        var items = [].slice.call(box.children);
        byScore(items, ins, function (el) {
            return el.getAttribute("data-dest");
        }).forEach(function (el, i) {
            el.hidden = i >= 4;
            box.appendChild(el);
        });
    }

    // Khối mùa: chỉ đổi chỗ các ô ĐIỂM ĐẾN với nhau, ô lễ hội giữ vị trí; ô đầu luôn là ô lớn
    function reorderSeason(ins) {
        var box = document.getElementById("hxSeason");
        if (!box) return;
        var items = [].slice.call(box.children);
        items.forEach(function (el, i) {
            if (!el.hasAttribute("data-n")) el.setAttribute("data-n", i);
        });
        var orig = items.slice().sort(function (a, b) {
            return +a.getAttribute("data-n") - +b.getAttribute("data-n");
        });
        var dests = byScore(
            orig.filter(function (el) {
                return el.getAttribute("data-type") === "destination";
            }),
            ins,
            function (el) {
                return el.getAttribute("data-slug");
            },
        );
        var out = orig.map(function (el) {
            return el.getAttribute("data-type") === "destination" ? dests.shift() : el;
        });
        out.forEach(function (el, i) {
            el.classList.toggle("is-big", i === 0);
            box.appendChild(el);
        });
    }

    function theme() {
        var hub = document.getElementById("tvhHub"),
            main = document.getElementById("hxMain");
        if (!hub || !main) return;
        var cs = getComputedStyle(hub);
        var a = cs.getPropertyValue("--a").trim(),
            b = cs.getPropertyValue("--b").trim(),
            t = cs.getPropertyValue("--t").trim();
        if (b) {
            main.style.setProperty("--v3-teal", b);
            main.style.setProperty("--v3-teal-d", b);
        }
        if (t) main.style.setProperty("--v3-teal-50", t);
        if (a) main.style.setProperty("--hx-a", a);
    }

    document.addEventListener("tv:inspiration", function (e) {
        var d = e.detail || {};
        [].forEach.call(document.querySelectorAll(".hx-insname"), function (n) {
            n.textContent = d.name || "";
        });
        [].forEach.call(document.querySelectorAll(".hx-insicon"), function (n) {
            n.className = "fas " + (d.icon || "fa-compass") + " hx-insicon";
        });
        reorderTours(d.slug);
        reorderSeason(d.slug);
        // chờ hub gắn data-ins mới rồi mới đọc màu
        requestAnimationFrame(theme);
    });
})();
