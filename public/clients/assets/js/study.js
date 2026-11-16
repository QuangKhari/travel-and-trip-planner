/* Đợt F: thanh nhiệm vụ buổi thử.
 * Người thử bấm "Bắt đầu" -> làm trên Travela -> "Xong" (máy chủ kiểm tra dữ liệu thật) hoặc "Bỏ qua".
 * Đếm số lần bấm và số trang đã mở trong lúc làm nhiệm vụ (sessionStorage), gửi kèm khi kết thúc.
 * Không hiện đồng hồ để người thử không bị áp lực thời gian; thời gian do máy chủ tính. */
(function () {
    "use strict";
    var bar = document.getElementById("studyBar");
    if (!bar) return;
    var D = bar.dataset, KEY = "tvStudy", st = null, open = true;
    try { open = sessionStorage.getItem(KEY + ":open") !== "0"; } catch (e) {}

    function counters() { try { return JSON.parse(sessionStorage.getItem(KEY) || "null"); } catch (e) { return null; } }
    function saveCounters(c) { try { sessionStorage.setItem(KEY, JSON.stringify(c)); } catch (e) {} }
    var c = counters();
    if (c && c.task) { c.pages++; saveCounters(c); }
    document.addEventListener("click", function (e) {
        if (bar.contains(e.target)) return;
        var x = counters(); if (x && x.task) { x.clicks++; saveCounters(x); }
    }, true);

    function el(t, cls, txt) { var n = document.createElement(t); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }
    function btn(cls, txt, fn) { var b = el("button", "stb__b " + cls, txt); b.type = "button"; b.addEventListener("click", fn); return b; }
    function send(url, body) {
        return fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": D.csrf }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function render(msg, tone) {
        bar.textContent = "";
        if (!st) return;
        var head = el("div", "stb__h");
        var done = st.tasks.filter(function (t) { return t.status === "success" || t.status === "skip"; }).length;
        head.appendChild(el("b", null, "Buổi thử " + st.code.toUpperCase()));
        head.appendChild(el("span", "stb__p", done + "/" + st.tasks.length + " nhiệm vụ"));
        var tg = btn("stb__tg", open ? "Thu gọn" : "Mở", function () { open = !open; try { sessionStorage.setItem(KEY + ":open", open ? "1" : "0"); } catch (e) {} render(); });
        tg.setAttribute("aria-expanded", open ? "true" : "false");
        head.appendChild(tg);
        bar.appendChild(head);
        bar.classList.toggle("is-min", !open);
        if (!open) return;
        var body = el("div", "stb__body");
        if (!st.consent) {
            body.appendChild(el("p", null, "Trước khi bắt đầu, bạn đọc và xác nhận tham gia."));
            var a = el("a", "stb__b is-main", "Đọc và đồng ý"); a.href = D.consent; body.appendChild(a);
        } else if (st.current) {
            var cur = st.current;
            body.appendChild(el("p", "stb__t", "Nhiệm vụ " + cur.n + ": " + cur.title));
            body.appendChild(el("p", "stb__q", cur.prompt));
            var acts = el("div", "stb__a");
            if (cur.status === "todo") {
                acts.appendChild(btn("is-main", "Bắt đầu", function () {
                    saveCounters({ task: cur.key, clicks: 0, pages: 0 });
                    send(D.start, { task: cur.key }).then(function (j) { st = j.state || st; render(j.ok ? null : j.message, j.ok ? null : "bad"); });
                }));
            } else {
                acts.appendChild(btn("is-main", "Xong", function (e) { finish(cur.key, false, e.currentTarget); }));
                acts.appendChild(btn("", "Bỏ qua nhiệm vụ này", function (e) { if (confirm("Bỏ qua nhiệm vụ " + cur.n + "?")) finish(cur.key, true, e.currentTarget); }));
                if (cur.startedAt && Date.now() / 1000 - cur.startedAt > st.softLimit) body.appendChild(el("p", "stb__hint", "Đã khá lâu; nếu thấy bế tắc bạn có thể bỏ qua."));
            }
            body.appendChild(acts);
        } else if (st.needSus) {
            body.appendChild(el("p", null, "Bạn đã làm xong 5 nhiệm vụ. Còn một phiếu đánh giá ngắn (10 câu)."));
            var s = el("a", "stb__b is-main", "Mở phiếu đánh giá"); s.href = D.sus; body.appendChild(s);
        } else {
            body.appendChild(el("p", null, "Xong buổi thử. Cảm ơn bạn!"));
        }
        if (msg) { var m = el("p", "stb__msg" + (tone ? " is-" + tone : ""), msg); m.setAttribute("role", "status"); body.appendChild(m); }
        bar.appendChild(body);
    }

    function finish(task, giveUp, b) {
        b.disabled = true;
        var x = counters() || {};
        send(D.finish, { task: task, giveUp: giveUp, clicks: x.task === task ? x.clicks : null, pages: x.task === task ? x.pages : null }).then(function (j) {
            if (j.ok && (j.passed || giveUp)) saveCounters(null);
            st = j.state || st;
            render(j.message, j.ok && j.passed ? "ok" : (giveUp ? null : "bad"));
        }).catch(function () { b.disabled = false; render("Mất kết nối, bấm lại giúp.", "bad"); });
    }

    fetch(D.state, { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { st = j; render(); }).catch(function () {});
})();
