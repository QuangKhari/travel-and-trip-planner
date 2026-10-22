/* Khay Chuyến đi + đánh dấu (tài liệu mục 4.10, giai đoạn B6). JS thuần, nạp ở mọi trang (footer).
 *
 * Khay: khách chưa đăng nhập lưu trong localStorage ("travela:trip-items"); đã đăng nhập thì gộp lên máy chủ
 * (POST syncUrl) và lấy lại bản gộp. Không có localStorage (chế độ riêng tư) vẫn chạy trong bộ nhớ trang.
 * Nút khai báo bằng HTML, không cần viết JS riêng cho từng trang:
 *   <button data-trip-add data-type="destination|food|event" data-slug="" data-name="" data-url="">
 *   <button data-mark="saved|visited|tried|attended" data-ref-type="" data-slug="" aria-pressed="false">
 */
(function () {
    "use strict";
    var cfg = window.TRAVELA_TRIP || {};
    var KEY = "travela:trip-items";
    var TYPES = { destination: "Điểm đến", food: "Món ăn", event: "Lễ hội" };
    var items = {}; /* "type:slug" -> {type, slug, name, url} */
    var removed = {}; /* đã bỏ khi chưa kịp đồng bộ */
    var memoryOnly = false;
    var syncTimer = 0;
    var ui = null;

    function k(type, slug) {
        return type + ":" + slug;
    }
    function load() {
        try {
            var raw = localStorage.getItem(KEY);
            var d = raw ? JSON.parse(raw) : {};
            if (!d || typeof d !== "object") return;
            Object.keys(d).forEach(function (key) {
                var v = d[key];
                if (!v || typeof v.slug !== "string") return;
                var type = TYPES[v.type] ? v.type : "destination"; /* bản cũ chỉ lưu điểm đến */
                items[k(type, v.slug)] = { type: type, slug: v.slug, name: String(v.name || v.slug), url: String(v.url || "#") };
            });
        } catch (e) {
            memoryOnly = true;
        }
    }
    function save() {
        try {
            localStorage.setItem(KEY, JSON.stringify(items));
        } catch (e) {
            memoryOnly = true;
        }
    }
    function safeUrl(u) {
        return /^(\/|https?:\/\/)/.test(u) ? u : "#";
    }
    function count() {
        return Object.keys(items).length;
    }
    function list() {
        return Object.keys(items).map(function (x) {
            return items[x];
        });
    }

    function changed() {
        save();
        refresh();
        document.dispatchEvent(new CustomEvent("travela:trip-change", { detail: { count: count() } }));
        if (cfg.loggedIn) {
            clearTimeout(syncTimer);
            syncTimer = setTimeout(sync, 600);
        }
    }

    function add(it) {
        if (!it || !TYPES[it.type] || !it.slug) return false;
        var key = k(it.type, it.slug);
        if (items[key]) return false;
        items[key] = { type: it.type, slug: String(it.slug), name: String(it.name || it.slug), url: safeUrl(String(it.url || "#")) };
        delete removed[key];
        changed();
        document.dispatchEvent(new CustomEvent("travela:trip-add", { detail: items[key] }));
        return true;
    }
    function remove(type, slug) {
        var key = k(type, slug);
        if (!items[key]) return;
        delete items[key];
        removed[key] = { type: type, slug: slug };
        changed();
    }
    function has(type, slug) {
        return !!items[k(type, slug)];
    }
    function clear() {
        list().forEach(function (it) {
            removed[k(it.type, it.slug)] = { type: it.type, slug: it.slug };
        });
        items = {};
        changed();
    }

    /* ---- đồng bộ với máy chủ khi đã đăng nhập ---- */
    function post(url, body) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": cfg.csrf || "",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify(body),
        });
    }
    function sync() {
        if (!cfg.loggedIn || !cfg.syncUrl) return;
        var body = {
            items: list().map(function (x) {
                return { type: x.type, slug: x.slug };
            }),
            removed: Object.keys(removed).map(function (x) {
                return removed[x];
            }),
        };
        post(cfg.syncUrl, body)
            .then(function (r) {
                if (!r.ok) throw 0;
                return r.json();
            })
            .then(function (d) {
                removed = {};
                (d.items || []).forEach(function (it) {
                    var key = k(it.type, it.slug);
                    if (!items[key]) items[key] = { type: it.type, slug: it.slug, name: it.name, url: safeUrl(it.url) };
                });
                save();
                refresh();
                document.dispatchEvent(new CustomEvent("travela:trip-change", { detail: { count: count() } }));
            })
            .catch(function () {
                /* mất mạng: giữ nguyên bản trên trình duyệt, lần đổi sau sẽ thử lại */
            });
    }

    /* ---- nút khai báo bằng HTML ---- */
    function refreshButtons() {
        [].forEach.call(document.querySelectorAll("[data-trip-add]"), function (b) {
            var on = has(b.dataset.type, b.dataset.slug);
            b.setAttribute("aria-pressed", on ? "true" : "false");
            b.classList.toggle("is-on", on);
            var lab = b.querySelector("[data-label]") || b;
            lab.textContent = on ? b.dataset.labelOn || "✓ Đã thêm" : b.dataset.labelOff || "+ Thêm vào chuyến đi";
        });
    }

    /* ---- khay: nút nổi + tờ trượt ---- */
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function buildUi() {
        if (ui || !document.body) return;
        var fab = el("button", "gx-tray-fab");
        fab.type = "button";
        fab.setAttribute("aria-label", "Mở khay chuyến đi");
        fab.setAttribute("aria-expanded", "false");
        fab.appendChild(el("span", "gx-tray-ico", "🧳"));
        var badge = el("b", "gx-tray-count", "0");
        fab.appendChild(badge);

        var sheet = el("aside", "gx-tray");
        sheet.setAttribute("role", "dialog");
        sheet.setAttribute("aria-label", "Khay chuyến đi");
        sheet.hidden = true;
        var head = el("div", "gx-tray-head");
        head.appendChild(el("h2", null, "Chuyến đi của bạn"));
        var close = el("button", "gx-tray-x", "×");
        close.type = "button";
        close.setAttribute("aria-label", "Đóng khay");
        head.appendChild(close);
        var body = el("div", "gx-tray-body");
        var foot = el("div", "gx-tray-foot");
        sheet.appendChild(head);
        sheet.appendChild(body);
        sheet.appendChild(foot);
        document.body.appendChild(fab);
        document.body.appendChild(sheet);

        function open(v) {
            sheet.hidden = !v;
            fab.setAttribute("aria-expanded", v ? "true" : "false");
            if (v) close.focus();
            else fab.focus();
        }
        fab.addEventListener("click", function () {
            open(sheet.hidden);
        });
        close.addEventListener("click", function () {
            open(false);
        });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && !sheet.hidden) open(false);
        });
        ui = { fab: fab, badge: badge, body: body, foot: foot, sheet: sheet };
    }
    function renderTray() {
        if (!ui) return;
        var n = count();
        ui.badge.textContent = n;
        ui.fab.classList.toggle("has-items", n > 0);
        ui.body.textContent = "";
        if (!n) {
            ui.body.appendChild(el("p", "gx-tray-empty", "Chưa có gì. Bấm “Thêm vào chuyến đi” ở điểm đến, món ăn hay lễ hội bạn thích."));
        } else {
            var groups = {};
            list().forEach(function (it) {
                (groups[it.type] = groups[it.type] || []).push(it);
            });
            Object.keys(TYPES).forEach(function (t) {
                if (!groups[t]) return;
                ui.body.appendChild(el("h3", null, TYPES[t]));
                var ul = el("ul", "gx-tray-list");
                groups[t].forEach(function (it) {
                    var li = el("li");
                    var a = el("a", null, it.name);
                    a.href = safeUrl(it.url);
                    var rm = el("button", "gx-tray-rm", "Bỏ");
                    rm.type = "button";
                    rm.setAttribute("aria-label", "Bỏ " + it.name + " khỏi chuyến đi");
                    rm.addEventListener("click", function () {
                        remove(it.type, it.slug);
                    });
                    li.appendChild(a);
                    li.appendChild(rm);
                    ul.appendChild(li);
                });
                ui.body.appendChild(ul);
            });
        }
        ui.foot.textContent = "";
        if (cfg.plannerUrl && n) {
            var go = el("a", "gx-btn", "Lên kế hoạch với các điểm này");
            go.href = cfg.plannerUrl + "?diem=" + encodeURIComponent(list().map(function (x) { return x.type + ":" + x.slug; }).join(","));
            ui.foot.appendChild(go);
        } else if (!cfg.plannerUrl) {
            ui.foot.appendChild(el("p", "gx-tray-note", "Trình lên kế hoạch sắp ra mắt. Các điểm bạn chọn được giữ lại để dùng khi đó."));
        }
        if (!cfg.loggedIn && n) {
            var p = el("p", "gx-tray-note", memoryOnly ? "Trình duyệt đang chặn lưu trữ: khay sẽ mất khi tải lại trang. " : "Đang lưu trên trình duyệt này. ");
            var l = el("a", null, "Đăng nhập");
            l.href = cfg.loginUrl || "/login";
            p.appendChild(l);
            p.appendChild(document.createTextNode(" để giữ khay trên mọi thiết bị."));
            ui.foot.appendChild(p);
        }
    }
    function refresh() {
        refreshButtons();
        renderTray();
    }

    /* ---- đánh dấu (cần đăng nhập) ---- */
    function toggleMark(btn) {
        if (!cfg.loggedIn) {
            location.href = cfg.loginUrl || "/login";
            return;
        }
        if (btn.disabled) return;
        btn.disabled = true;
        post(cfg.markUrl, { type: btn.dataset.mark, refType: btn.dataset.refType, slug: btn.dataset.slug })
            .then(function (r) {
                if (r.status === 401) {
                    location.href = cfg.loginUrl || "/login";
                    throw 0;
                }
                if (!r.ok) throw 0;
                return r.json();
            })
            .then(function (d) {
                btn.setAttribute("aria-pressed", d.marked ? "true" : "false");
                btn.classList.toggle("is-on", !!d.marked);
                var lab = btn.querySelector("[data-label]") || btn;
                lab.textContent = d.marked ? btn.dataset.labelOn : btn.dataset.labelOff;
            })
            .catch(function () {})
            .then(function () {
                btn.disabled = false;
            });
    }

    document.addEventListener("click", function (e) {
        var t = e.target.closest ? e.target.closest("[data-trip-add],[data-mark]") : null;
        if (!t) return;
        if (t.hasAttribute("data-trip-add")) {
            e.preventDefault();
            if (has(t.dataset.type, t.dataset.slug)) remove(t.dataset.type, t.dataset.slug);
            else add({ type: t.dataset.type, slug: t.dataset.slug, name: t.dataset.name, url: t.dataset.url });
        } else {
            e.preventDefault();
            toggleMark(t);
        }
    });

    window.TravelaTrip = { add: add, remove: remove, has: has, count: count, list: list, clear: clear };
    load();
    function boot() {
        buildUi();
        refresh();
        if (cfg.loggedIn) sync();
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
    else boot();
})();
