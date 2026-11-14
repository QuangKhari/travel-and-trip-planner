/* Chuông thông báo của chuyến (TripWatch): cảnh báo mới / đã ổn do hệ thống tự theo dõi, bản tin theo mốc, các lần xếp lại lịch.
 * "Xem cách xử lý" mở La bàn ở thẻ Cảnh báo — mỗi cách sửa đã được kiểm chứng bằng TripHealth, lịch chỉ đổi khi người dùng bấm Áp dụng. */
(function () {
    "use strict";
    var root = document.getElementById("tpWatch");
    if (!root) return;
    var cfgEl = document.getElementById("tpData"), cfg = {};
    try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : "{}"); } catch (e) { cfg = {}; }
    var bell = document.getElementById("tpBell"), box = document.getElementById("tpBellBox"), list = document.getElementById("tpBellList");
    var badge = bell.querySelector(".tgw__n"), nowBtn = document.getElementById("tpWatchNow");
    var data = { events: [], unread: 0 };
    var hasAi = !!document.getElementById("aiCo");

    function req(u, method) {
        return fetch(u, { method: method || "GET", credentials: "same-origin", headers: { "Accept": "application/json", "X-CSRF-TOKEN": cfg.csrf, "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); });
    }
    function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }
    var ICON = { alert: "fa-exclamation-triangle", resolved: "fa-check-circle", digest: "fa-newspaper", replan: "fa-magic" };
    function ago(iso) {
        var m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
        if (m < 1) return "vừa xong";
        if (m < 60) return m + " phút trước";
        if (m < 1440) return Math.round(m / 60) + " giờ trước";
        return new Date(iso).toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit" });
    }
    function setBadge(n) {
        badge.hidden = !n;
        badge.textContent = n > 9 ? "9+" : String(n);
        bell.classList.toggle("has-new", !!n);
        bell.setAttribute("aria-label", n ? n + " thông báo mới của chuyến" : "Thông báo của chuyến");
    }
    function openLaBan(day) {
        box.hidden = true; bell.setAttribute("aria-expanded", "false");
        if (!(window.TpLaBan && window.TpLaBan.ask("alerts", day))) location.href = location.pathname + "?la-ban=alerts";
    }
    function render() {
        list.textContent = "";
        if (!data.events.length) {
            list.appendChild(el("li", "tgw__empty", "Chưa có thông báo. Khi chuyến còn dưới 16 ngày, Travela bắt đầu theo dõi dự báo theo giờ và cảnh báo điểm đến."));
            return;
        }
        data.events.forEach(function (e) {
            var li = el("li", "tgw__it is-" + e.tone + (e.unread ? " is-unread" : ""));
            var ic = el("i", "fas " + (ICON[e.kind] || "fa-info-circle")); ic.setAttribute("aria-hidden", "true");
            li.appendChild(ic);
            var m = el("div", "tgw__m");
            m.appendChild(el("b", null, e.title));
            if (e.text) m.appendChild(el("p", null, e.text));
            var meta = el("small", null, (e.day ? "Ngày " + e.day + " · " : "") + ago(e.at));
            m.appendChild(meta);
            if (e.kind === "alert" && (window.TpLaBan || hasAi)) {
                var b = el("button", "tp-mini tgw__go", "Xem cách xử lý"); b.type = "button";
                b.addEventListener("click", function () { openLaBan(null); });
                m.appendChild(b);
            }
            li.appendChild(m);
            list.appendChild(li);
        });
    }
    function load() {
        return req(root.dataset.feed).then(function (j) { data = j; setBadge(j.unread || 0); render(); if (window.TpPlanner && window.TpPlanner.refreshToday) window.TpPlanner.refreshToday(); }).catch(function () { /* im lặng: chuông không quan trọng bằng lịch */ });
    }
    bell.addEventListener("click", function () {
        var open = box.hidden;
        box.hidden = !open;
        bell.setAttribute("aria-expanded", open ? "true" : "false");
        if (open && data.unread) {
            req(root.dataset.seen, "POST").then(function () { data.unread = 0; setBadge(0); });
        }
    });
    document.addEventListener("click", function (e) { if (!box.hidden && !root.contains(e.target)) { box.hidden = true; bell.setAttribute("aria-expanded", "false"); } });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !box.hidden) { box.hidden = true; bell.setAttribute("aria-expanded", "false"); bell.focus(); } });
    nowBtn.addEventListener("click", function () {
        nowBtn.disabled = true;
        var old = nowBtn.innerHTML;
        nowBtn.textContent = "Đang kiểm tra…";
        req(root.dataset.check, "POST").then(function (j) {
            data = j; render(); setBadge(0);
            req(root.dataset.seen, "POST");
            var p = el("li", "tgw__note", j.message); list.insertBefore(p, list.firstChild);
        }).catch(function (j) {
            list.insertBefore(el("li", "tgw__note is-bad", (j && j.message) || "Chưa kiểm tra được, thử lại sau."), list.firstChild);
        }).then(function () { nowBtn.disabled = false; nowBtn.innerHTML = old; });
    });
    window.TpWatch = { reload: load };
    load();
    setInterval(function () { if (!document.hidden) load(); }, 5 * 60 * 1000);
})();

/* Chép đường dẫn lịch (.ics) tự cập nhật: dán vào Google Calendar → "Từ URL", hoặc Apple Calendar → "Đăng ký lịch". */
(function () {
    "use strict";
    var b = document.getElementById("tpIcsCopy");
    if (!b) return;
    b.addEventListener("click", function () {
        var url = b.dataset.feed, span = b.querySelector("span"), old = span.textContent;
        var done = function (ok) {
            span.textContent = ok ? "Đã chép. Dán vào Google Calendar → Thêm lịch → Từ URL" : url;
            setTimeout(function () { span.textContent = old; }, 6000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(function () { done(true); }, function () { done(false); });
        else done(false);
    });
})();
