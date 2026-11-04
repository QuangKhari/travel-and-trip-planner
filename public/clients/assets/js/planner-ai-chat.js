/* "Vạch hải trình": trò chuyện tạo chuyến + hải đồ. Mọi chữ động gán bằng textContent (không innerHTML với dữ liệu từ máy chủ / người dùng; innerHTML chỉ dùng cho SVG tĩnh).
 * Mỗi câu người dùng gắn nhãn ô đang hỏi (entries) để "Đổi" một ô mà không mất ô khác. Hội thoại lưu sessionStorage 2 giờ. */
(function () {
    "use strict";
    var root = document.getElementById("paiRoot");
    if (!root) return;
    var cfg = {};
    try { cfg = JSON.parse(document.getElementById("paiData").textContent) || {}; } catch (e) { return; }
    var $ = function (id) { return document.getElementById(id); };
    var KEY = "pai.v3";
    var SLOTS = [["to", "Đi đâu"], ["from", "Xuất phát từ"], ["who", "Đi cùng ai"], ["when", "Khi nào"], ["like", "Bạn thích gì"]];
    var RECAP = [{ label: "Vạch hải trình", text: "__make", primary: true }, { label: "Đổi điểm đến", text: "__change:to" }, { label: "Đổi ngày", text: "__change:when" }, { label: "Đổi người đi", text: "__change:who" }];
    var NS = "http://www.w3.org/2000/svg";
    var S = { entries: [], from: null, asked: null, log: [], slots: {}, extras: [], geo: {}, busy: false, ang: -24, scan: false, courseKey: "", adjustments: {}, reviewSignature: "" };

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function post(url, body) {
        var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, 60000) : null;
        return fetch(url, { method: "POST", credentials: "same-origin", signal: ctl ? ctl.signal : undefined,
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify(body) }).then(function (r) { clearTimeout(t); return r.ok ? r.json() : Promise.reject(r.status); }, function (e) { clearTimeout(t); return Promise.reject(e); });
    }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify({ t: Date.now(), entries: S.entries, from: S.from, asked: S.asked, log: S.log.slice(-60), adjustments: S.adjustments })); } catch (e) {} }
    function load() { try { var j = JSON.parse(sessionStorage.getItem(KEY) || "null"); if (j && Date.now() - j.t < 72e5 && j.entries && j.log && j.log.length) return j; } catch (e) {} return null; }
    function wipe() { try { sessionStorage.removeItem(KEY); } catch (e) {} }

    /* ---------- nhật ký (trái) ---------- */
    function scroll() { var m = $("paiMsgs"); m.scrollTop = m.scrollHeight; }
    function progress() {
        var known = SLOTS.filter(function (x) { return !!S.slots[x[0]]; }).length;
        var fill = $("paiProgressFill"), label = $("paiProgressLabel");
        if (fill) fill.style.width = Math.round(known / SLOTS.length * 100) + "%";
        if (label) label.textContent = known + "/" + SLOTS.length + " mục đã ghi nhận" + (known === SLOTS.length ? " · Có thể xem lại chuyến" : "");
    }
    function setBusy(on) {
        S.busy = on;
        var send = $("paiSend");
        if (send) { send.disabled = on; send.setAttribute("aria-busy", on ? "true" : "false"); }
        $("paiRoot").classList.toggle("pai-is-busy", on);
    }
    function networkStatus() {
        var banner = $("paiNetwork");
        if (banner) banner.hidden = navigator.onLine !== false;
    }
    window.addEventListener('online', networkStatus);
    window.addEventListener('offline', networkStatus);
    networkStatus();
    function msg(who, text, skipLog) {
        var d = el("div", "pai-e " + who, text); $("paiMsgs").appendChild(d); scroll();
        if (!skipLog) { S.log.push({ w: who, t: text }); save(); }
        return d;
    }
    function typing() { var d = el("div", "pai-e ai pai-dots"); for (var i = 0; i < 3; i++) d.appendChild(el("i")); $("paiMsgs").appendChild(d); scroll(); return d; }
    function chips(list) {
        var box = $("paiChips"); box.textContent = "";
        (list || []).forEach(function (c) {
            var b = el("button", c.primary ? "go" : (c.text === "__suggest" ? "sand" : ""), c.label); b.type = "button";
            b.onclick = function () { pick(c); }; box.appendChild(b);
        });
    }
    function pick(c) {
        if (c.text === "__make") make(); else if (c.text === "__retry") { setBusy(true); read(); }
        else if (c.text === "__suggest") suggest();
        else if (c.text.indexOf("__change:") === 0) change(c.text.slice(9)); else turn(c.text, c.label);
    }
    function leaders(ul, cls) {
        SLOTS.forEach(function (s) { var li = el("li", cls); li.appendChild(el("span", null, s[1])); li.appendChild(el("b", null, S.slots[s[0]] || "")); ul.appendChild(li); });
    }

    /* ---------- hải đồ (phải): dựng một lần, cập nhật theo S ---------- */
    function buildPlate() {
        var p = $("paiPanel"); p.textContent = "";
        var ticks = ""; for (var i = 0; i < 72; i++) { var a = i * 5 * Math.PI / 180, L = i % 18 === 0 ? 14 : i % 6 === 0 ? 10 : 5;
            ticks += '<line class="pai-tk" x1="' + (200 + Math.sin(a) * 112).toFixed(1) + '" y1="' + (200 - Math.cos(a) * 112).toFixed(1) + '" x2="' + (200 + Math.sin(a) * (112 + L)).toFixed(1) + '" y2="' + (200 - Math.cos(a) * (112 + L)).toFixed(1) + '"/>'; }
        var svg = document.createElementNS(NS, "svg"); svg.setAttribute("viewBox", "0 0 400 400"); svg.setAttribute("role", "img"); svg.setAttribute("aria-label", "La bàn chỉ về điểm đến, hải trình nối nơi xuất phát với điểm đến");
        svg.innerHTML = '<ellipse class="pai-ct" cx="210" cy="205" rx="190" ry="150" transform="rotate(-18 210 205)"/><ellipse class="pai-ct" cx="205" cy="210" rx="160" ry="118" transform="rotate(14 205 210)"/><ellipse class="pai-ct" cx="190" cy="200" rx="135" ry="92" transform="rotate(-30 190 200)"/><ellipse class="pai-ct" cx="215" cy="190" rx="108" ry="150" transform="rotate(25 215 190)"/>'
            + '<circle class="pai-rg" cx="200" cy="200" r="112"/><circle class="pai-rg" cx="200" cy="200" r="88" stroke-dasharray="1 5"/>' + ticks
            + '<text class="pai-cd" x="200" y="72" text-anchor="middle">B</text><text class="pai-cd" x="200" y="337" text-anchor="middle">N</text><text class="pai-cd" x="336" y="205" text-anchor="middle">Đ</text><text class="pai-cd" x="64" y="205" text-anchor="middle">T</text>'
            + '<mask id="paM" maskUnits="userSpaceOnUse" x="0" y="0" width="400" height="400"><path id="paMc" fill="none" stroke="#fff" stroke-width="10" d=""/></mask>'
            + '<path id="paC" class="pai-course" d="" stroke-dasharray="3 7" mask="url(#paM)"/>'
            + '<g id="paNd" class="pai-needle"><path d="M200 94 209 200 191 200Z" fill="#e9a23b"/><path d="M200 306 209 200 191 200Z" fill="#0b2b33"/><circle cx="200" cy="200" r="5" fill="#f3f8f7" stroke="#0b2b33" stroke-width="2"/></g>'
            + '<g class="pai-pin" id="paA"><circle r="7" fill="#167A85"/><text id="paTa" y="-12" text-anchor="middle"></text></g>'
            + '<g class="pai-pin" id="paB"><circle r="8" fill="#e9a23b" stroke="#0b2b33" stroke-width="2"/><text id="paTb" y="-13" text-anchor="middle"></text></g>';
        p.appendChild(svg);
        p.appendChild(el("p", "pai-km", "")); p.lastChild.id = "paKm";
        var ex = el("div", "pai-ex"); ex.id = "paEx"; ex.hidden = true; p.appendChild(ex);
        var ul = el("ul", "pai-key"); ul.id = "paKey"; p.appendChild(ul);
        var g = el("button", "pai-plot", "Vạch hải trình"); g.type = "button"; g.id = "paGo"; g.onclick = make; p.appendChild(g);
        p.appendChild(el("p", "pai-note", "Ô nào chưa nói, AI sẽ tự chọn giúp và báo lại cho bạn."));
    }
    function proj(g) { return [Math.max(40, Math.min(360, 200 + (g[1] - 106.3) * 19.2)), Math.max(50, Math.min(370, 60 + (24 - g[0]) * 20))]; }
    function place(id, tid, pt, text) {
        var g = $(id), t = $(tid); g.classList.toggle("on", !!pt); if (!pt) return;
        g.setAttribute("transform", "translate(" + pt[0].toFixed(1) + " " + pt[1].toFixed(1) + ")"); t.textContent = text || "";
        t.setAttribute("text-anchor", pt[0] > 270 ? "end" : pt[0] < 130 ? "start" : "middle"); t.setAttribute("x", pt[0] > 270 ? 6 : pt[0] < 130 ? -6 : 0);
    }
    function plate() {
        var G = S.geo || {}, A = G.from ? proj(G.from) : null, B = G.to ? proj(G.to) : null;
        place("paA", "paTa", A, S.slots.from); place("paB", "paTb", B, S.slots.to);
        // kim: hướng la bàn thật nếu biết cả hai đầu; chỉ biết điểm đến thì chỉ về phao; chưa biết gì thì nghỉ
        S.ang = G.deg != null ? G.deg : (B ? Math.round(Math.atan2(B[0] - 200, 200 - B[1]) * 180 / Math.PI) : -24);
        var nd = $("paNd"); if (!S.scan) nd.style.transform = "rotate(" + S.ang + "deg)";
        // hải trình
        var c = $("paC"), key = A && B ? A.join() + "|" + B.join() : "";
        if (key !== S.courseKey) {
            S.courseKey = key;
            if (key) {
                var dx = B[0] - A[0], dy = B[1] - A[1], mx = (A[0] + B[0]) / 2 - dy * .22, my = (A[1] + B[1]) / 2 + dx * .22;
                var dd = "M" + A[0].toFixed(1) + " " + A[1].toFixed(1) + " Q" + mx.toFixed(1) + " " + my.toFixed(1) + " " + B[0].toFixed(1) + " " + B[1].toFixed(1), m = $("paMc");
                c.setAttribute("d", dd); m.setAttribute("d", dd);   // nét đứt hiện dần nhờ mặt nạ vẽ theo đường liền
                var len = m.getTotalLength(); m.style.transition = "none"; m.style.strokeDasharray = len; m.style.strokeDashoffset = len; void m.getBoundingClientRect();
                m.style.transition = "stroke-dashoffset 1.6s ease"; m.style.strokeDashoffset = 0;
            } else { c.setAttribute("d", ""); $("paMc").setAttribute("d", ""); }
        }
        $("paKm").textContent = G.km ? "Đường chim bay khoảng " + G.km.toLocaleString("vi-VN") + " km" : "";
        // chú giải có đường chấm dẫn
        progress();
        var ul = $("paKey"); ul.textContent = "";
        SLOTS.forEach(function (s) {
            var v = S.slots[s[0]], li = el("li", v ? "ok" : ""); li.appendChild(el("span", null, s[1])); li.appendChild(el("b", null, v || ""));
            if (v) { var e = el("button", null, "Đổi"); e.type = "button"; e.onclick = function () { change(s[0]); }; li.appendChild(e); }
            ul.appendChild(li);
        });
        var ex = $("paEx"); ex.textContent = ""; ex.hidden = !S.extras.length; S.extras.forEach(function (x) { ex.appendChild(el("p", null, x)); });
        $("paGo").disabled = S.busy;
    }

    /* ---------- vòng hội thoại ---------- */
    function turn(text, label) {
        text = (text || "").trim(); if (!text || S.busy) return;
        if (S.asked === "to" && /gợi ý|tùy bạn|chưa biết|đâu cũng được/i.test(text)) return suggest();
        setBusy(true); msg("me", label || text); chips([]);
        if (S.asked === "from" && text.length <= 30) S.from = text; else S.entries.push({ slot: S.asked && text.length <= 40 ? S.asked : "free", text: text.slice(0, 500) });
        read();
    }
    function suggest() {
        if (S.busy) return; setBusy(true); chips([]); msg("me", "Gợi ý giúp mình");
        post(cfg.read, { suggest: true }).then(function (r) {
            if (r.pick) { S.entries.push({ slot: "to", text: "đi " + r.pick }); read(); }
            else { msg("ai", "Chưa có điểm đến nào đủ dữ liệu để gợi ý. Bạn nói tên nơi muốn đi nhé."); setBusy(false); plate(); }
        }).catch(function () { msg("ai", "Kết nối bị gián đoạn, bạn thử lại nhé."); setBusy(false); plate(); });
    }
    function change(slot) {
        if (S.busy) return; var lab = SLOTS.filter(function (s) { return s[0] === slot; })[0][1];
        setBusy(true); chips([]); msg("me", "Đổi: " + lab.toLowerCase());
        if (slot === "from") S.from = null; else S.entries = S.entries.filter(function (e) { return e.slot !== slot; });
        S.adjustments = {}; S.reviewSignature = "";
        S.asked = null; read({ changed: slot });
    }
    function ack(prev) {
        var got = SLOTS.filter(function (s) { return !prev[s[0]] && S.slots[s[0]]; }).map(function (s) { return (s[0] === "from" ? "từ " : "") + S.slots[s[0]]; });
        if (got.length) msg("ai", "Mình ghi lại: " + got.join(", ") + ".");
    }
    function recap() {
        var d = msg("ai", "Đủ thông tin rồi. Mình vạch hải trình luôn nhé?"), ul = el("ul", "pai-sum"); leaders(ul); d.appendChild(ul);
    }
    function read(o) {
        o = o || {}; var prev = {}, k; for (k in S.slots) prev[k] = S.slots[k];
        var d = o.quiet ? null : typing();
        post(cfg.read, { said: S.entries.map(function (e) { return e.text; }).slice(-12), from: S.from }).then(function (r) {
            if (d) d.remove();
            S.slots = r.slots || {}; S.extras = r.extras || []; S.geo = r.geo || {};
            if (o.changed && o.changed !== "from" && S.slots[o.changed] && !o.retried) {   // câu kể ban đầu còn giữ ô này
                S.entries = S.entries.filter(function (e) { return e.slot !== "free"; });
                msg("ai", "Câu kể ban đầu của bạn vẫn đang giữ ô này nên mình bỏ nó đi để đổi cho chắc. Bạn nói lại các ý còn lại nếu cần nhé.");
                return read({ changed: o.changed, retried: true });
            }
            setBusy(false); plate();
            if (!o.quiet) { if (r.refused) msg("ai", r.refused); ack(prev); }
            var n = r.next;
            if (!n) { S.asked = null; if (!o.quiet) recap(); chips(RECAP); }
            else {
                if (!o.quiet && !o.changed && S.asked === n.slot && n.slot !== "from" && S.entries.length) msg("ai", "Mình chưa nhận ra ý này trong dữ liệu Travela. Bạn chọn một gợi ý hoặc nói cách khác nhé.");
                S.asked = n.slot; if (!o.quiet) msg("ai", n.q); chips(n.chips);
            }
            save(); if (!o.quiet) $("paiTxt").focus();
        }).catch(function () {
            if (d) d.remove(); setBusy(false); plate();
            msg("ai", "Kết nối bị gián đoạn. Nội dung bạn vừa gửi vẫn được giữ; bấm thử lại để AI đọc tiếp.");
            chips([{ label: "Thử lại", text: "__retry", primary: true }]); save();
        });
    }

    /* ---------- Xem lại trước khi AI tạo và ghi chuyến ---------- */
    function reviewCard(r) {
        setBusy(false);
        var info = r.details || {}, snapshot = JSON.stringify(requestBody(false));
        var d = msg("ai", "Kiểm tra lại chuyến đi trước khi lưu. Bạn có thể sửa trực tiếp những thông tin dưới đây.", true);
        d.classList.add("pai-review");
        var head = el("div", "pai-review__head");
        head.appendChild(el("strong", null, "Bản xem trước chuyến đi"));
        head.appendChild(el("span", null, "Chưa lưu vào hệ thống"));
        d.appendChild(head);
        d.appendChild(el("p", "pai-review__dest", "Điểm đến: " + (info.destination || "Chưa xác định") + ". Muốn đổi điểm đến, chọn nút 'Đổi điểm đến' bên dưới."));
        var form = el("form", "pai-review__form"); form.noValidate = false;
        var fields = [
            { name: "days", label: "Số ngày", type: "number", min: "1", max: "7", value: info.days || 3 },
            { name: "adults", label: "Người lớn", type: "number", min: "1", max: "50", value: info.adults || 2 },
            { name: "children", label: "Trẻ em", type: "number", min: "0", max: "30", value: info.children || 0 },
            { name: "startDate", label: "Ngày khởi hành (nếu đã biết)", type: "date", value: info.date || "" },
            { name: "budget", label: "Ngân sách dự kiến (đ)", type: "number", min: "0", max: "100000000000", step: "1000", value: typeof info.budget === 'number' ? info.budget : (info.budget && info.budget.amountVnd != null ? info.budget.amountVnd : "") }
        ];
        fields.forEach(function (field) {
            var label = el("label"); label.appendChild(el("span", null, field.label));
            var input = el("input"); input.name = field.name; input.type = field.type; input.value = S.adjustments[field.name] != null ? S.adjustments[field.name] : field.value;
            if (field.min != null) input.min = field.min;
            if (field.max != null) input.max = field.max;
            if (field.step) input.step = field.step;
            input.addEventListener("input", function () { confirm.disabled = true; update.textContent = "Kiểm tra lại thay đổi"; });
            label.appendChild(input); form.appendChild(label);
        });
        var note = el("p", "pai-review__note", "Lịch trình và chi phí là đề xuất, không phải xác nhận giá hoặc giờ mở cửa thực tế.");
        form.appendChild(note);
        if (r.filled && r.filled.length) {
            var labels = { to: "điểm đến", when: "thời gian/số ngày", who: "số người" };
            form.appendChild(el("p", "pai-review__assumed", "AI đã tự chọn: " + r.filled.map(function (k) { return labels[k] || k; }).join(", ") + ". Hãy kiểm tra kỹ."));
        }
        var actions = el("div", "pai-review__actions");
        var confirm = el("button", "pai-plot", "Xác nhận và tạo chuyến"); confirm.type = "button";
        var update = el("button", "pai-plot pai-plot--secondary", "Kiểm tra lại thông tin"); update.type = "submit";
        var editDest = el("button", "pai-plot pai-plot--link", "Đổi điểm đến / sở thích"); editDest.type = "button";
        function collect() {
            if (!form.reportValidity()) return false;
            var data = {};
            fields.forEach(function (f) {
                var v = form.elements[f.name].value;
                data[f.name] = f.type === "date" ? (v || null) : (v === "" ? null : Number(v));
            });
            if (data.adults + data.children > 50) { msg("ai", "Tổng số người không được vượt quá 50.", true); return false; }
            S.adjustments = data;
            save();
            return true;
        }
        update.addEventListener("click", function () { /* sự kiện submit bên dưới xử lý */ });
        form.addEventListener("submit", function (e) {
            e.preventDefault(); if (!collect()) return;
            d.remove(); make();
        });
        confirm.addEventListener("click", function () {
            if (!collect()) return;
            if (JSON.stringify(S.adjustments) !== S.reviewSignature || JSON.stringify(requestBody(false)) !== snapshot) {
                confirm.disabled = true; update.focus(); return;
            }
            confirm.disabled = true; createConfirmed();
        });
        editDest.onclick = function () { chips(RECAP.filter(function (c) { return c.text !== "__make"; })); $("paiTxt").focus(); };
        actions.appendChild(confirm); actions.appendChild(update); actions.appendChild(editDest);
        form.appendChild(actions); d.appendChild(form);
        // Chuẩn hóa giá trị từ chính bản xem trước: bấm xác nhận ngay không cần duyệt lại
        // nếu người dùng chưa sửa; sau khi thay đổi mới bắt buộc xem trước lần nữa.
        if (collect()) {
            S.reviewSignature = JSON.stringify(S.adjustments);
            snapshot = JSON.stringify(requestBody(false));
        } else confirm.disabled = true;
        save();
        plate();
        confirm.focus();
    }
    function requestBody(confirmed) {
        return { said: S.entries.map(function (e) { return e.text; }).slice(-12), from: S.from, confirmed: !!confirmed, adjustments: S.adjustments };
    }
    function createConfirmed() {
        if (S.busy) return;
        setBusy(true);
        msg("ai", "Đang dựng lịch trình theo các thông tin bạn xác nhận…", true);
        post(cfg.make, requestBody(true)).then(function (r) {
            settle();
            if (!r.ok || !r.url) return fail(r.message || "Không tạo được chuyến đi.");
            wipe();
            var d = msg("ai", "Đã tạo " + (r.title || "chuyến đi") + ". Bạn có thể mở và chỉnh từng ngày.", true);
            var link = el("a", "pai-link", "Mở lịch trình"); link.href = r.url; d.appendChild(link);
            setBusy(false);
            plate();
            link.focus();
        }).catch(function () { settle(); fail("Chưa xác nhận được kết quả tạo chuyến. Hãy kiểm tra danh sách chuyến trước khi thử tạo lại để tránh trùng."); });
    }
    function make() {
        if (S.busy) return;
        setBusy(true);
        chips([]);
        post(cfg.make, requestBody(false)).then(function (r) {
            if (!r.ok || !r.review) return fail(r.message || "Chưa xem trước được thông tin chuyến.");
            reviewCard(r);
        }).catch(function () { fail("Không tải được bản xem trước. Bạn thử lại nhé."); });
    }
    function settle() {   // kim dừng lại đúng hướng đi, có độ nảy nhẹ
        var nd = $("paNd"); S.scan = false; nd.classList.remove("scan"); nd.style.transition = "none"; nd.style.transform = "rotate(" + (S.ang - 40) + "deg)";
        void nd.getBoundingClientRect(); nd.style.transition = ""; nd.style.transform = "rotate(" + S.ang + "deg)";
    }
    function fail(m) {
        setBusy(false); plate();
        msg("ai", m || "Chưa tạo được chuyến đi."); chips([{ label: "Thử vạch lại", text: "__make", primary: true }]);
    }

    /* ---------- khởi động ---------- */
    $("paiForm").onsubmit = function (e) {
        e.preventDefault(); var i = $("paiTxt"), t = i.value.trim();
        if (S.busy || !t) return;
        i.value = ""; S.adjustments = {}; S.reviewSignature = ""; turn(t);
    };
    $("paiReset").onclick = function () {
        if (!window.confirm("Xóa hội thoại và bắt đầu một chuyến mới?")) return;
        wipe(); location.reload();
    };
    buildPlate();
    var saved = load();
    if (saved) {
        S.entries = saved.entries; S.from = saved.from; S.asked = saved.asked; S.log = saved.log;
        S.adjustments = saved.adjustments || {};
        saved.log.forEach(function (l) { msg(l.w, l.t, true); }); setBusy(true); plate(); read({ quiet: true });
    } else {
        setBusy(true); plate();
        msg("ai", "Chào bạn, mình là Travela AI. Kể mình nghe chuyến đi bạn đang nghĩ tới, mình hỏi từng ý rồi vạch lịch trình giúp.");
        read();
    }
})();
