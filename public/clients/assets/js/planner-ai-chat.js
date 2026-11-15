/* Tạo lịch với AI: trò chuyện bên trái; bên phải là ô tóm tắt chuyến (sửa được) và BẢN NHÁP LỊCH THẬT do bộ xếp lịch tạo, chưa lưu.
 * La bàn chỉ còn là biểu tượng nhỏ ở đầu ô tóm tắt. AI chỉ hiểu yêu cầu; lịch do PlannerService xếp; bấm "Lưu chuyến này" mới ghi.
 * (Trước đây: "Vạch hải trình": trò chuyện tạo chuyến + hải đồ.) Mọi chữ động gán bằng textContent (không innerHTML với dữ liệu từ máy chủ / người dùng; innerHTML chỉ dùng cho SVG tĩnh).
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
    var RECAP = [{ label: "Xếp lại bản nháp", text: "__make", primary: true }, { label: "Đổi điểm đến", text: "__change:to" }, { label: "Đổi ngày", text: "__change:when" }, { label: "Đổi người đi", text: "__change:who" }];
    var NS = "http://www.w3.org/2000/svg";
    var S = { entries: [], from: null, asked: null, log: [], slots: {}, extras: [], geo: {}, busy: false, ang: -24, scan: false, courseKey: "", adjustments: {}, reviewSignature: "", draft: null, draftToken: null, reviewEl: null, confirmBtn: null };
    var INTERESTS = { nature: "Thiên nhiên", culture: "Văn hóa, lịch sử", food: "Ẩm thực", adventure: "Vận động", relax: "Thư giãn", family: "Gia đình, trẻ nhỏ" };
    var PACES = [["relaxed", "Thong thả"], ["normal", "Vừa phải"], ["packed", "Đi nhiều"]];
    var TYPES = { place: "Tham quan", food: "Món ăn", experience: "Trải nghiệm", event: "Sự kiện", hotel: "Lưu trú", stay: "Lưu trú" };
    var GROUPS = { eat: "Ăn uống", nature: "Thiên nhiên", culture: "Văn hóa, lịch sử", fun: "Vui chơi", see: "Tham quan" };
    function hm(m) { m = Math.max(0, Math.round(m)); return String(Math.floor(m / 60) % 24).padStart(2, "0") + ":" + String(m % 60).padStart(2, "0"); }
    function durT(m) { m = Math.round(m); return m >= 60 ? Math.floor(m / 60) + " giờ" + (m % 60 ? " " + (m % 60) + " phút" : "") : m + " phút"; }

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function post(url, body) {
        var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, 60000) : null;
        return fetch(url, { method: "POST", credentials: "same-origin", signal: ctl ? ctl.signal : undefined,
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify(body) }).then(function (r) { clearTimeout(t); return r.ok ? r.json() : Promise.reject(r.status); }, function (e) { clearTimeout(t); return Promise.reject(e); });
    }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify({ t: Date.now(), entries: S.entries, from: S.from, asked: S.asked, log: S.log.slice(-60), adjustments: S.adjustments, draft: S.draft })); } catch (e) {} }
    function load() { try { var j = JSON.parse(sessionStorage.getItem(KEY) || "null"); if (j && Date.now() - j.t < 72e5 && j.entries && j.log && j.log.length) return j; } catch (e) {} return null; }
    function wipe() { try { sessionStorage.removeItem(KEY); } catch (e) {} }

    /* ---------- nhật ký (trái) ---------- */
    function scroll() { var m = $("paiMsgs"); m.scrollTop = m.scrollHeight; }
    function progress() {
        var known = SLOTS.filter(function (x) { return !!S.slots[x[0]]; }).length;
        var fill = $("paiProgressFill"), label = $("paiProgressLabel");
        if (fill) fill.style.width = Math.round(known / SLOTS.length * 100) + "%";
        if (label) label.textContent = known === SLOTS.length ? "Đủ thông tin để tạo lịch ✓" : "Đã hiểu " + known + "/" + SLOTS.length + " ý · cần thêm: " + SLOTS.filter(function (x) { return !S.slots[x[0]]; }).map(function (x) { return x[1].toLowerCase(); }).join(", ");
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
        var brief = el("section", "pai-brief"); brief.setAttribute("aria-label", "Travela đang hiểu chuyến của bạn");
        var bh = el("div", "pai-brief__head");
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
        svg.setAttribute("aria-hidden", "true"); svg.removeAttribute("role");
        bh.appendChild(svg);
        var bt = el("div"); bt.appendChild(el("b", null, "Travela đang hiểu")); var km = el("small", "pai-km", ""); km.id = "paKm"; bt.appendChild(km);
        bh.appendChild(bt); brief.appendChild(bh);
        var ul = el("ul", "pai-key"); ul.id = "paKey"; brief.appendChild(ul);
        var ex = el("div", "pai-ex"); ex.id = "paEx"; ex.hidden = true; brief.appendChild(ex);
        var g = el("button", "pai-plot", "Tạo bản nháp lịch trình"); g.type = "button"; g.id = "paGo"; g.onclick = make; brief.appendChild(g);
        brief.appendChild(el("p", "pai-note", "Ô nào chưa nói, Travela tự chọn và ghi rõ là đã tự chọn."));
        p.appendChild(brief);
        var dr = el("section", "pai-draft"); dr.id = "paDraft"; dr.setAttribute("aria-labelledby", "paDraftH"); dr.setAttribute("aria-live", "polite");
        p.appendChild(dr);
        renderDraft(S.draft);
    }
    /* ---------- bản nháp lịch: do bộ xếp lịch tạo, chưa lưu ---------- */
    function renderDraft(dr, why) {
        var box = $("paDraft"); if (!box) return;
        box.textContent = "";
        var h = el("div", "pai-draft__h");
        var hh = el("h2", null, "Bản nháp lịch trình"); hh.id = "paDraftH"; h.appendChild(hh);
        h.appendChild(el("span", "pai-draft__tag", "Chưa lưu"));
        box.appendChild(h);
        if (!dr) {
            box.appendChild(el("p", "pai-draft__empty", why || "Kể nơi muốn đi, đi mấy ngày và thích gì. Khi đủ ý, lịch nháp từng ngày hiện ở đây để bạn xem trước khi lưu."));
            return;
        }
        var sum = el("p", "pai-draft__sum");
        sum.textContent = (dr.destination ? dr.destination + " · " : "") + dr.days.length + " ngày · " + dr.count + " điểm · đi lại ≈ " + durT(dr.travelMin)
            + (dr.knownCost ? " · chi phí biết trước ≈ " + dr.knownCost.toLocaleString("vi-VN") + "đ" : "");
        box.appendChild(sum);
        var start = dr.startDate ? new Date(dr.startDate + "T00:00:00") : null, DOW = ["CN", "T2", "T3", "T4", "T5", "T6", "T7"];
        dr.days.forEach(function (d) {
            var sec = el("section", "pai-dday");
            var t = el("h3");
            t.appendChild(el("span", null, "Ngày " + d.day));
            if (start) { var dt = new Date(start.getTime() + (d.day - 1) * 864e5); t.appendChild(el("small", null, DOW[dt.getDay()] + " " + String(dt.getDate()).padStart(2, "0") + "/" + String(dt.getMonth() + 1).padStart(2, "0"))); }
            t.appendChild(el("small", "pai-dday__m", d.items.length + " điểm · " + durT(d.activityMin) + " hoạt động · " + (d.travelMin ? "≈ " + durT(d.travelMin) + " đi lại" : "gần nhau")));
            sec.appendChild(t);
            var ol = el("ol", "pai-dlist");
            if (!d.items.length) ol.appendChild(el("li", "pai-dlist__empty", "Ngày trống: dữ liệu chưa đủ. Sau khi lưu có thể tự thêm."));
            d.items.forEach(function (it, i) {
                if (i > 0 && it.travelMin > 0) ol.appendChild(el("li", "pai-dleg", "↓ " + durT(it.travelMin) + " · " + String(it.travelKm).replace(".", ",") + " km"));
                var li = el("li", "pai-dit");
                li.appendChild(el("time", null, hm(it.start)));
                var b = el("div");
                b.appendChild(el("b", null, it.name));
                b.appendChild(el("small", null, (it.type === "place" && GROUPS[it.group] ? GROUPS[it.group] : TYPES[it.type] || "Địa điểm") + " · " + durT(it.end - it.start) + (it.price != null ? (it.price <= 0 ? " · miễn phí" : " · từ " + Math.round(it.price).toLocaleString("vi-VN") + "đ") : "") + (it.osm ? " · từ bản đồ, chưa biên tập" : "")));
                if (it.reasons && it.reasons.length) {
                    var w = el("details", "pai-why"); w.appendChild(el("summary", null, "Vì sao chọn"));
                    var wl = el("ul"); it.reasons.forEach(function (x) { wl.appendChild(el("li", null, x)); }); w.appendChild(wl); b.appendChild(w);
                }
                li.appendChild(b); ol.appendChild(li);
            });
            sec.appendChild(ol); box.appendChild(sec);
        });
        if (dr.unplaced) box.appendChild(el("p", "pai-draft__note", dr.unplaced + " nơi hợp ý nhưng không vừa lịch sẽ nằm ở mục “Đã lưu” sau khi lưu."));
        box.appendChild(el("p", "pai-draft__note", "Giờ, đường đi và chi phí là ước tính từ dữ liệu Travela; giờ mở cửa đã được kiểm khi xếp. Sau khi lưu bạn chỉnh được từng mục."));
        var act = el("div", "pai-draft__act");
        var sv = el("button", "pai-plot", "Lưu chuyến này"); sv.type = "button";
        sv.onclick = function () { if (S.confirmBtn && !S.confirmBtn.disabled) S.confirmBtn.click(); else if (S.confirmBtn) S.confirmBtn.focus(); else make(); };
        act.appendChild(sv);
        PACES.forEach(function (p) {
            if ((S.adjustments.pace || "normal") === p[0]) return;
            var q = el("button", "pai-plot pai-plot--secondary", p[0] === "relaxed" ? "Nhẹ nhàng hơn" : p[0] === "packed" ? "Đi nhiều hơn" : "Vừa phải"); q.type = "button";
            q.onclick = function () { if (S.busy) return; S.adjustments.pace = p[0]; save(); make(); };
            act.appendChild(q);
        });
        box.appendChild(act);
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
        var d = msg("ai", "Đủ thông tin rồi. Mình xếp thử lịch từng ngày, bạn xem bên cạnh nhé."), ul = el("ul", "pai-sum"); leaders(ul); d.appendChild(ul);
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
            if (!n) { S.asked = null; chips(RECAP); if (!o.quiet) { recap(); setTimeout(make, 0); } }
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

    /* ---------- Bản nháp: xem lịch thật, sửa thông tin, rồi mới lưu ---------- */
    function reviewCard(r) {
        setBusy(false);
        if (S.reviewEl && S.reviewEl.parentNode) S.reviewEl.remove();   // chỉ giữ một thẻ nháp mới nhất trong hội thoại
        var info = r.details || {}, snapshot = JSON.stringify(requestBody(false)), dr = r.draft;
        S.draft = dr || null; S.draftToken = r.draftToken || null;
        renderDraft(S.draft, r.draftMessage);
        var d = msg("ai", dr ? "Mình đã xếp thử " + dr.count + " điểm trong " + dr.days.length + " ngày, đi lại khoảng " + durT(dr.travelMin) + ". Xem bản nháp, sửa thông tin dưới đây nếu cần, ưng thì bấm Lưu."
            : (r.draftMessage || "Chưa xếp được bản nháp với thông tin này. Bạn đổi thông tin dưới đây rồi xếp lại nhé."), true);
        S.reviewEl = d;
        d.classList.add("pai-review");
        var head = el("div", "pai-review__head");
        head.appendChild(el("strong", null, "Thông tin của bản nháp"));
        head.appendChild(el("span", null, "Chưa lưu vào hệ thống"));
        d.appendChild(head);
        d.appendChild(el("p", "pai-review__dest", "Điểm đến: " + (info.destination || "Chưa xác định") + ". Muốn đổi điểm đến, chọn “Đổi điểm đến” bên dưới."));
        var form = el("form", "pai-review__form"); form.noValidate = false;
        var fields = [
            { name: "days", label: "Số ngày", type: "number", min: "1", max: "7", value: info.days || 3 },
            { name: "adults", label: "Người lớn", type: "number", min: "1", max: "50", value: info.adults || 2 },
            { name: "children", label: "Trẻ em", type: "number", min: "0", max: "30", value: info.children || 0 },
            { name: "startDate", label: "Ngày khởi hành (nếu đã biết)", type: "date", value: info.date || "" },
            { name: "budget", label: "Ngân sách dự kiến (đ)", type: "number", min: "0", max: "100000000000", step: "1000", value: typeof info.budget === 'number' ? info.budget : (info.budget && info.budget.amountVnd != null ? info.budget.amountVnd : "") }
        ];
        function dirtyUp() { confirm.disabled = true; update.textContent = "Xếp lại với thông tin mới"; }
        fields.forEach(function (field) {
            var label = el("label"); label.appendChild(el("span", null, field.label));
            var input = el("input"); input.name = field.name; input.type = field.type; input.value = S.adjustments[field.name] != null ? S.adjustments[field.name] : field.value;
            if (field.min != null) input.min = field.min;
            if (field.max != null) input.max = field.max;
            if (field.step) input.step = field.step;
            input.addEventListener("input", dirtyUp);
            label.appendChild(input); form.appendChild(label);
        });
        // nhịp đi và sở thích: sửa thẳng, không cần nói lại trong hội thoại
        var paceL = el("label"); paceL.appendChild(el("span", null, "Nhịp đi"));
        var paceS = el("select"); paceS.name = "pace";
        PACES.forEach(function (p) { var o = el("option", null, p[1]); o.value = p[0]; paceS.appendChild(o); });
        paceS.value = S.adjustments.pace || info.pace || "normal"; paceS.addEventListener("change", dirtyUp);
        paceL.appendChild(paceS); form.appendChild(paceL);
        var fs = el("fieldset", "pai-review__likes"); fs.appendChild(el("legend", null, "Sở thích"));
        var curI = S.adjustments.interests || info.interests || [];
        Object.keys(INTERESTS).forEach(function (k) {
            var lb = el("label", "pai-chk"), cb = el("input"); cb.type = "checkbox"; cb.value = k; cb.checked = curI.indexOf(k) >= 0; cb.addEventListener("change", dirtyUp);
            lb.appendChild(cb); lb.appendChild(el("span", null, INTERESTS[k])); fs.appendChild(lb);
        });
        form.appendChild(fs);
        if (r.filled && r.filled.length) {
            var labels = { to: "điểm đến", when: "thời gian/số ngày", who: "số người" };
            form.appendChild(el("p", "pai-review__assumed", "Travela đã tự chọn: " + r.filled.map(function (k) { return labels[k] || k; }).join(", ") + ". Hãy kiểm tra kỹ."));
        }
        var actions = el("div", "pai-review__actions");
        var confirm = el("button", "pai-plot", "Lưu chuyến này"); confirm.type = "button";
        var update = el("button", "pai-plot pai-plot--secondary", "Xếp lại bản nháp"); update.type = "submit";
        var editDest = el("button", "pai-plot pai-plot--link", "Đổi điểm đến"); editDest.type = "button";
        S.confirmBtn = confirm;
        function collect() {
            if (!form.reportValidity()) return false;
            var data = {};
            fields.forEach(function (f) {
                var v = form.elements[f.name].value;
                data[f.name] = f.type === "date" ? (v || null) : (v === "" ? null : Number(v));
            });
            data.pace = paceS.value;
            data.interests = Array.prototype.filter.call(fs.querySelectorAll("input"), function (x) { return x.checked; }).map(function (x) { return x.value; });
            if (data.adults + data.children > 50) { msg("ai", "Tổng số người không được vượt quá 50.", true); return false; }
            S.adjustments = data;
            save();
            return true;
        }
        form.addEventListener("submit", function (e) {
            e.preventDefault(); if (!collect()) return;
            make();
        });
        confirm.addEventListener("click", function () {
            if (!collect()) return;
            if (JSON.stringify(S.adjustments) !== S.reviewSignature || JSON.stringify(requestBody(false)) !== snapshot) {
                confirm.disabled = true; update.focus(); return;
            }
            confirm.disabled = true; createConfirmed();
        });
        editDest.onclick = function () { change("to"); };
        actions.appendChild(confirm); actions.appendChild(update); actions.appendChild(editDest);
        form.appendChild(actions); d.appendChild(form);
        if (collect()) {
            S.reviewSignature = JSON.stringify(S.adjustments);
            snapshot = JSON.stringify(requestBody(false));
        }
        if (!dr) confirm.disabled = true;
        save();
        plate();
    }
    function requestBody(confirmed) {
        var b = { said: S.entries.map(function (e) { return e.text; }).slice(-12), from: S.from, confirmed: !!confirmed, adjustments: S.adjustments };
        if (confirmed && S.draftToken) b.draftToken = S.draftToken;   // lưu đúng bản nháp vừa xem
        return b;
    }
    function createConfirmed() {
        if (S.busy) return;
        setBusy(true);
        msg("ai", "Đang lưu bản nháp thành chuyến của bạn…", true);
        post(cfg.make, requestBody(true)).then(function (r) {
            settle();
            if (!r.ok || !r.url) return fail(r.message || "Không tạo được chuyến đi.");
            wipe(); S.draft = null; S.draftToken = null; S.confirmBtn = null;
            var dd = $("paDraft"); if (dd) { var tg = dd.querySelector(".pai-draft__tag"); if (tg) { tg.textContent = "Đã lưu"; tg.classList.add("is-saved"); } var ac = dd.querySelector(".pai-draft__act"); if (ac) ac.remove(); }
            var d = msg("ai", "Đã lưu " + (r.title || "chuyến đi") + ". Mở lịch để chỉnh từng ngày, xem bản đồ và đường đi.", true);
            var link = el("a", "pai-link", "Mở lịch trình"); link.href = r.url; d.appendChild(link);
            // sau khi có chuyến: La bàn gợi ý tiếp ngay trong trình soạn (món ăn, điểm tham quan, lễ hội, cảnh báo thời tiết / sự cố)
            var nx = msg("ai", "Muốn La bàn gợi ý thêm cho chuyến này? Chọn một chủ đề, La bàn mở lịch và đưa các lựa chọn để bạn thêm vào ngày phù hợp.", true);
            var row = el("div", "pai-next");
            [["Món nên thử", "food"], ["Điểm tham quan", "places"], ["Lễ hội dịp đi", "festival"], ["Cảnh báo thời tiết, sự cố", "alerts"]].forEach(function (x) {
                var a = el("a", "pai-next__a", x[0]); a.href = r.url + (r.url.indexOf("?") >= 0 ? "&" : "?") + "la-ban=" + x[1]; row.appendChild(a);
            });
            nx.appendChild(row);
            setBusy(false);
            plate();
            link.focus();
        }).catch(function () { settle(); fail("Chưa xác nhận được kết quả tạo chuyến. Hãy kiểm tra danh sách chuyến trước khi thử tạo lại để tránh trùng."); });
    }
    function make() {
        if (S.busy) return;
        setBusy(true);
        chips([]);
        var box = $("paDraft");
        if (box) { box.classList.add("is-busy"); box.setAttribute("aria-busy", "true"); var dt = box.querySelector(".pai-draft__sum, .pai-draft__empty"); if (dt) dt.textContent = "Đang xếp lịch từng ngày: chọn nơi hợp sở thích, kiểm giờ mở cửa, tính đường đi…"; }
        post(cfg.make, requestBody(false)).then(function (r) {
            if (box) { box.classList.remove("is-busy"); box.removeAttribute("aria-busy"); }
            if (!r.ok || !r.review) return fail(r.message || "Chưa xếp được bản nháp.");
            reviewCard(r);
        }).catch(function () { if (box) { box.classList.remove("is-busy"); box.removeAttribute("aria-busy"); } fail("Không tải được bản nháp. Bạn thử lại nhé."); });
    }
    function settle() {   // kim dừng lại đúng hướng đi, có độ nảy nhẹ
        var nd = $("paNd"); S.scan = false; nd.classList.remove("scan"); nd.style.transition = "none"; nd.style.transform = "rotate(" + (S.ang - 40) + "deg)";
        void nd.getBoundingClientRect(); nd.style.transition = ""; nd.style.transform = "rotate(" + S.ang + "deg)";
    }
    function fail(m) {
        setBusy(false); plate();
        msg("ai", m || "Chưa tạo được chuyến đi."); chips([{ label: "Xếp lại bản nháp", text: "__make", primary: true }]);
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
        S.adjustments = saved.adjustments || {}; S.draft = saved.draft || null; renderDraft(S.draft);
        saved.log.forEach(function (l) { msg(l.w, l.t, true); }); setBusy(true); plate(); read({ quiet: true });
    } else {
        setBusy(true); plate();
        msg("ai", "Chào bạn, mình là Travela AI. Kể mình nghe chuyến đi bạn đang nghĩ tới; đủ ý là mình xếp thử lịch từng ngày để bạn xem trước khi lưu.");
        read();
    }
})();
