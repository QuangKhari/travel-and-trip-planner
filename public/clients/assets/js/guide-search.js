/* C3: gợi ý khi gõ cho ô tìm kiếm ở header và trang /tim-kiem.
   Gắn vào: #tvHsearch input[name=keyword] và mọi input có thuộc tính data-gx-suggest.
   Enter khi chưa chọn gợi ý nào vẫn gửi form như cũ (header: tìm tour; trang /tim-kiem: tìm nội dung).
   Cấu hình lấy từ window.GX_SEARCH = { suggest, page, tours } do header.blade.php khai báo. */
(function () {
    "use strict";
    var cfg = window.GX_SEARCH;
    if (!cfg || !window.fetch) return;

    var MIN = 2;
    var DELAY = 220;
    var uid = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function attach(input) {
        var host = input.closest("#tvHsearch") || input.closest(".gx-search") || input.parentNode;
        var id = "gx-suggest-" + ++uid;
        var list = el("div", "gx-suggest");
        list.id = id;
        list.setAttribute("role", "listbox");
        list.setAttribute("aria-label", "Gợi ý tìm kiếm");
        list.hidden = true;
        var status = el("div", "gx-sr");
        status.setAttribute("role", "status");
        host.appendChild(list);
        host.appendChild(status);
        host.classList.add("gx-suggest-host");

        input.setAttribute("role", "combobox");
        input.setAttribute("aria-autocomplete", "list");
        input.setAttribute("aria-expanded", "false");
        input.setAttribute("aria-controls", id);

        var timer = null;
        var ctrl = null;
        var active = -1;
        var cache = {};

        function options() {
            return Array.prototype.slice.call(list.querySelectorAll("[role=option]"));
        }
        function close() {
            list.hidden = true;
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
            active = -1;
        }
        function setActive(i) {
            var opts = options();
            opts.forEach(function (o) { o.setAttribute("aria-selected", "false"); o.classList.remove("is-active"); });
            active = i;
            if (i >= 0 && opts[i]) {
                opts[i].setAttribute("aria-selected", "true");
                opts[i].classList.add("is-active");
                input.setAttribute("aria-activedescendant", opts[i].id);
                opts[i].scrollIntoView({ block: "nearest" });
            } else {
                input.removeAttribute("aria-activedescendant");
            }
        }
        function option(href, parts, extra) {
            var a = el("a", "gx-suggest__item" + (extra ? " " + extra : ""));
            a.href = href;
            a.id = id + "-o" + list.children.length;
            a.setAttribute("role", "option");
            a.setAttribute("aria-selected", "false");
            parts.forEach(function (p) { if (p[1]) a.appendChild(el("span", p[0], p[1])); });
            list.appendChild(a);
            return a;
        }
        function render(q, items) {
            list.textContent = "";
            items.forEach(function (it) {
                option(it.url, [["gx-suggest__type", it.typeLabel], ["gx-suggest__title", it.title], ["gx-suggest__meta", it.meta]]);
            });
            option(cfg.page + "?q=" + encodeURIComponent(q), [["gx-suggest__title", "Xem tất cả kết quả cho “" + q + "”"]], "gx-suggest__all");
            option(cfg.tours + "?keyword=" + encodeURIComponent(q), [["gx-suggest__title", "Tìm tour “" + q + "”"]], "gx-suggest__all");
            list.hidden = false;
            input.setAttribute("aria-expanded", "true");
            status.textContent = items.length ? items.length + " gợi ý" : "Không có gợi ý";
            active = -1;
        }
        function load(q) {
            if (cache[q]) { render(q, cache[q]); return; }
            if (ctrl) ctrl.abort();
            ctrl = window.AbortController ? new AbortController() : null;
            fetch(cfg.suggest + "?q=" + encodeURIComponent(q), { headers: { Accept: "application/json" }, signal: ctrl ? ctrl.signal : undefined })
                .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                .then(function (d) {
                    cache[q] = d.items || [];
                    if (input.value.trim() === q) render(q, cache[q]);
                })
                .catch(function () { /* bỏ qua: huỷ yêu cầu cũ hoặc mất mạng */ });
        }

        input.addEventListener("input", function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < MIN) { close(); return; }
            timer = setTimeout(function () { load(q); }, DELAY);
        });
        input.addEventListener("keydown", function (e) {
            var opts = options();
            if (e.key === "Escape") { close(); return; }
            if (list.hidden || !opts.length) return;
            if (e.key === "ArrowDown") { e.preventDefault(); setActive((active + 1) % opts.length); }
            else if (e.key === "ArrowUp") { e.preventDefault(); setActive(active <= 0 ? opts.length - 1 : active - 1); }
            else if (e.key === "Enter" && active >= 0) { e.preventDefault(); window.location.href = opts[active].href; }
        });
        input.addEventListener("focus", function () {
            if (input.value.trim().length >= MIN && options().length) { list.hidden = false; input.setAttribute("aria-expanded", "true"); }
        });
        list.addEventListener("mousedown", function (e) { e.preventDefault(); });   /* giữ focus ở ô nhập khi bấm vào gợi ý */
        document.addEventListener("click", function (e) { if (!host.contains(e.target)) close(); });
    }

    function init() {
        var set = Array.prototype.slice.call(document.querySelectorAll("#tvHsearch input[name=keyword], input[data-gx-suggest]"));
        set.forEach(function (i, idx) { if (set.indexOf(i) === idx) attach(i); });
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
