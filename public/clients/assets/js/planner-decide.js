/*
 * "Cùng quyết định" (đi nhóm, học Troupe): bỏ phiếu chọn ngày đi / điểm đến / chỗ ở TRƯỚC khi có lịch, và ai đi / chưa quyết / không đi.
 * Vẽ từ GET data-url (TripDecideController::show); mọi thao tác trả lại toàn bộ trạng thái nên chỉ cần vẽ lại.
 * Chốt một câu hỏi có áp dụng vào chuyến (ngày đi, điểm đến, số người) thì tải lại trang để lịch dùng thông tin mới.
 */
(function () {
    "use strict";
    var box = document.getElementById("tpDecide");
    if (!box) return;
    var url = box.getAttribute("data-url"), rsvpUrl = box.getAttribute("data-rsvp"), groupUrl = box.getAttribute("data-group"), me = +box.getAttribute("data-me");
    var csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";
    var body = box.querySelector(".tpd__body"), msg = box.querySelector(".tpd__msg");
    var KINDS = { date: "Ngày đi", destination: "Điểm đến", stay: "Chỗ ở", other: "Khác" };
    var RSVP = [["going", "Đi"], ["maybe", "Chưa quyết"], ["no", "Không đi"]];
    var state = null, loaded = false;

    function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
    function say(t, bad) { msg.textContent = t || ""; msg.classList.toggle("is-bad", !!bad); }
    function call(method, u, data) {
        return fetch(u, { method: method, credentials: "same-origin", headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" },
            body: method === "GET" ? undefined : JSON.stringify(data || {}) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok && !j.message) j.message = r.status === 403 ? "Bạn không có quyền làm việc này." : "Có lỗi, thử lại nhé."; j.ok = r.ok && j.ok !== false; return j; }); });
    }
    function apply(j) {
        if (!j.ok) { say(j.message || (j.errors ? Object.values(j.errors)[0][0] : "Không thực hiện được."), true); return; }
        state = j; render(); say(j.said || "");
        if (j.reload) setTimeout(function () { location.reload(); }, 900);
    }
    function load() { call("GET", url).then(apply).catch(function () { say("Chưa tải được phần bình chọn.", true); }); }
    function destOptions(sel) {
        var src = document.getElementById("tpDest");
        if (!src) return;
        Array.prototype.forEach.call(src.options, function (o) { if (o.value) { var x = el("option", null, o.textContent); x.value = o.value; sel.appendChild(x); } });
    }
    /** Ô thêm lựa chọn theo loại câu hỏi: ngày (lịch), điểm đến (danh sách), chỗ ở (tên + link), khác (chữ). */
    function optionInput(kind) {
        var f = el("form", "tpd__add"), a, b = null;
        if (kind === "date") { a = el("input"); a.type = "date"; a.min = new Date().toISOString().slice(0, 10); a.required = true; a.setAttribute("aria-label", "Ngày đề xuất"); }
        else if (kind === "destination") { a = el("select"); a.required = true; a.setAttribute("aria-label", "Điểm đến đề xuất"); var o0 = el("option", null, "Chọn điểm đến…"); o0.value = ""; a.appendChild(o0); destOptions(a); }
        else {
            a = el("input"); a.maxLength = 160; a.required = true; a.placeholder = kind === "stay" ? "Tên chỗ ở" : "Lựa chọn"; a.setAttribute("aria-label", a.placeholder);
            if (kind === "stay") { b = el("input"); b.type = "url"; b.maxLength = 255; b.placeholder = "Link (không bắt buộc)"; b.setAttribute("aria-label", "Link chỗ ở"); }
        }
        f.appendChild(a); if (b) f.appendChild(b);
        var s = el("button", "tp-mini", "Thêm lựa chọn"); s.type = "submit"; f.appendChild(s);
        f.value = function () {
            if (kind === "date" || kind === "destination") return { label: "", value: a.value };
            return { label: a.value, value: b ? b.value : null };
        };
        return f;
    }
    function render() {
        body.textContent = "";
        // Ai đi
        var p = state.people, ppl = el("div", "tpd__ppl");
        ppl.appendChild(el("h3", null, "Ai đi?"));
        var ul = el("ul", "tpd__who");
        p.list.forEach(function (x) {
            var li = el("li", "tpd__p is-" + x.rsvp);
            li.appendChild(el("span", "tpd__av", (x.name || "?").trim().charAt(0).toUpperCase()));
            li.appendChild(el("span", null, x.name + (x.isOwner ? " (chủ chuyến)" : "")));
            li.appendChild(el("small", null, RSVP.filter(function (r) { return r[0] === x.rsvp; })[0][1]));
            ul.appendChild(li);
        });
        ppl.appendChild(ul);
        var mine = p.list.filter(function (x) { return x.userId === me; })[0];
        if (mine && !mine.isOwner) {
            var seg = el("div", "tpd__seg"); seg.setAttribute("role", "group"); seg.setAttribute("aria-label", "Bạn có đi không");
            RSVP.forEach(function (r) {
                var b = el("button", "tp-mini" + (mine.rsvp === r[0] ? " is-on" : ""), r[1]); b.type = "button"; b.setAttribute("aria-pressed", mine.rsvp === r[0] ? "true" : "false");
                b.onclick = function () { call("POST", rsvpUrl, { rsvp: r[0] }).then(apply); };
                seg.appendChild(b);
            });
            ppl.appendChild(seg);
        }
        var sum = el("p", "tpd__sum", p.going + " đi · " + p.maybe + " chưa quyết · " + p.no + " không đi" + (p.guests ? " · " + p.guests + " khách không có tài khoản" : "") + ". Số người của chuyến: " + p.groupSize + ".");
        var want = Math.max(1, p.going + p.guests);
        if (state.canCreate && want !== p.groupSize) {
            var g = el("button", "tp-mini", "Đặt số người = " + want); g.type = "button";
            g.title = "Chi phí, vé và phòng tính theo số người của chuyến";
            g.onclick = function () { call("POST", groupUrl).then(apply); };
            sum.appendChild(document.createTextNode(" ")); sum.appendChild(g);
        }
        ppl.appendChild(sum);
        if (p.no) ppl.appendChild(el("p", "tp-muted tp-small", "Người báo “Không đi” không được chia các khoản chi mới."));
        body.appendChild(ppl);

        // Câu hỏi
        var ph = el("div", "tpd__polls");
        ph.appendChild(el("h3", null, "Bỏ phiếu"));
        if (!state.polls.length) ph.appendChild(el("p", "tp-muted", state.canCreate ? "Chưa có câu hỏi nào. Tạo câu hỏi chọn ngày đi, điểm đến hoặc chỗ ở, mọi người cùng thêm lựa chọn và bỏ phiếu." : "Chưa có câu hỏi nào."));
        state.polls.forEach(function (q) {
            var c = el("article", "tpd__q" + (q.open ? "" : " is-closed"));
            var h = el("header");
            h.appendChild(el("span", "tpd__kind", q.kindLabel));
            h.appendChild(el("b", null, q.question));
            h.appendChild(el("small", null, q.open ? q.voters + "/" + q.members + " người đã bỏ phiếu" : "Đã chốt"));
            c.appendChild(h);
            var ol = el("ul", "tpd__opts");
            var max = Math.max(1, Math.max.apply(null, q.options.map(function (o) { return o.votes; }).concat([0])));
            q.options.forEach(function (o) {
                var li = el("li", "tpd__opt" + (o.chosen ? " is-chosen" : "") + (q.myVote === o.id ? " is-mine" : ""));
                var b = el("button", "tpd__vote"); b.type = "button"; b.disabled = !q.open;
                b.setAttribute("aria-pressed", q.myVote === o.id ? "true" : "false");
                b.setAttribute("aria-label", (q.myVote === o.id ? "Bỏ phiếu đã chọn: " : "Bỏ phiếu cho ") + o.label);
                var bar = el("span", "tpd__bar"); bar.style.width = Math.round(o.votes / max * 100) + "%"; b.appendChild(bar);
                b.appendChild(el("span", "tpd__lbl", (o.chosen ? "✓ " : "") + o.label));
                b.appendChild(el("span", "tpd__n", o.votes + " phiếu"));
                b.onclick = function () { call("POST", url + "/" + q.id + "/bo-phieu", { optionId: q.myVote === o.id ? null : o.id }).then(apply); };
                li.appendChild(b);
                var meta = [];
                if (o.who.length) meta.push(o.who.join(", "));
                if (o.by) meta.push("đề xuất: " + o.by);
                if (meta.length) li.appendChild(el("small", null, meta.join(" · ")));
                if (o.value && /^https?:\/\//.test(o.value)) { var a = el("a", null, "Xem chỗ ở"); a.href = o.value; a.target = "_blank"; a.rel = "noopener nofollow"; li.appendChild(a); }
                if (q.open && q.canClose) { var k = el("button", "tp-mini", "Chốt cái này"); k.type = "button"; k.onclick = function () { if (confirm("Chốt “" + o.label + "”? " + (q.kind === "date" ? "Ngày khởi hành của chuyến sẽ đổi theo." : q.kind === "destination" ? "Điểm đến của chuyến sẽ đổi theo." : ""))) call("POST", url + "/" + q.id + "/chot", { optionId: o.id }).then(apply); }; li.appendChild(k); }
                ol.appendChild(li);
            });
            c.appendChild(ol);
            if (q.open) {
                var f = optionInput(q.kind);
                f.addEventListener("submit", function (e) { e.preventDefault(); call("POST", url + "/" + q.id + "/lua-chon", f.value()).then(apply); });
                c.appendChild(f);
                if (q.canClose) {
                    var act = el("div", "tpd__act");
                    var cl = el("button", "tp-mini", "Chốt theo số phiếu"); cl.type = "button"; cl.onclick = function () { call("POST", url + "/" + q.id + "/chot", {}).then(apply); };
                    var dl = el("button", "tp-mini tp-mini--ghost", "Xóa câu hỏi"); dl.type = "button"; dl.onclick = function () { if (confirm("Xóa câu hỏi và mọi phiếu?")) call("POST", url + "/" + q.id + "/xoa").then(apply); };
                    act.appendChild(cl); act.appendChild(dl); c.appendChild(act);
                }
            }
            ph.appendChild(c);
        });
        if (state.canCreate) {
            var cf = el("form", "tpd__new");
            var ks = el("select"); ks.setAttribute("aria-label", "Loại câu hỏi");
            Object.keys(KINDS).forEach(function (k) { var o = el("option", null, KINDS[k]); o.value = k; ks.appendChild(o); });
            var qi = el("input"); qi.maxLength = 160; qi.placeholder = "Câu hỏi (để trống: “Mình đi ngày nào?”…)"; qi.setAttribute("aria-label", "Câu hỏi");
            var sb = el("button", "tp-btn", "Tạo câu hỏi"); sb.type = "submit";
            cf.appendChild(ks); cf.appendChild(qi); cf.appendChild(sb);
            cf.addEventListener("submit", function (e) { e.preventDefault(); call("POST", url, { kind: ks.value, question: qi.value, options: [] }).then(apply); });
            ph.appendChild(cf);
        }
        body.appendChild(ph);
    }
    // tải khi mở tab "Thông tin & chia sẻ" (hoặc ngay nếu đang mở)
    function maybeLoad() { if (!loaded && !box.closest("[hidden]")) { loaded = true; load(); } }
    var panel = box.closest(".tp-panel");
    if (panel && window.MutationObserver) new MutationObserver(maybeLoad).observe(panel, { attributes: true, attributeFilter: ["hidden"] });
    maybeLoad();
    // người khác vừa sửa chuyến (cộng tác gần thời gian thực): làm mới số phiếu
    document.addEventListener("tp:remote", function () { if (loaded) load(); });
    setInterval(function () { if (loaded && !document.hidden && !box.closest("[hidden]")) load(); }, 20000);
})();
