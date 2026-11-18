/* Vòng đời chuyến trên trang sửa chuyến: GET {data-url}
 *  - trước / trong chuyến: điểm "mức sẵn sàng" + việc còn thiếu có hạn chót (bấm việc -> mở đúng tab / bảng Sức khỏe / Đặt chỗ);
 *  - sau chuyến: dự toán so với thực chi, nơi đã ghé / bỏ qua, nơi đã ghé chưa đánh giá.
 * Chỉ đọc, không đổi lịch. Lỗi tải thì ẩn khối (không chặn trình soạn). */
(function () {
    "use strict";
    var box = document.getElementById("tpReady");
    if (!box || !window.fetch) return;
    function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }
    function money(n) { return Math.round(n || 0).toLocaleString("vi-VN") + "đ"; }
    function dmy(s) { var p = (s || "").split("-"); return p.length === 3 ? p[2] + "/" + p[1] : s; }
    var GO = {
        health: function () { var b = document.getElementById("tpuxHealthBtn"); if (b) b.click(); },
        bookings: function () {   // khối "Đặt chỗ cho chuyến" nằm ở tab Thông tin & chia sẻ
            var t = document.getElementById("tpTabInfo"); if (t) t.click();
            var s = document.getElementById("tpBook"); if (s) setTimeout(function () { s.scrollIntoView({ block: "start", behavior: "smooth" }); }, 50);
        },
        cost: function () { var t = document.getElementById("tpTabCost"); if (t) t.click(); },
        info: function () { var t = document.getElementById("tpTabInfo"); if (t) t.click(); },
        plan: function () { var t = document.getElementById("tpTabPlan"); if (t) t.click(); },
    };
    var CAT = { food: "Ăn uống", stay: "Chỗ ở", move: "Di chuyển", ticket: "Vé, tham quan", shop: "Mua sắm", other: "Khác" };

    function readiness(r) {
        var x = r.readiness;
        var head = el("div", "tgr__head");
        var ring = el("div", "tgr__ring is-" + x.level);
        ring.style.setProperty("--p", x.score);
        ring.appendChild(el("b", null, String(x.score)));
        head.appendChild(ring);
        var t = el("div");
        t.appendChild(el("h2", null, r.phase === "during" ? "Chuyến đang diễn ra" : "Mức sẵn sàng của chuyến")).id = "tpReadyH";
        t.appendChild(el("p", "tp-muted tp-small", x.level === "ready" ? "Gần như mọi thứ đã sẵn sàng." : "Còn " + (x.total - x.done) + " việc nên làm trước khi đi."));
        head.appendChild(t);
        box.appendChild(head);
        var ul = el("ul", "tgr__list");
        x.tasks.forEach(function (k) {
            var li = el("li", "tgr__it" + (k.ok ? " is-ok" : "") + (k.overdue ? " is-late" : ""));
            var ic = el("i", "fas " + (k.ok ? "fa-check-circle" : "fa-circle")); ic.setAttribute("aria-hidden", "true");
            li.appendChild(ic);
            var m = el("div", "tgr__m");
            if (!k.ok && k.action && GO[k.action]) {
                var a = el("button", "tgr__go", k.title); a.type = "button"; a.addEventListener("click", GO[k.action]); m.appendChild(a);
            } else m.appendChild(el("span", null, k.title));
            if (k.detail) m.appendChild(el("small", null, k.detail));
            li.appendChild(m);
            if (k.due) li.appendChild(el("span", "tgr__due", (k.overdue ? "Quá hạn " : "Trước ") + dmy(k.due)));
            ul.appendChild(li);
        });
        box.appendChild(ul);
    }
    function recap(r) {
        var x = r.recap;
        box.appendChild(el("h2", null, "Tổng kết chuyến đi")).id = "tpReadyH";
        var g = el("div", "tgr__grid");
        [["Dự toán", money(x.planned)], ["Thực chi", money(x.actual)],
            ["Chênh", (x.diff > 0 ? "+" : x.diff < 0 ? "−" : "") + money(Math.abs(x.diff)) + (x.diffPct != null ? " (" + (x.diffPct > 0 ? "+" : "") + x.diffPct + "%)" : "")],
            ["Đã ghé / bỏ qua", x.visited + " / " + x.skipped + (x.unmarked ? " · " + x.unmarked + " chưa đánh dấu" : "")]].forEach(function (c) {
            var d = el("div", "tgr__stat"); d.appendChild(el("small", null, c[0])); d.appendChild(el("b", null, c[1])); g.appendChild(d);
        });
        box.appendChild(g);
        var cats = Object.keys(x.byCategory || {});
        if (cats.length) box.appendChild(el("p", "tp-small tp-muted", "Thực chi theo nhóm: " + cats.map(function (k) { return (CAT[k] || k) + " " + money(x.byCategory[k]); }).join(" · ")));
        else if (!x.actual) box.appendChild(el("p", "tp-small tp-muted", "Chưa ghi khoản chi nào ở tab Chi phí, nên chưa so được dự toán với thực chi."));
        if ((x.toReview || []).length) {
            var p = el("p", "tp-small");
            p.appendChild(document.createTextNode("Đánh giá nơi đã ghé để Travela chấm nơi chính xác hơn cho người sau: "));
            x.toReview.forEach(function (v, i) {
                if (i) p.appendChild(document.createTextNode(", "));
                var a = el("a", null, v.name); a.href = v.url; p.appendChild(a);
            });
            box.appendChild(p);
        }
    }
    fetch(box.dataset.url, { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (r) {
            box.textContent = "";
            if (r.recap) recap(r); else if (r.readiness) readiness(r); else return;
            box.hidden = false;
        }).catch(function () { box.hidden = true; });
})();
