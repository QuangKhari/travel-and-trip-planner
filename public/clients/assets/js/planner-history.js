/* Lịch sử phiên bản chuyến đi (tbl_trip_revisions) trong trang sửa chuyến.
 *   GET  cfg.historyUrl                       -> {rev, revisions:[{rev,type,by,items,at}]}
 *   GET  cfg.historyUrl + "/{rev}"            -> {days:[{day,items[]}], diff:{add,remove,moved,meta,same}, ...}
 *   POST cfg.historyUrl + "/{rev}/khoi-phuc"  {rev: bản đang mở} -> {ok, rev} | 409 REVISION_CONFLICT | 423 đang có người sửa
 * Khôi phục tạo phiên bản MỚI (không xóa lịch sử) rồi tải lại trang; còn thay đổi chưa lưu thì lưu xong mới cho khôi phục.
 * Trạng thái trình soạn đọc qua window.TpPlanner (planner.js).
 */
(function () {
    "use strict";
    var dataEl = document.getElementById("tpData"), dlg = document.getElementById("tpHist");
    if (!dataEl || !dlg) return;
    var cfg;
    try { cfg = JSON.parse(dataEl.textContent); } catch (e) { return; }
    if (!cfg.historyUrl) return;

    var list = document.getElementById("tpHistList"), view = document.getElementById("tpHistView");
    var RO = cfg.role === "view", NOTE = "tpHistNote" + (cfg.trip && cfg.trip.id);
    var TYPE = { save: "Lưu", restore: "Khôi phục", external: "Chuyến bay / khách sạn" };
    var state = { cur: null, sel: null, items: [], lastFocus: null, busy: false };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function icon(n) { var i = el("i", "fas " + n); i.setAttribute("aria-hidden", "true"); return i; }
    function getJson(url) {
        return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
    }
    /** "14:05 hôm nay", "09:12 hôm qua", "08/11 21:30" */
    function when(s) {
        var d = new Date(String(s).replace(" ", "T"));
        if (isNaN(d)) return s;
        var p = function (n) { return (n < 10 ? "0" : "") + n; }, hm = p(d.getHours()) + ":" + p(d.getMinutes());
        var t0 = new Date(); t0.setHours(0, 0, 0, 0);
        var diff = Math.round((t0 - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 864e5);
        return diff === 0 ? hm + " hôm nay" : diff === 1 ? hm + " hôm qua" : p(d.getDate()) + "/" + p(d.getMonth() + 1) + " " + hm;
    }
    function planner() { return window.TpPlanner ? window.TpPlanner.state() : { rev: cfg.rev, dirty: false }; }

    /* ---------------- danh sách ---------------- */
    function load(selectRev) {
        list.textContent = "";
        list.appendChild(el("li", "tph__msg", "Đang tải…"));
        getJson(cfg.historyUrl).then(function (j) {
            state.cur = j.rev;
            state.items = j.revisions || [];
            renderList();
            var first = state.items.filter(function (x) { return x.rev === selectRev; })[0] || state.items[1] || state.items[0];
            if (first) select(first.rev, false); else emptyView();
        }).catch(function () {
            list.textContent = "";
            list.appendChild(el("li", "tph__msg is-bad", "Chưa tải được lịch sử. Kiểm tra kết nối rồi mở lại."));
            view.textContent = "";
        });
    }
    function renderList() {
        list.textContent = "";
        if (!state.items.length) { list.appendChild(el("li", "tph__msg", "Chưa có phiên bản nào. Lịch sử bắt đầu từ lần lưu kế tiếp.")); return; }
        state.items.forEach(function (x) {
            var li = el("li");
            var b = el("button", "tph__item" + (x.rev === state.sel ? " is-on" : "") + (x.rev === state.cur ? " is-cur" : ""));
            b.type = "button";
            b.setAttribute("aria-pressed", x.rev === state.sel ? "true" : "false");
            b.dataset.rev = x.rev;
            var top = el("span", "tph__item-top");
            top.appendChild(el("b", null, "Bản " + x.rev));
            if (x.rev === state.cur) top.appendChild(el("span", "tph__tag is-cur", "Hiện tại"));
            else if (x.type !== "save") top.appendChild(el("span", "tph__tag" + (x.type === "restore" ? " is-restore" : ""), TYPE[x.type] || x.type));
            b.appendChild(top);
            b.appendChild(el("span", "tph__item-meta", when(x.at) + (x.by ? " · " + x.by : "")));
            b.appendChild(el("span", "tph__item-meta", x.items + " mục"));
            b.addEventListener("click", function () { select(x.rev, true); });
            li.appendChild(b);
            list.appendChild(li);
        });
    }
    function emptyView() {
        view.textContent = "";
        view.appendChild(el("p", "tp-muted", "Chọn một phiên bản để xem."));
    }

    /* ---------------- xem trước một bản ---------------- */
    function select(rev, focusView) {
        state.sel = rev;
        renderList();
        view.textContent = "";
        view.appendChild(el("p", "tp-muted", "Đang tải bản " + rev + "…"));
        getJson(cfg.historyUrl + "/" + rev).then(function (j) {
            if (state.sel !== rev) return;
            renderView(j);
            if (focusView) { var h = view.querySelector("h3"); if (h) h.focus(); }
        }).catch(function () {
            if (state.sel !== rev) return;
            view.textContent = "";
            view.appendChild(el("p", "tph__msg is-bad", "Không tải được bản này."));
        });
    }
    function bullets(title, arr, cls, ic) {
        var box = el("div", "tph__diff " + cls);
        var h = el("p", "tph__diff-h");
        h.appendChild(icon(ic));
        h.appendChild(el("span", null, title));
        box.appendChild(h);
        var ul = el("ul");
        var shown = arr.slice(0, 8);
        shown.forEach(function (t) { ul.appendChild(el("li", null, t)); });
        if (arr.length > shown.length) ul.appendChild(el("li", "tp-muted", "và " + (arr.length - shown.length) + " mục khác"));
        box.appendChild(ul);
        return box;
    }
    function renderView(j) {
        view.textContent = "";
        var head = el("div", "tph__vhead");
        var h = el("h3", null, "Bản " + j.rev + (j.trip && j.trip.title ? " · " + j.trip.title : ""));
        h.tabIndex = -1;
        head.appendChild(h);
        head.appendChild(el("p", "tp-muted tp-small", (TYPE[j.type] || j.type) + " lúc " + when(j.at) + (j.by ? " bởi " + j.by : "")));
        view.appendChild(head);

        var isCur = j.rev === state.cur;
        // điều sẽ xảy ra nếu khôi phục
        var d = j.diff || {};
        if (isCur) view.appendChild(el("p", "tph__note", "Đây là phiên bản đang dùng."));
        else if (d.same) view.appendChild(el("p", "tph__note", "Nội dung giống hệt bản hiện tại."));
        else {
            var wrap = el("div", "tph__diffs");
            wrap.appendChild(el("p", "tph__diffs-h", "Nếu khôi phục bản này:"));
            if ((d.meta || []).length) wrap.appendChild(bullets("Thông tin chuyến đổi", d.meta, "is-meta", "fa-sliders-h"));
            if ((d.add || []).length) wrap.appendChild(bullets("Thêm lại " + d.add.length + " mục", d.add, "is-add", "fa-plus-circle"));
            if ((d.remove || []).length) wrap.appendChild(bullets("Bỏ " + d.remove.length + " mục đang có", d.remove, "is-rm", "fa-minus-circle"));
            if (d.moved) wrap.appendChild(el("p", "tph__diff is-move", d.moved + " mục được xếp lại ngày như bản này."));
            view.appendChild(wrap);
        }

        // nội dung theo ngày
        var days = el("div", "tph__days");
        (j.days || []).forEach(function (g) {
            var sec = el("div", "tph__day");
            sec.appendChild(el("b", null, g.day == null ? "Đã lưu (chưa xếp ngày)" : "Ngày " + g.day));
            var ol = el("ol");
            g.items.forEach(function (n) { ol.appendChild(el("li", null, n)); });
            sec.appendChild(ol);
            days.appendChild(sec);
        });
        if (!(j.days || []).length) days.appendChild(el("p", "tp-muted tp-small", "Bản này chưa có mục nào."));
        view.appendChild(days);

        if (RO || isCur || d.same) return;
        var act = el("div", "tph__act");
        var msg = el("p", "tph__msg", "");
        msg.setAttribute("role", "status");
        var btn = el("button", "tp-btn tph__restore");
        btn.type = "button";
        btn.appendChild(icon("fa-undo"));
        btn.appendChild(document.createTextNode(" Khôi phục bản " + j.rev));
        btn.addEventListener("click", function () { restore(j.rev, btn, msg); });
        act.appendChild(btn);
        act.appendChild(msg);
        view.appendChild(act);
    }

    /* ---------------- khôi phục ---------------- */
    function restore(rev, btn, msg) {
        if (state.busy) return;
        var st = planner();
        if (st.dirty) {
            msg.className = "tph__msg is-warn";
            msg.textContent = "Bạn còn thay đổi chưa lưu. Đang lưu, bấm lại sau giây lát.";
            if (window.TpPlanner) window.TpPlanner.saveNow();
            return;
        }
        if (!window.confirm("Khôi phục bản " + rev + "? Lịch hiện tại được giữ lại trong lịch sử, bạn có thể quay lại bất cứ lúc nào.")) return;
        state.busy = true;
        btn.disabled = true;
        msg.className = "tph__msg";
        msg.textContent = "Đang khôi phục…";
        fetch(cfg.historyUrl + "/" + rev + "/khoi-phuc", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ rev: st.rev }),
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { s: r.status, j: j }; }); })
            .then(function (res) {
                state.busy = false;
                if (res.s === 200 && res.j.ok) {
                    try { sessionStorage.setItem(NOTE, JSON.stringify({ from: st.rev, to: rev, rev: res.j.rev })); } catch (e) { /* bỏ qua */ }
                    msg.textContent = "Đã khôi phục. Đang tải lại…";
                    location.reload();
                    return;
                }
                btn.disabled = false;
                msg.className = "tph__msg is-bad";
                if (res.s === 409) { msg.textContent = (res.j.by || "Người khác") + " vừa lưu chuyến đi. Hãy tải lại trang rồi thử lại."; load(rev); }
                else if (res.s === 423) msg.textContent = res.j.message || "Có người đang sửa chuyến đi này.";
                else if (res.s === 403) msg.textContent = "Bạn chỉ có quyền xem chuyến đi này.";
                else msg.textContent = res.j.message || "Chưa khôi phục được, thử lại sau.";
            })
            .catch(function () { state.busy = false; btn.disabled = false; msg.className = "tph__msg is-bad"; msg.textContent = "Mất kết nối, chưa khôi phục."; });
    }

    /* ---------------- mở / đóng ---------------- */
    function open(rev) {
        state.lastFocus = document.activeElement;
        dlg.hidden = false;
        document.getElementById("tpHistClose").focus();
        load(rev || null);
    }
    function close() {
        dlg.hidden = true;
        if (state.lastFocus && state.lastFocus.focus) state.lastFocus.focus();
    }
    var opener = document.getElementById("tpHistOpen");
    if (opener) opener.addEventListener("click", function () { open(null); });
    document.getElementById("tpHistClose").addEventListener("click", close);
    dlg.addEventListener("click", function (e) { if (e.target === dlg) close(); });
    dlg.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { e.stopPropagation(); close(); return; }
        // phím lên / xuống chuyển giữa các phiên bản
        if ((e.key === "ArrowDown" || e.key === "ArrowUp") && e.target.classList && e.target.classList.contains("tph__item")) {
            e.preventDefault();
            var bs = Array.prototype.slice.call(list.querySelectorAll(".tph__item")), i = bs.indexOf(e.target);
            var n = bs[i + (e.key === "ArrowDown" ? 1 : -1)];
            if (n) { n.focus(); select(+n.dataset.rev, false); }
        }
        // giữ focus trong hộp thoại
        if (e.key === "Tab") {
            var f = dlg.querySelectorAll("button:not([disabled]), [tabindex='0']");
            if (!f.length) return;
            if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
    });

    // sau khi khôi phục và tải lại: báo kết quả, kèm nút quay lại bản trước đó
    var note = null;
    try { note = JSON.parse(sessionStorage.getItem(NOTE) || "null"); sessionStorage.removeItem(NOTE); } catch (e) { note = null; }
    var bar = document.getElementById("tpSync");
    if (note && bar) {
        bar.textContent = "";
        bar.appendChild(icon("fa-history"));
        bar.appendChild(el("span", null, " Đã khôi phục nội dung bản " + note.to + " thành phiên bản mới (bản " + note.rev + "). "));
        var back = el("button", "tp-mini", "Quay lại bản " + note.from);
        back.type = "button";
        back.addEventListener("click", function () { open(note.from); });
        bar.appendChild(back);
        bar.hidden = false;
    }
})();
