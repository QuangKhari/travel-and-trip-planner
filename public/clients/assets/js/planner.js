/* Trình soạn chuyến đi (/len-ke-hoach/{id}). JS thuần, dữ liệu từ <script type="application/json" id="tpData">.
 *
 * Trạng thái nằm trong mảng `items` + các ô thông tin; mọi chữ động gán bằng textContent (không innerHTML).
 * - Hoàn tác / làm lại: mỗi thay đổi đã "chốt" (commit) so với ảnh chụp trước đó và đẩy vào ngăn xếp.
 * - Tự lưu sau vài giây không thao tác; lưu xong không tải lại trang.
 * - Quãng đường theo ngày tính ngay trên trình duyệt bằng planner-geo.js (cùng công thức với máy chủ).
 * - Kéo-thả bằng HTML5 (tay cầm ⠿); trên cảm ứng dùng nút ↑ ↓ và ô chọn ngày.
 * - Bản đồ: Leaflet + OpenStreetMap; nếu cấu hình osrmUrl thì vẽ đường bộ, không thì đường thẳng.
 */
(function () {
    "use strict";
    var node = document.getElementById("tpData");
    if (!node) return;
    var cfg = JSON.parse(node.textContent);
    var G = window.TpGeo;

    var TYPE = { destination: "Điểm đến", place: "Địa điểm", food: "Món ăn", event: "Lễ hội", experience: "Trải nghiệm", tour: "Tour", note: "Mục tự thêm", transport: "Di chuyển" };
    var CUSTOM = { note: true, transport: true };
    var SLOT = { morning: "Sáng", noon: "Trưa", afternoon: "Chiều", evening: "Tối" };
    var SUGG_TITLE = { tray: "Từ khay chuyến đi", tour: "Tour sắp khởi hành", experience: "Trải nghiệm", food: "Món nên thử", event: "Lễ hội" };
    var COLORS = ["#0d6f6a", "#c2410c", "#1d4ed8", "#a21caf", "#4d7c0f", "#be123c", "#0e7490", "#92400e", "#4338ca", "#15803d", "#b45309", "#6d28d9", "#0369a1", "#9f1239"];
        var LIMITS = cfg.limits || { maxDays: 14, maxItems: 80 };
    var DAY_ITEMS_WARN = 6, DAY_TRAVEL_WARN_MIN = 300, AUTOSAVE_MS = 2500;
    var SRC = { link: "từ link", map: "chọn trên bản đồ", search: "từ tìm kiếm" };
    var SHORT_MSG = "Link rút gọn không chứa tọa độ. Hãy mở link trong trình duyệt rồi chép link đầy đủ ở thanh địa chỉ, hoặc bấm “Chọn trên bản đồ”.";
    var BAD_POS_MSG = "Chưa đọc được tọa độ. Dán link Google Maps đầy đủ hoặc nhập dạng 21.0285, 105.8542.";

    var $ = function (id) { return document.getElementById(id); };
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function btn(cls, text, label, fn) {
        var b = el("button", cls, text);
        b.type = "button";
        if (label) b.setAttribute("aria-label", label);
        b.addEventListener("click", fn);
        return b;
    }
    function safeUrl(u) { return /^(\/|https?:\/\/)/.test(u || "") ? u : null; }
    function money(n) { return Math.round(n || 0).toLocaleString("vi-VN") + "đ"; }
    function dur(min) { return Math.floor(min / 60) + "h" + String(min % 60).padStart(2, "0"); }
    function pad2(n) { return String(n).padStart(2, "0"); }

    /* ---------------- trạng thái ---------------- */
    var seq = 0;
    var items = cfg.items.map(function (x) {
        x.uid = ++seq;
        x.day = x.day == null ? null : +x.day;
        x.cost = x.cost == null || x.cost === "" ? null : +x.cost;
        x.note = x.note || "";
        x.slot = x.slot || null;
        x.reasons = x.reasons || [];
        return x;
    });
    var sugg = cfg.suggestions || {};
    var ROLE = cfg.role || "owner", RO = ROLE === "view", rev = cfg.rev || 0;
    /* Phương án khi mưa: { "<ngày>": [{type, refId, name, sub, url}] }, tách khỏi lịch chính và chi phí chính */
    var rain = (function (p) {
        var o = {};
        if (p && !Array.isArray(p)) Object.keys(p).forEach(function (k) { if (Array.isArray(p[k]) && p[k].length) o[k] = p[k]; });
        return o;
    })(cfg.rain && cfg.rain.plan);
    var RAIN_MAX = cfg.rainMax || 4;
    var undo = [], redo = [], last = "", savedSnap = "";
    var dirty = false, saving = false, pendingSave = false, autoFail = false, timer = null;
    var ui = { tab: "plan", target: 1, mapDay: "", sel: {}, open: {}, tier: null, results: null, searchSeq: 0, res: null, br: null, drag: null, pick: null, draft: null };

    var box = $("tpDaysBox"), msg = $("tpMsg");

    function daysCount() {
        var n = parseInt($("tpDays").value, 10);
        return isNaN(n) ? 1 : Math.max(1, Math.min(LIMITS.maxDays, n));
    }
    function meta() {
        var b = $("tpBudget").value;
        return {
            title: $("tpTitle").value.trim(),
            start: $("tpStart").value || null,
            days: daysCount(),
            group: Math.max(1, Math.min(50, parseInt($("tpGroup").value, 10) || 1)),
            budget: b === "" ? null : +b,
            dest: $("tpDest").value ? +$("tpDest").value : null,
        };
    }
    function snap() {
        return JSON.stringify({ m: meta(), i: items.map(function (x) { return x; }), r: rain });
    }
    function restore(s) {
        var o = JSON.parse(s), m = o.m;
        $("tpTitle").value = m.title;
        $("tpStart").value = m.start || "";
        $("tpDays").value = m.days;
        $("tpGroup").value = m.group;
        $("tpBudget").value = m.budget == null ? "" : m.budget;
        $("tpDest").value = m.dest == null ? "" : String(m.dest);
        items = o.i;
        rain = o.r || {};
        ui.sel = {}; ui.open = {};
        renderAll();
    }
    function clampDays() {
        var n = daysCount();
        items.forEach(function (it) { if (it.day != null && it.day > n) it.day = n; });
        $("tpDays").value = n;
    }
    function key(it) { return it.type + ":" + it.refId; }
    function haveMap() {
        var h = {};
        items.forEach(function (x) { if (!CUSTOM[x.type]) h[key(x)] = true; });
        return h;
    }
    function dayLabel(d) {
        var s = $("tpStart").value;
        if (!s) return "Ngày " + d;
        var dt = new Date(s + "T00:00:00");
        if (isNaN(dt.getTime())) return "Ngày " + d;
        dt.setDate(dt.getDate() + d - 1);
        return "Ngày " + d + " · " + dt.toLocaleDateString("vi-VN", { weekday: "short", day: "2-digit", month: "2-digit" });
    }
    function targetLabel(d) { return d == null ? "Chưa xếp ngày" : dayLabel(d); }

    /* ---------------- chốt thay đổi / hoàn tác ---------------- */
    function say(t, bad) {
        msg.textContent = t;
        msg.classList.toggle("tp-msg--bad", !!bad);
    }
    function markDirty() {
        if (RO) return;
        dirty = true;
        autoFail = false;
        say("Chưa lưu");
        clearTimeout(timer);
        timer = setTimeout(function () { save(false); }, AUTOSAVE_MS);
    }
    /** keepList = true: không dựng lại danh sách (đang gõ trong một ô, dựng lại sẽ mất con trỏ). */
    function commit(keepList) {
        if (RO) return;
        var now = snap();
        if (now === last) return;
        undo.push(last);
        if (undo.length > 60) undo.shift();
        redo = [];
        last = now;
        markDirty();
        renderAll(keepList);
    }
    function undoOnce() {
        if (!undo.length) return;
        redo.push(last);
        last = undo.pop();
        restore(last);
        markDirty();
    }
    function redoOnce() {
        if (!redo.length) return;
        undo.push(last);
        last = redo.pop();
        restore(last);
        markDirty();
    }
    function syncButtons() {
        $("tpUndo").disabled = !undo.length;
        $("tpRedo").disabled = !redo.length;
    }

    /* ---------------- thống kê ---------------- */
    function dayItems(d) { return items.filter(function (x) { return x.day === d; }); }
    function totalCost() { return items.reduce(function (s, x) { return s + (x.cost || 0); }, 0); }

    /* ---------------- giá tham khảo (ước tính khi chưa nhập) ---------------- */
    var REF = cfg.costRef || null;
    var TIER_LABEL = { low: "Tiết kiệm", mid: "Vừa phải", high: "Thoải mái" };
    var LODGE_RE = /khách sạn|hotel|homestay|resort|nhà nghỉ|hostel|villa|lưu trú|căn hộ|bungalow/i;
    var MEAL_RE = /nhà hàng|cà phê|cafe|coffee|phở|bún|buffet|cơm|lẩu|ăn uống|ăn sáng|ăn trưa|ăn tối|restaurant|(^|\s)quán(\s|$)/i;
    function tier() {
        if (ui.tier) return ui.tier;
        var m = meta();
        if (REF && m.budget > 0) {
            var per = m.budget / (m.group * m.days), th = REF.tierBudgetPerPersonDay || {};
            return per < (th.low || 0) ? "low" : per < (th.mid || 0) ? "mid" : "high";
        }
        return "mid";
    }
    function kindOf(it) {
        if (it.type === "food") return "meal";
        if (it.type === "experience") return "experience";
        if (it.type === "transport") return "transport";
        if (it.type === "note") {
            var t = (it.name || "") + " " + (it.sub || "");
            if (LODGE_RE.test(t)) return "lodging";
            if (MEAL_RE.test(t)) return "meal";
        }
        return null;
    }
    function roomsNeeded() { return Math.max(1, Math.ceil(meta().group / ((REF && REF.peoplePerRoom) || 2))); }
    function nightsCount() { return Math.max(1, daysCount() - 1); }
    /** Giá ước tính của MỘT mục chưa nhập chi phí; null nếu không ước tính được (địa điểm, lễ hội...). */
    function estItem(it) {
        if (!REF || it.cost != null) return null;
        var k = kindOf(it), t = tier(), m = meta();
        if (k === "meal" || k === "experience") return REF[k][t] * m.group;
        if (k === "transport") return REF.transport[t];
        if (k === "lodging") return REF.lodging[t] * roomsNeeded() * nightsCount();
        return null;
    }
    /** Dự toán tham khảo: ước tính theo mục + các khoản chưa có mục nào (lưu trú, ăn uống, đi lại). */
    function estimates() {
        var out = { items: [], itemsSum: 0, gaps: [], gapsSum: 0, total: 0 };
        if (!REF) return out;
        items.forEach(function (it) {
            var e = estItem(it);
            if (e) { out.items.push({ it: it, est: e }); out.itemsSum += e; }
        });
        var n = daysCount(), t = tier(), g = meta().group, L = 0, M = 0, T = 0;
        items.forEach(function (it) {
            var k = kindOf(it);
            if (k === "lodging") L++; else if (k === "meal") M++; else if (k === "transport") T++;
        });
        if (n > 1 && !L) out.gaps.push({ label: "Lưu trú " + nightsCount() + " đêm × " + roomsNeeded() + " phòng", amount: REF.lodging[t] * roomsNeeded() * nightsCount() });
        var meals = Math.max(0, n * (REF.mealsPerDay || 3) - M);
        if (meals) out.gaps.push({ label: "Ăn uống chưa lên kế hoạch (" + meals + " bữa × " + g + " người)", amount: meals * REF.meal[t] * g });
        if (!T) out.gaps.push({ label: "Đi lại tại chỗ (" + n + " ngày)", amount: n * REF.transport[t] });
        out.gaps.forEach(function (x) { out.gapsSum += x.amount; });
        out.total = out.itemsSum + out.gapsSum;
        return out;
    }
    function dayStats(list) {
        var g = G.legs(list);
        return { km: g.km, minutes: g.minutes, legs: g.legs, cost: list.reduce(function (s, x) { return s + (x.cost || 0); }, 0) };
    }
    function liveWarnings() {
        var w = [], n = daysCount();
        for (var d = 1; d <= n; d++) {
            var list = dayItems(d);
            if (list.length > DAY_ITEMS_WARN) w.push("Ngày " + d + " có " + list.length + " mục, hơi dày.");
            var st = dayStats(list);
            if (st.minutes > DAY_TRAVEL_WARN_MIN) w.push("Ngày " + d + " di chuyển khoảng " + (Math.round(st.minutes / 6) / 10) + " giờ, nên bớt điểm hoặc chia sang ngày khác.");
        }
        var un = dayItems(null).length;
        if (un) w.push("Còn " + un + " mục chưa xếp vào ngày nào.");
        var m = meta(), cost = totalCost();
        if (m.budget != null && cost > m.budget) w.push("Chi phí dự kiến " + money(cost) + " vượt ngân sách " + money(m.budget) + ".");
        return w.concat(cfg.contentWarnings || []);
    }

    /* ---------------- vẽ: Lịch trình ---------------- */
    function slotSelect(it) {
        var s = el("select", "no-nice");
        s.setAttribute("aria-label", "Buổi của " + it.name);
        var o0 = el("option", null, "Buổi?");
        o0.value = "";
        s.appendChild(o0);
        Object.keys(SLOT).forEach(function (k) {
            var o = el("option", null, SLOT[k]);
            o.value = k;
            if (it.slot === k) o.selected = true;
            s.appendChild(o);
        });
        s.addEventListener("change", function () { it.slot = s.value || null; commit(true); });
        return s;
    }
    function daySelect(it) {
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Ngày của " + it.name);
        var o0 = el("option", null, "Chưa xếp");
        o0.value = "";
        sel.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d);
            o.value = String(d);
            if (it.day === d) o.selected = true;
            sel.appendChild(o);
        }
        sel.addEventListener("change", function () {
            moveItem(it, sel.value === "" ? null : +sel.value, null);
            commit();
        });
        return sel;
    }
    /** Đặt (hoặc bỏ nếu pos = null) vị trí cho một mục tự thêm. */
    function setPos(it, pos) {
        if (pos) { it.lat = pos.lat; it.lng = pos.lng; it.source = pos.source || "map"; it.url = G.mapsUrl(pos.lat, pos.lng); }
        else { it.lat = null; it.lng = null; it.source = null; it.url = null; }
        commit();
    }
    function posRow(it) {
        var row = el("div", "tp-pos");
        var has = G.hasPos(it);
        row.appendChild(el("span", "tp-pos__txt", has
            ? "Vị trí: " + it.lat + ", " + it.lng + " (" + (SRC[it.source] || "đã đặt") + ")"
            : "Chưa có vị trí nên chưa hiện trên bản đồ và chưa tính quãng đường."));
        var inp = el("input");
        inp.type = "text";
        inp.placeholder = "Dán link Google Maps hoặc tọa độ";
        inp.setAttribute("aria-label", "Đặt vị trí cho " + it.name);
        inp.addEventListener("change", function () {
            var v = inp.value.trim();
            if (!v) return;
            var p = G.parseMapsInput(v);
            if (!p) { say(G.isShortMapsLink(v) ? SHORT_MSG : BAD_POS_MSG, true); return; }
            p.source = "link";
            setPos(it, p);
            say("Đã đặt vị trí cho “" + it.name + "”. Chưa lưu.");
        });
        row.appendChild(inp);
        row.appendChild(btn("tp-mini", "Chọn trên bản đồ", "Chọn vị trí trên bản đồ cho " + it.name, function () { startPick(it.uid); }));
        if (has) row.appendChild(btn("tp-mini tp-mini--danger", "Bỏ vị trí", "Bỏ vị trí của " + it.name, function () { setPos(it, null); }));
        return row;
    }
    function itemRow(it, leg) {
        var li = el("li", "tp-item" + (CUSTOM[it.type] ? " tp-item--custom" : ""));
        li.setAttribute("data-uid", String(it.uid));

        var top = el("div", "tp-item__top");
        var h = el("span", "tp-handle", "⠿");
        h.setAttribute("title", "Kéo để đổi thứ tự hoặc chuyển ngày");
        h.setAttribute("aria-hidden", "true");
        h.draggable = true;
        h.addEventListener("dragstart", function (e) {
            ui.drag = it.uid;
            li.classList.add("is-drag");
            e.dataTransfer.effectAllowed = "move";
            try { e.dataTransfer.setData("text/plain", String(it.uid)); e.dataTransfer.setDragImage(li, 16, 16); } catch (x) { /* một số trình duyệt không hỗ trợ */ }
        });
        h.addEventListener("dragend", clearDrag);
        if (!RO) top.appendChild(h);

        var cb = el("input");
        cb.type = "checkbox";
        cb.checked = !!ui.sel[it.uid];
        cb.setAttribute("aria-label", "Chọn " + it.name);
        cb.addEventListener("change", function () {
            if (cb.checked) ui.sel[it.uid] = true; else delete ui.sel[it.uid];
            renderBulk();
        });
        if (!RO) top.appendChild(cb);
        var nb = numberOf(it);
        var badge = el("button", "tp-numbadge" + (G.hasPos(it) ? " is-link" : ""), nb === "" ? "•" : String(nb));
        badge.type = "button";
        badge.style.background = it.day == null ? "#6b7280" : COLORS[(it.day - 1) % COLORS.length];
        badge.setAttribute("aria-label", "Số " + (nb === "" ? "chưa xếp" : nb) + ": xem " + it.name + " trên bản đồ");
        badge.addEventListener("click", function () { showOnMap(it.uid); });
        top.appendChild(badge);

        var main = el("div", "tp-item__main");
        main.appendChild(el("span", "tp-type", TYPE[it.type] || ""));
        var href = safeUrl(it.url);
        if (href) {
            var a = el("a", "tp-item__name", it.name);
            a.href = href;
            a.target = "_blank";
            a.rel = "noopener";
            main.appendChild(a);
        } else {
            main.appendChild(el("span", "tp-item__name", it.name));
        }
        var sub = [it.sub, it.when ? "diễn ra " + it.when : ""].filter(Boolean).join(" · ");
        if (sub) main.appendChild(el("small", "tp-item__sub", sub));
        if (CUSTOM[it.type]) {
            var hp = G.hasPos(it);
            main.appendChild(el("span", "tp-badge" + (hp ? " is-ok" : ""), hp ? "Có vị trí" : "Chưa có vị trí"));
        }
        top.appendChild(main);
        if (it.image) {
            var ph = el("img", "tp-item__photo");
            ph.src = it.image; ph.alt = ""; ph.loading = "lazy";
            ph.addEventListener("click", function () { showOnMap(it.uid); });
            top.appendChild(ph);
        }
        var right = el("div", "tp-item__right");
        right.appendChild(costInput(it));
        if (G.hasPos(it)) right.appendChild(btn("tp-mini", "Bản đồ", "Xem " + it.name + " trên bản đồ", function () { showOnMap(it.uid); }));
        var open = !!ui.open[it.uid];
        var tg = btn("tp-mini tp-item__toggle", open ? "Thu gọn" : "Sửa", "Sửa chi tiết " + it.name, function () {
            ui.open[it.uid] = !ui.open[it.uid];
            renderDays();
        });
        tg.setAttribute("aria-expanded", open ? "true" : "false");
        if (!RO) right.appendChild(tg);
        top.appendChild(right);
        li.addEventListener("mouseenter", function () { if (map) map.highlight(it.uid); });
        li.addEventListener("mouseleave", function () { if (map && !(ui.card && ui.card.uid === it.uid)) map.highlight(null); });
        li.appendChild(top);
        if (it.reasons && it.reasons.length) li.appendChild(el("p", "tp-why", "Vì sao chọn: " + it.reasons.join("; ")));
        if (it.fit && it.fit.length) {
            var fl = el("ul", "tp-fit");
            it.fit.forEach(function (f) { fl.appendChild(el("li", "tp-fit__i tp-fit--" + (f.k === "good" || f.k === "warn" ? f.k : "info"), f.x)); });
            li.appendChild(fl);
        }
        if (it.src) li.appendChild(el("small", "tp-src", it.src));
        else if (CUSTOM[it.type] && it.source === "search") li.appendChild(el("small", "tp-src", "Ngoài dữ liệu Travela, chỉ chi phí tham khảo chung"));
        if (it.note && !open) li.appendChild(el("p", "tp-item__notepreview", it.note));

        if (open && !RO) {
            var panel = el("div", "tp-item__panel");
            var ctl = el("div", "tp-item__ctl");
            ctl.appendChild(daySelect(it));
            ctl.appendChild(slotSelect(it));
            ctl.appendChild(btn("tp-mini", "↑", "Lên: " + it.name, function () { shift(it, -1); }));
            ctl.appendChild(btn("tp-mini", "↓", "Xuống: " + it.name, function () { shift(it, 1); }));
            ctl.appendChild(btn("tp-mini tp-mini--danger", "Bỏ khỏi chuyến", "Bỏ " + it.name + " khỏi chuyến đi", function () {
                items.splice(items.indexOf(it), 1);
                delete ui.sel[it.uid];
                delete ui.open[it.uid];
                commit();
            }));
            panel.appendChild(ctl);
            var note = el("input");
            note.type = "text";
            note.maxLength = 255;
            note.placeholder = "Ghi chú (giờ hẹn, mã đặt chỗ...)";
            note.value = it.note;
            note.setAttribute("aria-label", "Ghi chú cho " + it.name);
            note.addEventListener("input", function () { it.note = note.value; });
            note.addEventListener("change", function () { commit(true); });
            panel.appendChild(note);
            if (CUSTOM[it.type]) panel.appendChild(posRow(it));
            li.appendChild(panel);
        }

        if (leg) li.appendChild(el("p", "tp-leg", "↓ " + leg.km + " km · " + leg.min + " phút đến " + leg.to.name));

        // thả lên một mục: chèn trước/sau tùy nửa trên/dưới
        li.addEventListener("dragover", function (e) {
            if (ui.drag == null || ui.drag === it.uid) return;
            e.preventDefault();
            var r = li.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2;
            li.classList.toggle("drop-before", !after);
            li.classList.toggle("drop-after", after);
        });
        li.addEventListener("dragleave", function () { li.classList.remove("drop-before", "drop-after"); });
        li.addEventListener("drop", function (e) {
            if (ui.drag == null) return;
            e.preventDefault();
            e.stopPropagation();
            var r = li.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2;
            var dragged = byUid(ui.drag);
            clearDrag();
            if (dragged) dropRelative(dragged, it, after);
        });
        return li;
    }
    function costInput(it) {
        if (RO) return el("span", "tp-cost-ro", it.cost == null ? "" : money(it.cost));
        var cost = el("input");
        cost.type = "number";
        cost.min = "0";
        cost.step = "1000";
        var est = estItem(it);
        cost.placeholder = est ? "~" + Math.round(est).toLocaleString("vi-VN") + " (ước tính)" : "Chi phí (đ)";
        cost.value = it.cost == null ? "" : String(it.cost);
        cost.setAttribute("aria-label", "Chi phí của " + it.name);
        cost.addEventListener("input", function () { it.cost = cost.value === "" ? null : Math.max(0, +cost.value); softUpdate(); });
        cost.addEventListener("change", function () { commit(true); });
        return cost;
    }
    function byUid(u) {
        for (var i = 0; i < items.length; i++) if (items[i].uid === u) return items[i];
        return null;
    }
    function clearDrag() {
        ui.drag = null;
        document.querySelectorAll(".is-drag,.drop-before,.drop-after,.drop-zone").forEach(function (e) { e.classList.remove("is-drag", "drop-before", "drop-after", "drop-zone"); });
    }
    /** Đưa mục sang ngày `day`, đứng trước mục `before` (null = cuối ngày). Thứ tự trong mảng = thứ tự trong ngày. */
    function moveItem(it, day, before) {
        if (it === before) return;
        items.splice(items.indexOf(it), 1);
        it.day = day;
        if (before) items.splice(items.indexOf(before), 0, it); else items.push(it);
    }
    function dropRelative(it, target, after) {
        if (it === target) return;
        var grp = items.filter(function (x) { return x.day === target.day && x !== it; });
        var i = grp.indexOf(target);
        moveItem(it, target.day, after ? (grp[i + 1] || null) : target);
        commit();
    }
    function shift(it, dir) {
        var group = items.filter(function (x) { return x.day === it.day; });
        var i = group.indexOf(it), j = i + dir;
        if (j < 0 || j >= group.length) return;
        var a = items.indexOf(it), b = items.indexOf(group[j]);
        items[a] = group[j];
        items[b] = it;
        commit();
    }

    function dayHeader(d, list, st) {
        var h = el("div", "tp-dayhead");
        var title = el("h3", null, d === null ? "Chưa xếp ngày" : dayLabel(d));
        if (d !== null) title.style.borderLeftColor = COLORS[(d - 1) % COLORS.length];
        h.appendChild(title);
        var bits = [list.length + " mục"];
        if (st.km) bits.push("~" + st.km + " km, " + dur(st.minutes));
        if (st.cost) bits.push(money(st.cost));
        h.appendChild(el("span", "tp-daystat", bits.join(" · ")));
        var act = el("div", "tp-dayact");
        if (RO) { h.appendChild(act); return h; }
        if (d !== null) act.appendChild(btn("tp-mini", "+ Thêm", "Thêm mục vào " + dayLabel(d), function () { ui.target = d; renderTarget(); switchTab("plan"); focusAdd(); }));
        if (list.length) act.appendChild(btn("tp-mini", "Chọn hết", "Chọn tất cả mục " + (d === null ? "chưa xếp" : dayLabel(d)), function () {
            list.forEach(function (x) { ui.sel[x.uid] = true; });
            renderDays();
            renderBulk();
        }));
        if (d !== null && list.filter(G.hasPos).length >= 3) act.appendChild(btn("tp-mini", "Tối ưu tuyến", "Tối ưu tuyến " + dayLabel(d), function () { optimizeDay(d); }));
        h.appendChild(act);
        return h;
    }
    /* ---------------- Phương án khi mưa ---------------- */
    function rainKey(r) { return r.type + ":" + r.refId; }
    function rainPanel(d) {
        var det = el("details", "tp-rain");
        var list = rain[String(d)] || [];
        var sum = el("summary", null, "Nếu trời mưa" + (list.length ? " (" + list.length + ")" : ""));
        det.appendChild(sum);
        if (ui.rainOpen && ui.rainOpen[d]) det.open = true;
        det.addEventListener("toggle", function () { ui.rainOpen = ui.rainOpen || {}; ui.rainOpen[d] = det.open; });
        det.appendChild(el("p", "tp-muted tp-small", "Chọn vài mục trong nhà để thay thế khi mưa. Không tính vào chi phí chính."));
        var ul = el("ul", "tp-rain__list");
        list.forEach(function (r) {
            var li = el("li", "tp-rain__item");
            var href = safeUrl(r.url);
            if (href) { var a = el("a", null, r.name); a.href = href; a.target = "_blank"; a.rel = "noopener"; li.appendChild(a); } else li.appendChild(el("span", null, r.name));
            if (r.sub) li.appendChild(el("small", "tp-muted", " · " + r.sub));
            li.appendChild(btn("tp-mini tp-mini--danger", "Bỏ", "Bỏ " + r.name + " khỏi phương án mưa ngày " + d, function () {
                rain[String(d)] = (rain[String(d)] || []).filter(function (x) { return rainKey(x) !== rainKey(r); });
                if (!rain[String(d)].length) delete rain[String(d)];
                commit();
            }));
            ul.appendChild(li);
        });
        det.appendChild(ul);
        var box = el("div", "tp-rain__sugg");
        var b = btn("tp-mini", "Gợi ý mục trong nhà", "Gợi ý mục trong nhà cho ngày " + d, function () { loadRain(d, box, b); });
        det.appendChild(b);
        det.appendChild(box);
        return det;
    }
    function loadRain(d, box, b) {
        if (!cfg.rainSuggestUrl) return;
        if ((rain[String(d)] || []).length >= RAIN_MAX) { box.textContent = "Đã đủ " + RAIN_MAX + " mục cho ngày này."; return; }
        b.disabled = true;
        box.textContent = "Đang tìm…";
        var have = (rain[String(d)] || []).map(rainKey);
        var q = "?day=" + d + have.map(function (k) { return "&have[]=" + encodeURIComponent(k); }).join("");
        fetch(cfg.rainSuggestUrl + q, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (j) {
                box.textContent = "";
                if (!j.items || !j.items.length) { box.appendChild(el("p", "tp-muted tp-small", "Chưa có mục trong nhà đã duyệt cho nơi này. Bạn có thể thêm mục riêng ở ô tìm kiếm.")); return; }
                var ul = el("ul", "tp-rain__list");
                j.items.forEach(function (x) {
                    var li = el("li", "tp-rain__item");
                    li.appendChild(el("strong", null, x.name));
                    if (x.sub) li.appendChild(el("small", "tp-muted", " · " + x.sub));
                    li.appendChild(el("small", "tp-rain__why", (x.reasons || []).join("; ")));
                    li.appendChild(btn("tp-mini", "Chọn", "Chọn " + x.name + " làm phương án mưa ngày " + d, function () {
                        var cur = rain[String(d)] = rain[String(d)] || [];
                        if (cur.length >= RAIN_MAX || cur.some(function (r) { return rainKey(r) === x.type + ":" + x.refId; })) return;
                        cur.push({ type: x.type, refId: x.refId, name: x.name, sub: x.sub || "", url: x.url || null, lat: x.lat == null ? null : x.lat, lng: x.lng == null ? null : x.lng });
                        commit();
                    }));
                    ul.appendChild(li);
                });
                box.appendChild(ul);
            })
            .catch(function () { box.textContent = "Chưa tải được gợi ý, thử lại sau."; })
            .then(function () { b.disabled = false; });
    }
    /** Danh sách ngày ở thanh bên trái: bấm để nhảy tới ngày đó (giống Wanderlog). */
    function renderDayNav() {
        var nav = $("tpDayNav");
        if (!nav) return;
        nav.textContent = "";
        for (var d = 1; d <= daysCount(); d++) {
            (function (day) {
                var li = el("li");
                var list = dayItems(day);
                var b = btn("tp-daynav__b", "", dayLabel(day) + ", " + list.length + " mục", function () {
                    switchTab("plan");
                    var sec = document.getElementById("tpDay" + day);
                    if (sec) { sec.scrollIntoView({ block: "start", behavior: "smooth" }); ui.mapDay = String(day); $("tpMapDay").value = String(day); renderMap(); }
                });
                var dot = el("span", "tp-daynav__dot");
                dot.style.background = COLORS[(day - 1) % COLORS.length];
                b.appendChild(dot);
                b.appendChild(el("span", "tp-daynav__t", dayLabel(day)));
                b.appendChild(el("small", null, list.length ? String(list.length) : ""));
                li.appendChild(b);
                nav.appendChild(li);
            })(d);
        }
    }
    function renderDays() {
        box.textContent = "";
        var n = daysCount();
        var any = items.length > 0;
        for (var d = 1; d <= n + 1; d++) {
            var day = d <= n ? d : null;
            var list = dayItems(day);
            if (day === null && !list.length) continue;
            var sec = el("section", "tp-day");
            if (day !== null) sec.id = "tpDay" + day;
            var st = dayStats(list);
            sec.appendChild(dayHeader(day, list, st));
            var legOf = {};
            st.legs.forEach(function (l) { legOf[l.from.uid] = l; });
            var ul = el("ul", "tp-items");
            ul.setAttribute("data-day", day === null ? "" : String(day));
            if (!list.length) {
                var empty = el("li", "tp-empty", "Chưa có mục nào. Bấm “+ Thêm” rồi tìm địa điểm, hoặc kéo một mục từ ngày khác vào đây.");
                ul.appendChild(empty);
            } else {
                list.forEach(function (it) { ul.appendChild(itemRow(it, legOf[it.uid])); });
            }
            (function (dayNo, zone) {
                zone.addEventListener("dragover", function (e) {
                    if (ui.drag == null) return;
                    e.preventDefault();
                    zone.classList.add("drop-zone");
                });
                zone.addEventListener("dragleave", function (e) { if (e.target === zone) zone.classList.remove("drop-zone"); });
                zone.addEventListener("drop", function (e) {
                    if (ui.drag == null) return;
                    e.preventDefault();
                    var dragged = byUid(ui.drag);
                    clearDrag();
                    if (dragged) { moveItem(dragged, dayNo, null); commit(); }
                });
            })(day, ul);
            sec.appendChild(ul);
            if (day !== null && cfg.rainSuggestUrl && !RO) sec.appendChild(rainPanel(day));
            box.appendChild(sec);
        }
        renderDayNav();
        if (!any) box.insertBefore(el("p", "tp-muted", "Chuyến đi chưa có mục nào. Gõ tên địa điểm, quán ăn hoặc khách sạn vào ô tìm ở trên để thêm vào lịch."), box.firstChild);
    }
    function renderTotals() {
        var sum = totalCost(), b = meta().budget, t = $("tpTotals");
        var s = "Chi phí đã nhập: " + money(sum);
        if (b != null) s += " / ngân sách " + money(b) + (sum > b ? " (vượt " + money(sum - b) + ")" : "");
        t.textContent = s;
        t.classList.toggle("tp-totals--over", b != null && sum > b);
    }

    /* ---------------- chọn nhiều mục ---------------- */
    function selected() { return items.filter(function (x) { return ui.sel[x.uid]; }); }
    function renderBulk() {
        var b = $("tpBulk"), list = selected();
        b.textContent = "";
        b.hidden = !list.length;
        if (!list.length) return;
        b.appendChild(el("strong", null, "Đã chọn " + list.length + " mục"));
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Chuyển các mục đã chọn sang");
        var o0 = el("option", null, "Chưa xếp ngày");
        o0.value = "";
        sel.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d);
            o.value = String(d);
            sel.appendChild(o);
        }
        b.appendChild(sel);
        b.appendChild(btn("tp-mini", "Chuyển", "Chuyển các mục đã chọn", function () {
            var target = sel.value === "" ? null : +sel.value;
            selected().forEach(function (x) { moveItem(x, target, null); });
            ui.sel = {};
            commit();
        }));
        b.appendChild(btn("tp-mini tp-mini--danger", "Xóa", "Xóa các mục đã chọn", function () {
            var rm = selected();
            if (!window.confirm("Xóa " + rm.length + " mục đã chọn khỏi chuyến đi?")) return;
            items = items.filter(function (x) { return !ui.sel[x.uid]; });
            ui.sel = {};
            commit();
        }));
        b.appendChild(btn("tp-mini", "Bỏ chọn", "Bỏ chọn tất cả", function () { ui.sel = {}; renderDays(); renderBulk(); }));
    }

    /* ---------------- tối ưu tuyến ---------------- */
    function routeNote(text, canUndo) {
        var n = $("tpRouteNote");
        n.textContent = "";
        n.hidden = !text;
        if (!text) return;
        n.appendChild(el("span", null, text));
        if (canUndo) n.appendChild(btn("tp-mini", "Hoàn tác", "Hoàn tác tối ưu tuyến", function () { undoOnce(); routeNote("Đã hoàn tác.", false); }));
        n.appendChild(btn("tp-mini", "Đóng", "Đóng thông báo", function () { routeNote("", false); }));
    }
    function optimizeDay(d) {
        var group = dayItems(d), pos = group.filter(G.hasPos);
        if (pos.length < 3) { routeNote("Cần ít nhất 3 mục có vị trí trong ngày để tối ưu.", false); return; }
        var keepFirst = $("tpKeepFirst") ? $("tpKeepFirst").checked : true;
        var before = G.roadKmExact(pos);
        var order = G.optimize(pos, keepFirst);
        var np = order.map(function (i) { return pos[i]; });
        var after = G.roadKmExact(np);
        if (before - after < 0.2) { routeNote(dayLabel(d) + ": thứ tự hiện tại đã gần tối ưu.", false); return; }
        var k = 0, newGroup = group.map(function (x) { return G.hasPos(x) ? np[k++] : x; });
        var slots = [];
        items.forEach(function (x, i) { if (x.day === d) slots.push(i); });
        slots.forEach(function (p, j) { items[p] = newGroup[j]; });
        commit();
        routeNote(dayLabel(d) + ": đã sắp lại, tiết kiệm khoảng " + Math.round(before - after) + " km (~" + Math.round((before - after) / G.SPEED_KMH * 60) + " phút), ước tính.", true);
    }

    /* ---------------- vẽ: Tổng quan, cảnh báo, chi phí ---------------- */
    function renderStats() {
        var s = $("tpStats");
        s.textContent = "";
        var m = meta(), n = items.length;
        var tours = items.filter(function (x) { return x.type === "tour"; }).length;
        var custom = items.filter(function (x) { return CUSTOM[x.type]; }).length;
        var un = dayItems(null).length;
        [["Số ngày", String(m.days)], ["Số mục", String(n)], ["Tour", String(tours)], ["Mục tự thêm", String(custom)], ["Chưa xếp", String(un)], ["Chi phí", money(totalCost())]].forEach(function (c) {
            var card = el("div", "tp-stat");
            card.appendChild(el("b", null, c[1]));
            card.appendChild(el("span", null, c[0]));
            s.appendChild(card);
        });
    }
    function renderWarn() {
        var w = liveWarnings(), box2 = $("tpWarn");
        box2.textContent = "";
        if (w.length) {
            var ul = el("ul", "tp-warn");
            w.forEach(function (x) { ul.appendChild(el("li", null, x)); });
            box2.appendChild(ul);
        } else {
            box2.appendChild(el("p", "tp-ok", "Chưa thấy vấn đề gì với lịch hiện tại."));
        }
        box2.appendChild(el("p", "tp-muted tp-small", cfg.estimateNote || ""));
    }
    function renderSummary() {
        var s = $("tpSummary");
        s.textContent = "";
        var adv = cfg.advice || [];
        if (!adv.length) return;
        s.appendChild(el("h3", null, "Thời điểm đi"));
        var ul = el("ul", "tp-list");
        adv.forEach(function (a) { ul.appendChild(el("li", null, a.dest + ", tháng " + a.month + ": " + a.label + (a.note ? " (" + a.note + ")" : ""))); });
        s.appendChild(ul);
    }
    function renderCostSummary() {
        var c = $("tpCostSum");
        if (!c) return;
        c.textContent = "";
        var m = meta(), sum = totalCost(), es = estimates(), all = sum + es.total;
        var grid = el("div", "tp-costgrid");
        function cell(label, val, cls) {
            var d = el("div", "tp-costcell" + (cls ? " " + cls : ""));
            d.appendChild(el("span", null, label));
            d.appendChild(el("b", null, val));
            grid.appendChild(d);
        }
        cell("Đã nhập", money(sum));
        if (es.total) cell("Ước tính thêm", "~" + money(es.total), "is-est");
        cell("Tổng dự kiến", money(all) + (es.total ? " *" : ""), "is-total");
        cell("Bình quân/người", money(all / m.group));
        c.appendChild(grid);
        if (m.budget != null && m.budget > 0) {
            var pct = Math.min(100, Math.round(all / m.budget * 100));
            var bar = el("div", "tp-bar-track");
            var fill = el("div", "tp-bar-fill" + (all > m.budget ? " is-over" : ""));
            fill.style.width = pct + "%";
            bar.appendChild(fill);
            bar.setAttribute("role", "img");
            bar.setAttribute("aria-label", "Dự kiến dùng " + pct + "% ngân sách");
            c.appendChild(bar);
            c.appendChild(el("p", all > m.budget ? "tp-totals tp-totals--over" : "tp-muted", all > m.budget ? "Vượt ngân sách " + money(all - m.budget) + " (ngân sách " + money(m.budget) + ")" : "Còn lại " + money(m.budget - all) + " trong ngân sách " + money(m.budget)));
        } else {
            c.appendChild(el("p", "tp-muted", "Nhập ngân sách ở tab Thông tin để so sánh."));
        }
        if (es.total) c.appendChild(el("p", "tp-muted tp-small", "* Gồm khoản ước tính theo giá tham khảo, không phải giá thật. Nhập giá thật vào từng mục để chính xác hơn."));
    }
    function renderEstimate(b) {
        if (!REF) return;
        var es = estimates();
        b.appendChild(el("h3", null, "Dự toán tham khảo"));
        var row = el("div", "tp-tierrow");
        var lab = el("label", null, "Mức chi tiêu ");
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Mức chi tiêu để ước tính");
        ["low", "mid", "high"].forEach(function (k) {
            var o = el("option", null, TIER_LABEL[k]);
            o.value = k;
            if (k === tier()) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener("change", function () { ui.tier = sel.value; renderDays(); renderCostTable(); softUpdate(); });
        lab.appendChild(sel);
        row.appendChild(lab);
        if (es.items.length) {
            row.appendChild(btn("tp-mini", "Điền giá ước tính vào " + es.items.length + " mục chưa có giá", "Điền giá ước tính vào các mục chưa có giá", function () {
                es.items.forEach(function (x) { x.it.cost = Math.round(x.est / 1000) * 1000; });
                commit();
            }));
        }
        b.appendChild(row);
        if (!es.items.length && !es.gaps.length) { b.appendChild(el("p", "tp-muted tp-small", "Mọi khoản chính đã có giá.")); return; }
        var t = el("table", "tp-table");
        es.items.forEach(function (x) {
            var tr = el("tr");
            tr.appendChild(el("td", null, x.it.name));
            tr.appendChild(el("td", "tp-num", "~" + money(x.est)));
            t.appendChild(tr);
        });
        es.gaps.forEach(function (x) {
            var tr = el("tr", "tp-gap");
            tr.appendChild(el("td", null, x.label));
            tr.appendChild(el("td", "tp-num", "~" + money(x.amount)));
            t.appendChild(tr);
        });
        b.appendChild(t);
        b.appendChild(el("p", "tp-muted tp-small", "Giá tham khảo theo mức “" + TIER_LABEL[tier()] + "” do nhóm đặt sẵn (ăn uống, trải nghiệm tính theo số người; lưu trú theo số phòng và số đêm). Không phải giá thị trường chính thức."));
    }
    function renderCostTable() {
        var b = $("tpCostBox");
        b.textContent = "";
        b.appendChild(el("div", null)).id = "tpCostSum";
        b.appendChild(el("p", "tp-muted tp-small", "Giá tour đã nhân với số người. Các mục khác bạn nhập ở ô Chi phí; mục chưa nhập sẽ được ước tính theo giá tham khảo bên dưới."));
        renderEstimate(b);
        if (!items.length) { renderCostSummary(); return; }

        var byType = {}, byDay = {};
        items.forEach(function (x) {
            var t = byType[x.type] || (byType[x.type] = { n: 0, sum: 0 });
            t.n++; t.sum += x.cost || 0;
            var dk = x.day == null ? "x" : String(x.day);
            byDay[dk] = (byDay[dk] || 0) + (x.cost || 0);
        });
        b.appendChild(el("h3", null, "Theo loại (đã nhập)"));
        var t1 = el("table", "tp-table");
        Object.keys(byType).forEach(function (k) {
            var tr = el("tr");
            tr.appendChild(el("td", null, TYPE[k] || k));
            tr.appendChild(el("td", null, byType[k].n + " mục"));
            tr.appendChild(el("td", "tp-num", money(byType[k].sum)));
            t1.appendChild(tr);
        });
        b.appendChild(t1);

        b.appendChild(el("h3", null, "Theo ngày (đã nhập)"));
        var t2 = el("table", "tp-table");
        for (var d = 1; d <= daysCount(); d++) {
            var tr2 = el("tr");
            tr2.appendChild(el("td", null, dayLabel(d)));
            tr2.appendChild(el("td", "tp-num", money(byDay[String(d)] || 0)));
            t2.appendChild(tr2);
        }
        if (byDay.x) {
            var tr3 = el("tr");
            tr3.appendChild(el("td", null, "Chưa xếp ngày"));
            tr3.appendChild(el("td", "tp-num", money(byDay.x)));
            t2.appendChild(tr3);
        }
        b.appendChild(t2);

        b.appendChild(el("h3", null, "Từng mục"));
        var t3 = el("table", "tp-table tp-table--items");
        var ordered = [];
        for (var dd = 1; dd <= daysCount(); dd++) ordered = ordered.concat(dayItems(dd));
        ordered = ordered.concat(dayItems(null));
        ordered.forEach(function (it) {
            var tr4 = el("tr");
            tr4.appendChild(el("td", null, it.day == null ? "—" : "N" + it.day));
            tr4.appendChild(el("td", null, it.name));
            tr4.appendChild(el("td", null, TYPE[it.type] || ""));
            var td = el("td", "tp-num");
            td.appendChild(costInput(it));
            tr4.appendChild(td);
            t3.appendChild(tr4);
        });
        b.appendChild(t3);
        renderCostSummary();
    }

    /* ---------------- Thêm vào chuyến: một ô tìm duy nhất ---------------- */
    var SEARCH_CHIPS = [["Khách sạn", "khách sạn"], ["Nhà hàng", "nhà hàng"], ["Quán cà phê", "quán cà phê"], ["Chợ", "chợ"]];
    var ADD_CHIPS = [["Taxi / Grab", "transport"], ["Vé máy bay / tàu / xe", "transport"]];

    function newItem(o) {
        return {
            uid: ++seq, type: o.type, refId: o.refId == null ? null : o.refId, name: o.name, sub: o.sub || "",
            url: o.url || (CUSTOM[o.type] && o.lat != null && o.lng != null ? G.mapsUrl(o.lat, o.lng) : null),
            day: o.day == null ? null : o.day, note: o.note || "", cost: o.cost == null ? null : +o.cost, slot: null, reasons: [],
            lat: o.lat == null ? null : o.lat, lng: o.lng == null ? null : o.lng, when: null,
            source: CUSTOM[o.type] && o.lat != null ? (o.source || "map") : null,
        };
    }
    function addItem(o, label) {
        if (items.length >= LIMITS.maxItems) { say("Chuyến đi đã đạt tối đa " + LIMITS.maxItems + " mục.", true); return false; }
        if (!CUSTOM[o.type] && haveMap()[o.type + ":" + o.refId]) return false;
        items.push(newItem(o));
        commit();
        say("Đã thêm “" + (label || o.name) + "” vào " + targetLabel(o.day) + ". Chưa lưu.");
        return true;
    }
    function renderTarget() {
        var s = $("tpTarget");
        s.textContent = "";
        var o0 = el("option", null, "Chưa xếp ngày");
        o0.value = "";
        s.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, dayLabel(d));
            o.value = String(d);
            s.appendChild(o);
        }
        if (ui.target != null && ui.target > daysCount()) ui.target = daysCount();
        s.value = ui.target == null ? "" : String(ui.target);
    }
    function resultList(list, emptyText) {
        var wrap = el("div");
        if (!list.length) { wrap.appendChild(el("p", "tp-muted", emptyText)); return wrap; }
        var have = haveMap();
        var ul = el("ul", "tp-list tp-sugg");
        list.forEach(function (s) {
            var li = el("li");
            var href = safeUrl(s.url);
            if (href) {
                var a = el("a", null, s.name);
                a.href = href;
                a.target = "_blank";
                a.rel = "noopener";
                li.appendChild(a);
            } else {
                li.appendChild(el("span", null, s.name));
            }
            li.appendChild(el("span", "tp-type", TYPE[s.type] || ""));
            var added = !!have[s.type + ":" + s.refId];
            var add = btn("tp-mini", added ? "Đã có" : "+ Thêm", (added ? "Đã có " : "Thêm ") + s.name + " vào chuyến đi", function () {
                if (addItem({ type: s.type, refId: s.refId, name: s.name, sub: s.sub, url: s.url, cost: s.cost, lat: s.lat, lng: s.lng, day: ui.target })) { renderResults(); renderSugg(); }
            });
            add.disabled = added;
            li.appendChild(add);
            var sub = [s.sub, s.destName].filter(Boolean).join(" · ");
            if (sub) li.appendChild(el("small", null, sub));
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }
    /** Các địa điểm đã thêm từ tìm kiếm trên bản đồ (để nút hiện "Đã thêm"). */
    function haveExtMap() {
        var h = {};
        items.forEach(function (x) { if (CUSTOM[x.type] && x.source === "search" && G.hasPos(x)) h[x.lat + "," + x.lng] = true; });
        return h;
    }
    /** Đặt ghim xem trước (vàng) trên bản đồ khi rê chuột vào một kết quả. */
    function previewPin(lat, lng, name) {
        if (!map) return;
        map.preview(lat, lng, name);
        map.view(lat, lng, Math.max(map.zoom(), 14));
    }
    function extList(list) {
        var have = haveExtMap();
        var ul = el("ul", "tp-list tp-sugg tp-ext");
        list.forEach(function (r) {
            var li = el("li");
            var main = el("span", "tp-ext__main");
            main.appendChild(el("b", null, r.name));
            if (r.kind) main.appendChild(el("span", "tp-type", r.kind));
            li.appendChild(main);
            var added = !!have[r.key];
            var add = btn("tp-mini", added ? "Đã thêm" : "+ Thêm", (added ? "Đã thêm " : "Thêm ") + r.name + " vào chuyến đi", function () {
                addItem({ type: "note", refId: null, name: r.name, note: r.address || "", lat: r.lat, lng: r.lng, source: "search", sub: r.kind || "", day: ui.target });
            });
            add.disabled = added;
            li.appendChild(add);
            if (r.address) li.appendChild(el("small", null, r.address));
            li.addEventListener("mouseenter", function () { previewPin(r.lat, r.lng, r.name); });
            li.addEventListener("mouseleave", function () { renderDraft(); });
            ul.appendChild(li);
        });
        return ul;
    }
    function group(title, body) {
        var d = el("div", "tp-res-group");
        d.appendChild(el("h4", null, title));
        d.appendChild(body);
        return d;
    }
    function openManual(q) {
        var m = $("tpManual");
        m.open = true;
        if (q != null) $("tpCustomLabel").value = q;
        try { m.scrollIntoView({ block: "center", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
        $("tpCustomLabel").focus();
    }
    function renderResults() {
        var r = $("tpResults"), s = ui.res;
        if (ui.br && ui.br.built) renderBrowseList();
        r.textContent = "";
        r.hidden = !s;
        if (!s) return;
        r.appendChild(group("Có trên Travela", s.kho === null ? el("p", "tp-muted", "Đang tìm…") : resultList(s.kho, "Chưa có nội dung phù hợp trên Travela.")));
        if (cfg.searchExtUrl) {
            var body;
            if (s.ext === null) body = el("p", "tp-muted", "Đang tìm trên bản đồ…");
            else if (!s.ext.ok) body = el("p", "tp-muted", "Chưa tìm được trên bản đồ lúc này. Bạn vẫn thêm được bằng tên ở bên dưới.");
            else if (!s.ext.list.length) body = el("p", "tp-muted", "Không thấy địa điểm nào trên bản đồ. Thử viết có dấu, thêm tên thành phố hoặc dùng tên khác.");
            else body = extList(s.ext.list);
            r.appendChild(group("Địa điểm trên bản đồ (OpenStreetMap)", body));
        }
        var foot = el("div", "tp-res-foot");
        foot.appendChild(el("span", "tp-muted tp-small", "Không đúng ý?"));
        foot.appendChild(btn("tp-mini", "+ Thêm “" + s.q + "” như một ghi chú", "Thêm " + s.q + " như một ghi chú, chưa có vị trí", function () { addItem({ type: "note", refId: null, name: s.q, day: ui.target }); }));
        foot.appendChild(btn("tp-mini", "Tự nhập chi phí, vị trí...", "Mở biểu mẫu tự nhập", function () { openManual(s.q); }));
        r.appendChild(foot);
    }
    function fetchJson(url) {
        return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
    }
    /** Điểm làm trung tâm khi tìm trên bản đồ: điểm đến chính, không có thì trung bình các mục đã có vị trí. */
    function biasCenter() {
        var d = meta().dest;
        if (d) {
            var x = (cfg.dests || []).filter(function (z) { return z.id === d; })[0];
            if (x && x.lat != null && x.lng != null) return { lat: x.lat, lng: x.lng };
        }
        var pts = items.filter(G.hasPos);
        if (!pts.length) return null;
        var la = 0, ln = 0;
        pts.forEach(function (p) { la += p.lat; ln += p.lng; });
        return { lat: Math.round(la / pts.length * 1e4) / 1e4, lng: Math.round(ln / pts.length * 1e4) / 1e4 };
    }
    function runNameSearch(q, mine) {
        var enc = encodeURIComponent(q);
        ui.res = { q: q, kho: null, ext: cfg.searchExtUrl ? null : { ok: true, list: [] } };
        renderResults();
        var main = meta().dest;
        fetchJson(cfg.searchUrl + "?q=" + enc + (main && $("tpDestOnly").checked ? "&dest=" + main : ""))
            .then(function (j) { if (mine === ui.searchSeq) { ui.res.kho = j.results || []; renderResults(); } })
            .catch(function () { if (mine === ui.searchSeq) { ui.res.kho = []; renderResults(); } });
        if (cfg.searchExtUrl) {
            var c = biasCenter();
            fetchJson(cfg.searchExtUrl + "?q=" + enc + (c ? "&lat=" + c.lat + "&lng=" + c.lng : ""))
                .then(function (j) { if (mine === ui.searchSeq) { ui.res.ext = { ok: j.ok !== false, list: j.results || [] }; renderResults(); } })
                .catch(function () { if (mine === ui.searchSeq) { ui.res.ext = { ok: false, list: [] }; renderResults(); } });
        }
    }
    function runSearch() {
        var q = $("tpQ").value.trim();
        if (q.length < 2) { ui.searchSeq++; ui.res = null; ui.br = null; renderResults(); renderBrowse(); return; }
        var mine = ++ui.searchSeq;
        if (!cfg.browseUrl) { ui.br = null; renderBrowse(); runNameSearch(q, mine); return; }
        // Hiểu câu tìm trước: "nhà hàng chay giá rẻ" -> tìm theo tiêu chí; "Hồ Gươm", "phở Thìn" -> tìm theo tên như cũ
        fetchJson(cfg.browseUrl + "?q=" + encodeURIComponent(q) + brPos())
            .then(function (j) {
                if (mine !== ui.searchSeq) return;
                if (j && j.browse && j.filters) {
                    ui.res = null;
                    ui.br = { q: q, intent: j.intent || {}, f: brFromServer(j.filters), data: j.browse, loading: false, seq: 0, built: false };
                    renderResults();
                    renderBrowse();
                } else {
                    ui.br = null;
                    renderBrowse();
                    runNameSearch(q, mine);
                }
            })
            .catch(function () { if (mine === ui.searchSeq) { ui.br = null; renderBrowse(); runNameSearch(q, mine); } });
    }

    /* ---------------- tìm theo tiêu chí (loại, mức giá, khoảng cách, ăn chay, trong nhà/ngoài trời...) ---------------- */
    var BR_CATS = [["", "Mọi loại"], ["hotel", "Khách sạn"], ["restaurant", "Nhà hàng"], ["cafe", "Quán cà phê"], ["bar", "Quán bar"], ["market", "Chợ, mua sắm"], ["sight", "Tham quan"], ["experience", "Trải nghiệm"], ["tour", "Tour"]];
    var BR_LEVELS = [[1, "₫", "Rẻ"], [2, "₫₫", "Vừa"], [3, "₫₫₫", "Cao"], [4, "₫₫₫₫", "Sang"]];
    var BR_CUISINE = [["", "Món bất kỳ"], ["seafood", "Hải sản"], ["vietnamese", "Việt"], ["japanese", "Nhật"], ["korean", "Hàn"], ["hotpot", "Lẩu"], ["barbecue", "Nướng"], ["pizza", "Pizza"], ["italian", "Ý"], ["western", "Âu"], ["chinese", "Hoa"], ["thai", "Thái"]];
    var BR_KM = [1, 2, 3, 5, 10, 20];
    var brTimer = null;

    function brFromServer(f) {
        return {
            cat: f.cat || "", kinds: f.kinds || [], levels: f.levels || null, minPrice: f.minPrice, maxPrice: f.maxPrice, free: !!f.free, veg: !!f.veg,
            cuisine: f.cuisine || "", indoor: f.indoor === null || f.indoor === undefined ? "" : (f.indoor ? "1" : "0"), stars: f.stars || "",
            km: f.radiusKm || 3, sort: f.sort || "", unknown: !!f.includeUnknown, from: "dest",
        };
    }
    function brAnchor(f) {
        if (f && f.from && f.from !== "dest") {
            var it = items.filter(function (x) { return String(x.uid) === String(f.from); })[0];
            if (it && G.hasPos(it)) return { lat: it.lat, lng: it.lng };
        }
        return biasCenter();
    }
    function brPos() {
        var c = biasCenter(), d = meta().dest;
        return (c ? "&lat=" + c.lat + "&lng=" + c.lng : "") + (d ? "&dest=" + d : "");
    }
    function brQuery(b) {
        var f = b.f, p = ["q=" + encodeURIComponent(b.q), "f=1"], c = brAnchor(f), d = meta().dest;
        if (f.cat) p.push("cat=" + f.cat);
        if (f.kinds && f.kinds.length) p.push("kinds=" + f.kinds.join(","));
        if (f.levels) p.push("levels=" + f.levels[0] + "-" + f.levels[1]);
        if (f.minPrice != null && f.minPrice !== "") p.push("min=" + f.minPrice);
        if (f.maxPrice != null && f.maxPrice !== "") p.push("max=" + f.maxPrice);
        if (f.free) p.push("free=1");
        if (f.veg) p.push("veg=1");
        if (f.cuisine) p.push("cuisine=" + f.cuisine);
        if (f.indoor !== "") p.push("indoor=" + f.indoor);
        if (f.stars) p.push("stars=" + f.stars);
        if (f.km) p.push("km=" + f.km);
        if (f.sort) p.push("sort=" + f.sort);
        if (f.unknown) p.push("unknown=1");
        if (c) p.push("lat=" + c.lat, "lng=" + c.lng);
        if (d) p.push("dest=" + d);
        return cfg.browseUrl + "?" + p.join("&");
    }
    /** Đổi một điều kiện lọc rồi tải lại sau một nhịp ngắn (gõ số tiền không gọi mỗi phím). */
    function brChange(mut, delay) {
        var b = ui.br;
        if (!b) return;
        mut(b.f);
        syncBrPanel();
        clearTimeout(brTimer);
        brTimer = setTimeout(function () { brFetch(); }, delay == null ? 120 : delay);
    }
    function brFetch() {
        var b = ui.br;
        if (!b) return;
        var mine = ++b.seq;
        b.loading = true;
        renderBrowseList();
        fetchJson(brQuery(b))
            .then(function (j) { if (ui.br === b && mine === b.seq) { b.loading = false; b.data = j.browse || b.data; b.failed = false; renderBrowseList(); } })
            .catch(function () { if (ui.br === b && mine === b.seq) { b.loading = false; b.failed = true; renderBrowseList(); } });
    }
    function brNum(v) { var n = parseFloat(String(v).replace(",", ".")); return isNaN(n) || n < 0 ? null : Math.round(n * 1000); }   // ô nhập tính bằng nghìn đồng
    function brField(label, node, cls) {
        var l = el("label", "tp-br__f" + (cls ? " " + cls : ""));
        l.appendChild(el("span", "tp-br__l", label));
        l.appendChild(node);
        return l;
    }
    function brSelect(opts, value, onChange) {
        var s = el("select", "no-nice");
        opts.forEach(function (o) { var op = el("option", null, o[1]); op.value = String(o[0]); s.appendChild(op); });
        s.value = String(value == null ? "" : value);
        s.addEventListener("change", function () { onChange(s.value); });
        return s;
    }
    function brCheck(text, checked, onChange) {
        var l = el("label", "tp-br__ck");
        var c = el("input");
        c.type = "checkbox";
        c.checked = !!checked;
        c.addEventListener("change", function () { onChange(c.checked); });
        l.appendChild(c);
        l.appendChild(document.createTextNode(" " + text));
        return l;
    }
    var brRefs = null;
    function buildBrPanel(b) {
        var f = b.f, p = el("div", "tp-br__panel"), refs = {};
        var head = el("div", "tp-br__head");
        head.appendChild(el("b", null, "Tìm theo tiêu chí"));
        var tags = el("span", "tp-br__tags");
        (b.intent.tags || []).forEach(function (t) { tags.appendChild(el("span", "tp-chip tp-chip--static", t)); });
        if ((b.intent.tags || []).length) head.appendChild(el("span", "tp-muted tp-small", "Đã hiểu:"));
        head.appendChild(tags);
        head.appendChild(btn("tp-mini", "Xóa bộ lọc", "Xóa các bộ lọc", function () { brChange(function (x) { x.kinds = []; x.levels = null; x.minPrice = null; x.maxPrice = null; x.free = false; x.veg = false; x.cuisine = ""; x.indoor = ""; x.stars = ""; x.sort = ""; x.unknown = false; syncBrInputs(); }, 0); }));
        p.appendChild(head);

        var grid = el("div", "tp-br__grid");
        refs.cat = brSelect(BR_CATS, f.cat, function (v) { brChange(function (x) { x.cat = v; x.kinds = []; x.stars = ""; x.cuisine = ""; x.veg = false; }, 0); });
        grid.appendChild(brField("Loại", refs.cat));

        // mức giá: bốn nút, chọn một hoặc nhiều mức liền nhau
        var lv = el("div", "tp-br__levels");
        refs.lv = BR_LEVELS.map(function (L) {
            var bt = btn("tp-chip tp-br__lv", L[1] + " " + L[2], "Mức giá " + L[2].toLowerCase(), function () {
                brChange(function (x) {
                    var lo = x.levels ? x.levels[0] : null, hi = x.levels ? x.levels[1] : null, n = L[0];
                    if (lo === null) { x.levels = [n, n]; }
                    else if (n >= lo && n <= hi) { x.levels = lo === hi ? null : (n === lo ? [lo + 1, hi] : (n === hi ? [lo, hi - 1] : [n, n])); }
                    else { x.levels = [Math.min(lo, n), Math.max(hi, n)]; }
                    x.free = false;
                }, 0);
            });
            bt.setAttribute("aria-pressed", "false");
            lv.appendChild(bt);
            return bt;
        });
        grid.appendChild(brField("Mức giá", lv, "tp-br__f--wide"));

        refs.min = el("input"); refs.min.type = "number"; refs.min.min = "0"; refs.min.step = "50"; refs.min.placeholder = "từ";
        refs.max = el("input"); refs.max.type = "number"; refs.max.min = "0"; refs.max.step = "50"; refs.max.placeholder = "đến";
        refs.min.addEventListener("input", function () { brChange(function (x) { x.minPrice = brNum(refs.min.value); }, 500); });
        refs.max.addEventListener("input", function () { brChange(function (x) { x.maxPrice = brNum(refs.max.value); }, 500); });
        var rng = el("div", "tp-br__range");
        rng.appendChild(refs.min); rng.appendChild(el("span", null, "–")); rng.appendChild(refs.max);
        grid.appendChild(brField("Giá (nghìn đồng)", rng));

        refs.km = brSelect(BR_KM.map(function (k) { return [k, "Trong " + k + " km"]; }), f.km, function (v) { brChange(function (x) { x.km = parseFloat(v); }, 0); });
        grid.appendChild(brField("Khoảng cách", refs.km));

        refs.from = el("select", "no-nice");
        refs.from.addEventListener("change", function () { brChange(function (x) { x.from = refs.from.value; }, 0); });
        grid.appendChild(brField("Tính từ", refs.from));

        refs.stars = brSelect([["", "Mọi hạng"], [2, "2 sao"], [3, "3 sao"], [4, "4 sao"], [5, "5 sao"]], f.stars, function (v) { brChange(function (x) { x.stars = v; }, 0); });
        refs.hotelNodes = [brField("Hạng sao", refs.stars, "tp-br__f--hotel")];
        grid.appendChild(refs.hotelNodes[0]);
        refs.cuisine = brSelect(BR_CUISINE, f.cuisine, function (v) { brChange(function (x) { x.cuisine = v; }, 0); });
        refs.eatNodes = [brField("Món", refs.cuisine, "tp-br__f--eat")];
        grid.appendChild(refs.eatNodes[0]);
        refs.indoor = brSelect([["", "Trong nhà hay ngoài trời"], ["1", "Trong nhà"], ["0", "Ngoài trời"]], f.indoor, function (v) { brChange(function (x) { x.indoor = v; }, 0); });
        grid.appendChild(brField("Không gian", refs.indoor));
        refs.sort = brSelect([["", "Nổi bật trước"], ["near", "Gần nhất"], ["price", "Giá thấp đến cao"]], f.sort, function (v) { brChange(function (x) { x.sort = v; }, 0); });
        grid.appendChild(brField("Sắp xếp", refs.sort));
        p.appendChild(grid);

        var cks = el("div", "tp-br__cks");
        refs.free = brCheck("Miễn phí", f.free, function (v) { brChange(function (x) { x.free = v; if (v) x.levels = null; }, 0); });
        refs.veg = brCheck("Có món chay", f.veg, function (v) { brChange(function (x) { x.veg = v; }, 0); });
        refs.eatNodes.push(refs.veg);
        cks.appendChild(refs.free); cks.appendChild(refs.veg);
        p.appendChild(cks);
        brRefs = refs;
        syncBrInputs();
        return p;
    }
    /** Đặt lại các ô nhập theo trạng thái lọc (sau khi bấm Xóa bộ lọc hoặc khi dựng lần đầu). */
    function syncBrInputs() {
        var b = ui.br, r = brRefs;
        if (!b || !r) return;
        var f = b.f;
        r.cat.value = f.cat; r.stars.value = String(f.stars || ""); r.cuisine.value = f.cuisine || ""; r.indoor.value = f.indoor; r.sort.value = f.sort || "";
        r.km.value = String(f.km);
        r.min.value = f.minPrice != null ? String(f.minPrice / 1000) : ""; r.max.value = f.maxPrice != null ? String(f.maxPrice / 1000) : "";
        r.free.firstChild.checked = !!f.free; r.veg.firstChild.checked = !!f.veg;
        // danh sách "Tính từ": trung tâm điểm đến hoặc một mục đã có vị trí trong chuyến
        r.from.textContent = "";
        var o0 = el("option", null, "Trung tâm điểm đến");
        o0.value = "dest";
        r.from.appendChild(o0);
        items.filter(G.hasPos).slice(0, 30).forEach(function (it) { var o = el("option", null, (it.day ? "Ngày " + it.day + " · " : "") + it.name); o.value = String(it.uid); r.from.appendChild(o); });
        r.from.value = f.from;
        if (r.from.value !== f.from) { f.from = "dest"; r.from.value = "dest"; }
        syncBrPanel();
    }
    function syncBrPanel() {
        var b = ui.br, r = brRefs;
        if (!b || !r) return;
        var f = b.f;
        r.lv.forEach(function (bt, i) {
            var on = !!f.levels && i + 1 >= f.levels[0] && i + 1 <= f.levels[1];
            bt.setAttribute("aria-pressed", on ? "true" : "false");
            bt.classList.toggle("is-on", on);
        });
        var eat = !f.cat || f.cat === "restaurant" || f.cat === "cafe" || f.cat === "bar";
        r.eatNodes.forEach(function (n) { n.hidden = !eat; });
        r.hotelNodes.forEach(function (n) { n.hidden = !(!f.cat || f.cat === "hotel"); });
    }
    function fmtDist(m) { return m < 1000 ? Math.round(m / 10) * 10 + " m" : (Math.round(m / 100) / 10).toLocaleString("vi-VN") + " km"; }
    function priceBadge(r) {
        var lv = r.priceLevel ? "₫₫₫₫".slice(0, r.priceLevel) : "";
        if (r.priceSource === "travela") return { t: (lv ? lv + " · " : "") + (r.priceText || "Giá trên Travela"), cls: "tp-br__p" };
        if (r.priceSource === "stars") return { t: lv + " · ước tính theo hạng sao", cls: "tp-br__p tp-br__p--est" };
        if (r.priceSource === "kind") return { t: lv + " · ước tính theo loại hình", cls: "tp-br__p tp-br__p--est" };
        return { t: r.priceText || "Chưa rõ giá", cls: "tp-br__p tp-br__p--none" };
    }
    var CUISINE_LABEL = {};
    BR_CUISINE.forEach(function (c) { if (c[0]) CUISINE_LABEL[c[0]] = c[1]; });
    function brItem(r) {
        var li = el("li", "tp-br__it");
        var main = el("div", "tp-br__main");
        var href = safeUrl(r.url);
        var nm;
        if (href) { nm = el("a", null, r.name); nm.href = href; nm.target = "_blank"; nm.rel = "noopener"; } else { nm = el("b", null, r.name); }
        main.appendChild(nm);
        var meta1 = el("div", "tp-br__meta");
        if (r.kind) meta1.appendChild(el("span", "tp-type", r.kind));
        if (!r.destLevel) meta1.appendChild(el("span", "tp-br__d", fmtDist(r.distM)));
        if (r.stars) meta1.appendChild(el("span", "tp-br__s", "★".repeat(r.stars) + " " + r.stars + " sao"));
        var pb = priceBadge(r);
        meta1.appendChild(el("span", pb.cls, pb.t));
        main.appendChild(meta1);
        var meta2 = [];
        if (r.veg) meta2.push("Có món chay");
        (r.cuisine || []).slice(0, 3).forEach(function (c) { meta2.push(CUISINE_LABEL[c] || c); });
        if (r.wifi) meta2.push("Wi-Fi");
        if (r.indoor === true) meta2.push("Trong nhà"); else if (r.indoor === false) meta2.push("Ngoài trời");
        if (r.duration) meta2.push(r.duration);
        if (r.hours) meta2.push("Giờ: " + r.hours);
        if (meta2.length) main.appendChild(el("small", null, meta2.join(" · ")));
        if (r.address) main.appendChild(el("small", null, r.address));
        li.appendChild(main);

        var typed = r.type !== "note";
        var added = typed ? !!haveMap()[r.type + ":" + r.refId] : !!haveExtMap()[r.lat + "," + r.lng];
        var add = btn("tp-mini", added ? "Đã có" : "+ Thêm", (added ? "Đã có " : "Thêm ") + r.name + " vào chuyến đi", function () {
            var ok = typed
                ? addItem({ type: r.type, refId: r.refId, name: r.name, sub: r.kind, url: r.url, cost: r.cost, lat: r.lat, lng: r.lng, day: ui.target })
                : addItem({ type: "note", refId: null, name: r.name, note: r.address || "", lat: r.lat, lng: r.lng, source: "search", sub: r.kind || "", day: ui.target });
            if (ok !== false) renderBrowseList();
        });
        add.disabled = added;
        li.appendChild(add);
        if (!r.destLevel && r.lat != null) {
            li.addEventListener("mouseenter", function () { previewPin(r.lat, r.lng, r.name); });
            li.addEventListener("mouseleave", function () { renderDraft(); });
        }
        return li;
    }
    function renderBrowseList() {
        var b = ui.br, box = $("tpBrowseList");
        if (!b || !box) return;
        box.textContent = "";
        var d = b.data || {};
        var bar = el("div", "tp-br__bar");
        bar.appendChild(el("b", null, b.loading ? "Đang tìm…" : (d.total || 0) + " kết quả"));
        if (!b.loading && d.hidden) {
            bar.appendChild(el("span", "tp-muted tp-small", "Đã ẩn " + d.hidden + " mục chưa có thông tin cho bộ lọc này."));
            bar.appendChild(btn("tp-mini", "Hiện cả mục chưa rõ", "Hiện cả mục chưa có thông tin", function () { brChange(function (x) { x.unknown = true; }, 0); }));
        } else if (b.f.unknown && !b.loading) {
            bar.appendChild(btn("tp-mini", "Ẩn mục chưa rõ", "Ẩn mục chưa có thông tin", function () { brChange(function (x) { x.unknown = false; }, 0); }));
        }
        box.appendChild(bar);
        if (b.failed) box.appendChild(el("p", "tp-muted tp-small", "Chưa tải được kết quả lúc này, thử lại sau."));
        if (d.note) box.appendChild(el("p", "tp-muted tp-small", d.note));
        // loại con (từ dữ liệu vừa tìm được)
        var kinds = (d.facets && d.facets.kinds) || [];
        if (kinds.length > 1) {
            var kb = el("div", "tp-br__kinds");
            kb.appendChild(btn("tp-chip" + (b.f.kinds.length ? "" : " is-on"), "Tất cả", "Mọi loại", function () { brChange(function (x) { x.kinds = []; }, 0); }));
            kinds.forEach(function (k) {
                var on = b.f.kinds.indexOf(k.key) >= 0;
                kb.appendChild(btn("tp-chip" + (on ? " is-on" : ""), k.label + " (" + k.count + ")", "Chỉ hiện " + k.label, function () { brChange(function (x) { x.kinds = on ? [] : [k.key]; }, 0); }));
            });
            box.appendChild(kb);
        }
        var list = d.results || [];
        if (!list.length && !b.loading) {
            box.appendChild(el("p", "tp-muted", "Không có địa điểm khớp. Thử nới bộ lọc, tăng khoảng cách, hoặc bấm “Hiện cả mục chưa rõ”."));
        } else {
            var ul = el("ul", "tp-list tp-sugg tp-br__list");
            list.forEach(function (r) { ul.appendChild(brItem(r)); });
            box.appendChild(ul);
        }
        var s = d.sources || {};
        var foot = el("details", "tp-br__about");
        foot.appendChild(el("summary", null, "Về dữ liệu và mức giá"));
        foot.appendChild(el("p", "tp-muted tp-small", "Giá chỉ hiện khi nguồn có dữ liệu. Trải nghiệm và tour lấy giá thật từ Travela. Dữ liệu OpenStreetMap không có giá: mức giá của khách sạn được ước tính theo hạng sao, của nhà nghỉ và quán ăn nhanh theo loại hình, và có ghi “ước tính”. Hạng sao, món, ăn chay, Wi-Fi, giờ mở cửa chỉ hiện khi tình nguyện viên OpenStreetMap đã điền."));
        if (s.osm) foot.appendChild(el("p", "tp-muted tp-small", "Dữ liệu địa điểm © OpenStreetMap contributors."));
        box.appendChild(foot);
    }
    function renderBrowse() {
        var host = $("tpBrowse");
        if (!host) return;
        var b = ui.br;
        host.hidden = !b;
        if (!b) { host.textContent = ""; brRefs = null; return; }
        if (!b.built) {
            host.textContent = "";
            host.appendChild(buildBrPanel(b));
            var l = el("div", "tp-br__results");
            l.id = "tpBrowseList";
            host.appendChild(l);
            var ft = el("div", "tp-res-foot");
            ft.appendChild(el("span", "tp-muted tp-small", "Muốn tìm theo tên?"));
            ft.appendChild(btn("tp-mini", "Tìm “" + b.q + "” theo tên", "Tìm theo tên thay vì theo tiêu chí", function () {
                var q = b.q, mine = ++ui.searchSeq;
                ui.br = null;
                renderBrowse();
                runNameSearch(q, mine);
            }));
            host.appendChild(ft);
            b.built = true;
        }
        renderBrowseList();
    }

    function renderSugg() {
        var s = $("tpSugg");
        s.textContent = "";
        var any = false, have = haveMap();
        ["tray", "tour", "experience", "food", "event"].forEach(function (g) {
            var list = (sugg[g] || []).filter(function (x) { return !have[key(x)]; });
            if (!list.length) return;
            any = true;
            s.appendChild(el("h4", null, SUGG_TITLE[g]));
            s.appendChild(resultList(list, ""));
        });
        if (!any) s.appendChild(el("p", "tp-muted", "Chưa có gợi ý. Thêm một điểm đến trước, các món, lễ hội và tour tại đó sẽ hiện ở đây."));
    }
    function renderPresets() {
        var p = $("tpPresets");
        p.textContent = "";
        p.appendChild(el("span", "tp-muted tp-small", "Tìm nhanh:"));
        SEARCH_CHIPS.forEach(function (x) {
            p.appendChild(btn("tp-chip", x[0], "Tìm " + x[0], function () { $("tpQ").value = x[1]; runSearch(); $("tpQ").focus(); }));
        });
        p.appendChild(el("span", "tp-muted tp-small", "Thêm ngay:"));
        ADD_CHIPS.forEach(function (x) {
            p.appendChild(btn("tp-chip tp-chip--add", "+ " + x[0], "Thêm " + x[0] + " vào chuyến đi", function () { addItem({ type: x[1], refId: null, name: x[0], day: ui.target }); }));
        });
    }
    function renderDestOnly() {
        var d = meta().dest, x = d ? (cfg.dests || []).filter(function (z) { return z.id === d; })[0] : null;
        $("tpDestOnlyBox").hidden = !x;
        if (x) $("tpDestName").textContent = x.name;
    }
    function focusAdd() {
        try { $("tpAdd").scrollIntoView({ block: "start", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
        $("tpQ").focus();
    }
    function fmtDate(d) { return pad2(d.getDate()) + "/" + pad2(d.getMonth() + 1); }
    function renderBrief() {
        var b = $("tpBrief");
        if (!b) return;
        var m = meta(), bits = [m.days + " ngày", m.group + " người"];
        if (m.start) {
            var s0 = new Date(m.start + "T00:00:00");
            if (!isNaN(s0.getTime())) {
                var e0 = new Date(s0.getTime());
                e0.setDate(e0.getDate() + m.days - 1);
                bits.push(fmtDate(s0) + (m.days > 1 ? " – " + fmtDate(e0) : ""));
            }
        }
        if (m.budget != null) bits.push("ngân sách " + money(m.budget));
        b.textContent = bits.join(" · ") + " · ";
        b.appendChild(btn("tp-link", "Sửa thông tin", "Sửa thông tin chuyến đi", function () { switchTab("info"); }));
    }

    /* ---------------- bản đồ (Google Maps, MapLibre hoặc OpenStreetMap, xem planner-map.js) ---------------- */
    var map = null, routeCache = {};
    function initMap(skipGoogle) {
        var m = $("tpMap");
        if (!m || !window.TpMap) return;
        window.TpMap.create(m, {
            googleMapsKey: cfg.googleMapsKey, mapId: cfg.googleMapId, styleUrl: cfg.mapStyleUrl, skipGoogle: !!skipGoogle,
            onAuthFail: function () { m.textContent = ""; map = null; initMap(true); },   // khóa Google bị từ chối: chuyển sang bản đồ vector / OpenStreetMap
        }).then(function (ad) {
            map = ad;
            if (!map) return;
            map.onClick(onMapPick);
            m.setAttribute("data-map", map.kind);
            renderMap();
            renderDraft();
            setTimeout(function () { map.resize(); }, 0);
        });
    }

    /* ---------------- chọn vị trí cho mục tự thêm ---------------- */
    function startPick(uid) {
        if (!map) { say("Bản đồ chưa tải được. Hãy dán link Google Maps hoặc tọa độ.", true); return; }
        ui.pick = { uid: uid == null ? null : uid };
        map.setPicking(true);
        say("Bấm vào một điểm trên bản đồ để chọn vị trí (Esc để hủy).");
        try { map.container().scrollIntoView({ block: "center", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
    }
    function endPick() {
        ui.pick = null;
        if (map) map.setPicking(false);
    }
    function onMapPick(e) {
        if (!ui.pick) return;
        var uid = ui.pick.uid;
        endPick();
        var pos = { lat: Math.round(e.lat * 1e6) / 1e6, lng: Math.round(e.lng * 1e6) / 1e6, source: "map" };
        if (uid == null) { setDraft(pos); say("Đã chọn vị trí. Bấm “+ Thêm” để thêm mục."); return; }
        var it = items.filter(function (x) { return x.uid === uid; })[0];
        if (it) { setPos(it, pos); say("Đã đặt vị trí cho “" + it.name + "”. Chưa lưu."); }
    }
    /** Vị trí nháp của mục đang soạn trong biểu mẫu "Thêm mục tự nhập". */
    function setDraft(pos) {
        ui.draft = pos;
        renderDraft();
    }
    function renderDraft() {
        var m = $("tpPosMsg"), c = $("tpPosClear");
        if (map) map.clearPreview();
        if (!ui.draft) { m.textContent = ""; c.hidden = true; return; }
        m.textContent = "Vị trí đã chọn: " + ui.draft.lat + ", " + ui.draft.lng + " (" + (SRC[ui.draft.source] || "") + ")";
        c.hidden = false;
        if (map) {
            map.preview(ui.draft.lat, ui.draft.lng, "Vị trí của mục sắp thêm");
            map.view(ui.draft.lat, ui.draft.lng, Math.max(map.zoom(), 13));
        }
    }
    function drawRoute(pts, color) {
        var line = map.line(pts, color);
        if (!cfg.osrmUrl || pts.length < 2) return;
        var coords = pts.map(function (p) { return p.lng + "," + p.lat; }).join(";");
        var gen = ++mapGen;
        function paint(g) { if (gen !== mapGen || !map) return; map.removeLine(line); line = map.geoLine(g, color); }
        if (routeCache[coords]) { paint(routeCache[coords]); return; }
        fetch(cfg.osrmUrl.replace(/\/$/, "") + "/route/v1/driving/" + coords + "?overview=simplified&geometries=geojson")
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.routes && j.routes[0]) { routeCache[coords] = j.routes[0].geometry; paint(routeCache[coords]); }
            })
            .catch(function () { /* giữ đường thẳng */ });
    }
    var mapGen = 0;
    function focusItem(uid) {
        switchTab("plan");
        var li = document.querySelector('.tp-item[data-uid="' + uid + '"]');
        if (!li) return;
        li.scrollIntoView({ block: "center", behavior: "smooth" });
        li.classList.add("is-flash");
        setTimeout(function () { li.classList.remove("is-flash"); }, 1600);
    }
    function renderMapDays() {
        var s = $("tpMapDay"), cur = ui.mapDay;
        s.textContent = "";
        var o0 = el("option", null, "Tất cả ngày");
        o0.value = "";
        s.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d);
            o.value = String(d);
            s.appendChild(o);
        }
        var ou = el("option", null, "Chưa xếp");
        ou.value = "x";
        s.appendChild(ou);
        if (cur !== "" && cur !== "x" && +cur > daysCount()) cur = "";
        ui.mapDay = cur;
        s.value = cur;
    }
    function renderMap() {
        if (!map) return;
        mapGen++;
        map.clear();
        var all = [], n = daysCount();
        for (var d = 1; d <= n + 1; d++) {
            var day = d <= n ? d : null;
            if (ui.mapDay !== "" && (day === null ? ui.mapDay !== "x" : String(day) !== ui.mapDay)) continue;
            var color = day === null ? "#6b7280" : COLORS[(day - 1) % COLORS.length];
            var list = dayItems(day);
            var pts = list.filter(G.hasPos);
            if (!pts.length) continue;
            drawRoute(pts, color);
            list.forEach(function (p, i) {
                if (!G.hasPos(p)) return;
                map.marker({
                    id: p.uid, lat: p.lat, lng: p.lng, color: color, label: day === null ? "" : (i + 1),
                    title: (day === null ? "" : "Ngày " + day + " · " + (i + 1) + " ") + p.name,
                    onClick: function () { if (!ui.pick) openCard(p.uid); },
                });
                all.push(p);
            });
        }
        map.fit(all, 13);
        if (ui.card) renderCard();
    }

    /* ---------------- thẻ chi tiết địa điểm (bấm ghim hoặc ảnh để mở) ---------------- */
    function numberOf(it) {
        if (it.day == null) return "";
        return dayItems(it.day).indexOf(it) + 1;
    }
    function dirUrl(it) { return "https://www.google.com/maps/dir/?api=1&destination=" + it.lat + "," + it.lng; }
    function openCard(uid) {
        ui.card = { uid: uid, tab: "about", g: undefined };
        var it = byUid(uid);
        if (it && map) {
            map.highlight(uid);
            if (G.hasPos(it)) map.view(it.lat, it.lng, Math.max(map.zoom(), 14));
        }
        renderCard();
        if (it && map && map.kind === "google" && G.hasPos(it) && !CUSTOM[it.type]) {
            map.details(it).then(function (g) {
                if (ui.card && ui.card.uid === uid) { ui.card.g = g || null; renderCard(); }
            });
        }
    }
    function closeCard() {
        ui.card = null;
        if (map) map.highlight(null);
        var c = $("tpPlaceCard");
        if (c) { c.hidden = true; c.textContent = ""; }
    }
    function cardNav(dir) {
        var it = byUid(ui.card.uid);
        if (!it) return;
        var list = dayItems(it.day).filter(G.hasPos), i = list.indexOf(it) + dir;
        if (i >= 0 && i < list.length) openCard(list[i].uid);
    }
    function renderCard() {
        var c = $("tpPlaceCard");
        if (!c) return;
        var it = ui.card ? byUid(ui.card.uid) : null;
        if (!it) { closeCard(); return; }
        var g = ui.card.g, tab = ui.card.tab;
        c.textContent = "";
        c.hidden = false;

        var head = el("div", "tp-pcard__head");
        var num = numberOf(it);
        var col = it.day == null ? "#6b7280" : COLORS[(it.day - 1) % COLORS.length];
        head.appendChild(window.TpMap.pinEl(col, num === "" ? "" : num, true));
        var tt = el("div", "tp-pcard__title");
        tt.appendChild(el("strong", null, it.name));
        tt.appendChild(el("small", null, [TYPE[it.type] || "", it.sub, it.day != null ? dayLabel(it.day) : "Chưa xếp ngày"].filter(Boolean).join(" · ")));
        head.appendChild(tt);
        var list = it.day != null ? dayItems(it.day).filter(G.hasPos) : [];
        if (list.length > 1) {
            var i = list.indexOf(it);
            var nav = el("div", "tp-pcard__nav");
            nav.appendChild(btn("tp-mini", "‹", "Mục trước trong ngày", function () { cardNav(-1); }));
            nav.appendChild(el("span", null, (i + 1) + "/" + list.length));
            nav.appendChild(btn("tp-mini", "›", "Mục sau trong ngày", function () { cardNav(1); }));
            head.appendChild(nav);
        }
        head.appendChild(btn("tp-mini tp-pcard__x", "✕", "Đóng thẻ chi tiết", closeCard));
        c.appendChild(head);

        var tabs = el("div", "tp-pcard__tabs");
        [["about", "Giới thiệu"]].concat(g && g.photos && g.photos.length ? [["photos", "Ảnh"]] : []).forEach(function (t) {
            var b = btn("tp-pcard__tab" + (tab === t[0] ? " is-on" : ""), t[1], null, function () { ui.card.tab = t[0]; renderCard(); });
            tabs.appendChild(b);
        });
        c.appendChild(tabs);

        var body = el("div", "tp-pcard__body");
        if (tab === "photos" && g && g.photos) {
            var gal = el("div", "tp-pcard__gal");
            g.photos.forEach(function (ph) {
                var im = el("img");
                im.src = ph.url; im.alt = it.name; im.loading = "lazy";
                if (ph.by) im.title = "Ảnh: " + ph.by;
                gal.appendChild(im);
            });
            body.appendChild(gal);
            body.appendChild(el("small", "tp-muted", "Ảnh từ Google Maps, hiển thị trực tiếp, không lưu trên Travela."));
        } else {
            var top = el("div", "tp-pcard__top");
            var txt = el("div", "tp-pcard__txt");
            if (it.blurb) txt.appendChild(el("p", null, it.blurb));
            else if (!g) txt.appendChild(el("p", "tp-muted", CUSTOM[it.type] ? "Mục bạn tự thêm." : "Chưa có lời giới thiệu cho mục này."));
            if (it.reasons && it.reasons.length) txt.appendChild(el("p", "tp-why", "Vì sao chọn: " + it.reasons.join("; ")));
            if (it.fit && it.fit.length) {
                var fl = el("ul", "tp-fit");
                it.fit.forEach(function (f) { fl.appendChild(el("li", "tp-fit__i tp-fit--" + (f.k === "good" || f.k === "warn" ? f.k : "info"), f.x)); });
                txt.appendChild(fl);
            }
            top.appendChild(txt);
            var img = it.image || (g && g.photos && g.photos[0] && g.photos[0].url);
            if (img) { var pi = el("img", "tp-pcard__img"); pi.src = img; pi.alt = it.name; pi.loading = "lazy"; top.appendChild(pi); }
            body.appendChild(top);

            var facts = el("ul", "tp-pcard__facts");
            function fact(label, value, href) {
                if (!value) return;
                var li = el("li");
                li.appendChild(el("b", null, label + ": "));
                if (href && safeUrl(href)) { var a = el("a", null, value); a.href = href; a.target = "_blank"; a.rel = "noopener"; li.appendChild(a); }
                else li.appendChild(el("span", null, value));
                facts.appendChild(li);
            }
            fact("Địa chỉ", it.address || (g && g.address));
            if (g && g.rating != null) fact("Đánh giá Google", g.rating.toFixed(1) + " ★ (" + g.ratings.toLocaleString("vi-VN") + " lượt)", g.mapsUrl);
            fact("Điện thoại", g && g.phone);
            if (it.website || (g && g.website)) fact("Website", (it.website || g.website).replace(/^https?:\/\/(www\.)?/i, "").replace(/\/$/, ""), it.website || g.website);
            if (it.when) fact("Diễn ra", it.when);
            if (it.cost != null) fact("Chi phí", money(it.cost));
            if (it.note) fact("Ghi chú", it.note);
            if (it.src) fact("Nguồn", it.src);
            if (facts.children.length) body.appendChild(facts);
            if (g && g.hours && g.hours.length) {
                var hd = el("details", "tp-pcard__hours");
                hd.appendChild(el("summary", null, "Giờ mở cửa"));
                var hu = el("ul");
                g.hours.forEach(function (h) { hu.appendChild(el("li", null, h)); });
                hd.appendChild(hu);
                body.appendChild(hd);
            }
            if (g) body.appendChild(el("small", "tp-muted", "Giờ mở cửa, đánh giá và ảnh lấy trực tiếp từ Google Maps, có thể thay đổi."));
            else if (map && map.kind === "google" && !CUSTOM[it.type] && ui.card.g === undefined) body.appendChild(el("small", "tp-muted", "Đang tải thêm thông tin từ Google…"));
        }
        c.appendChild(body);

        var act = el("div", "tp-pcard__act");
        if (G.hasPos(it)) {
            var a1 = el("a", "tp-btn tp-btn--ghost", "Chỉ đường");
            a1.href = dirUrl(it); a1.target = "_blank"; a1.rel = "noopener";
            act.appendChild(a1);
            var a2 = el("a", "tp-btn tp-btn--ghost", "Mở Google Maps");
            a2.href = (g && g.mapsUrl) || G.mapsUrl(it.lat, it.lng); a2.target = "_blank"; a2.rel = "noopener";
            act.appendChild(a2);
        }
        var href = safeUrl(it.url);
        if (href && !CUSTOM[it.type]) { var a3 = el("a", "tp-btn tp-btn--ghost", "Xem trên Travela"); a3.href = href; a3.target = "_blank"; a3.rel = "noopener"; act.appendChild(a3); }
        c.appendChild(act);
    }
    function showOnMap(uid) {
        var it = byUid(uid);
        if (!it) return;
        if (!G.hasPos(it)) { say("Mục này chưa có vị trí nên chưa hiện trên bản đồ.", true); return; }
        openCard(uid);
    }

    /* ---------------- tab ---------------- */
    var TABS = ["plan", "cost", "info"], LEGACY = { explore: "plan", overview: "info" };
    function switchTab(name) {
        name = LEGACY[name] || name;
        if (TABS.indexOf(name) < 0) name = "plan";
        ui.tab = name;
        document.querySelectorAll(".tp-tab").forEach(function (t) {
            var on = t.getAttribute("data-tab") === name;
            t.classList.toggle("is-on", on);
            t.setAttribute("aria-selected", on ? "true" : "false");
        });
        TABS.forEach(function (k) {
            var p = $("tpPanel" + k.charAt(0).toUpperCase() + k.slice(1));
            if (p) p.hidden = k !== name;
        });
        try { sessionStorage.setItem("tpTab" + cfg.trip.id, name); } catch (e) { /* bỏ qua */ }
        if (name === "cost") renderCostTable();
        if (name === "plan" && map) setTimeout(function () { map.resize(); }, 0);
    }

    /* ---------------- dựng lại ---------------- */
    function softUpdate() {
        renderBrief();
        renderTotals();
        renderStats();
        renderWarn();
        renderCostSummary();
    }
    function renderAll(keepList) {
        if (!keepList) { renderDays(); renderBulk(); if (ui.tab === "cost") renderCostTable(); }
        renderBrief();
        renderDestOnly();
        renderTotals();
        renderStats();
        renderWarn();
        renderSummary();
        renderSugg();
        renderResults();
        renderTarget();
        renderMapDays();
        renderMap();
        renderCostSummary();
        syncButtons();
    }

    /* ---------------- lưu / chia sẻ ---------------- */
    function post(url, body) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify(body),
        });
    }
    function save(manual) {
        if (RO) return;
        clearTimeout(timer);
        if (saving) { pendingSave = true; return; }
        if (!dirty && !manual) return;
        if (!manual && autoFail) return;
        clampDays();
        var m = meta();
        if (!m.title) { say("Hãy nhập tên chuyến đi (chưa lưu).", true); return; }
        var body = {
            rev: rev,
            title: m.title, startDate: m.start, days: m.days, groupSize: m.group, budget: m.budget, destinationId: m.dest,
            rainPlan: (function () {
                var o = {}, n = daysCount();
                Object.keys(rain).forEach(function (k) {
                    if (+k >= 1 && +k <= n) o[k] = rain[k].map(function (r) { return { type: r.type, refId: r.refId }; });
                });
                return o;
            })(),
            items: items.map(function (x) {
                var o = { type: x.type, refId: CUSTOM[x.type] ? null : x.refId, day: x.day, note: x.note, cost: x.cost, slot: x.slot || null, reasons: x.reasons || [] };
                if (CUSTOM[x.type]) {
                    o.label = x.name;
                    if (G.hasPos(x)) { o.lat = x.lat; o.lng = x.lng; o.source = x.source || "map"; }
                }
                return o;
            }),
        };
        var sent = snap();
        saving = true;
        $("tpSave").disabled = true;
        say("Đang lưu…");
        post(cfg.saveUrl, body)
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
            .then(function (res) {
                saving = false;
                $("tpSave").disabled = false;
                if (res.status === 409) { showSync(res.j.message || "Chuyến đi vừa được người khác cập nhật.", true); autoFail = true; return; }
                if (res.ok) {
                    if (res.j.rev != null) rev = res.j.rev;
                    savedSnap = sent;
                    cfg.contentWarnings = res.j.contentWarnings || [];
                    cfg.advice = res.j.advice || [];
                    if (res.j.fit) {
                        var same = snap() === sent;
                        items.forEach(function (x) {
                            var f = res.j.fit[x.type + ":" + x.refId];
                            if (f) { x.fit = f.fit || []; x.outdoor = f.outdoor; x.src = f.src || null; }
                        });
                        if (same) { sent = snap(); savedSnap = sent; last = sent; }
                        renderDays();
                    }
                    renderWarn();
                    renderSummary();
                    if (snap() !== sent) { markDirty(); } else { dirty = false; autoFail = false; say("Đã lưu lúc " + (res.j.savedAt || "")); }
                } else {
                    var errs = res.j && res.j.errors ? Object.keys(res.j.errors).map(function (k) { return res.j.errors[k][0]; }) : [];
                    autoFail = true;
                    say(errs[0] || (res.j && res.j.message) || "Không lưu được, bấm “Lưu thay đổi” để thử lại.", true);
                }
                if (pendingSave) { pendingSave = false; if (dirty) save(false); }
            })
            .catch(function () {
                saving = false;
                autoFail = true;
                $("tpSave").disabled = false;
                say("Mất kết nối, chưa lưu được. Bấm “Lưu thay đổi” khi có mạng.", true);
            });
    }
    function share() {
        var b = $("tpShareBtn"), inp = $("tpShareUrl");
        var on = inp.hidden;
        b.disabled = true;
        post(cfg.shareUrl, { on: on ? 1 : 0 })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.url) { inp.value = j.url; inp.hidden = false; b.textContent = "Tắt chia sẻ"; inp.select(); }
                else { inp.value = ""; inp.hidden = true; b.textContent = "Bật liên kết chia sẻ"; }
            })
            .catch(function () { /* giữ trạng thái cũ */ })
            .then(function () { b.disabled = false; });
    }

    /* ---------------- khởi tạo ---------------- */
    function fillDestSelects() {
        var main = $("tpDest");
        (cfg.dests || []).forEach(function (d) {
            var a = el("option", null, d.name);
            a.value = String(d.id);
            main.appendChild(a);
        });
        if (cfg.trip.destinationId) main.value = String(cfg.trip.destinationId);
    }

    fillDestSelects();
    renderPresets();

    $("tpSave").addEventListener("click", function () { save(true); });
    $("tpUndo").addEventListener("click", undoOnce);
    $("tpRedo").addEventListener("click", redoOnce);
    if ($("tpShareBtn")) $("tpShareBtn").addEventListener("click", share);

    $("tpTitle").addEventListener("input", function () { $("tpHeading").textContent = $("tpTitle").value || "Chuyến đi"; });
    $("tpTitle").addEventListener("change", function () { commit(true); });
    $("tpStart").addEventListener("change", function () { commit(); });
    $("tpDays").addEventListener("change", function () { clampDays(); commit(); });
    $("tpGroup").addEventListener("change", function () { commit(true); });
    $("tpBudget").addEventListener("input", softUpdate);
    $("tpBudget").addEventListener("change", function () { commit(true); });
    $("tpDest").addEventListener("change", function () { commit(true); });

    $("tpTarget").addEventListener("change", function () { ui.target = $("tpTarget").value === "" ? null : +$("tpTarget").value; });
    $("tpMapDay").addEventListener("change", function () { ui.mapDay = $("tpMapDay").value; renderMap(); });
    var qTimer = null;
    $("tpQ").addEventListener("input", function () { clearTimeout(qTimer); qTimer = setTimeout(runSearch, 350); });
    $("tpQ").addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); clearTimeout(qTimer); runSearch(); }
        else if (e.key === "Escape") { clearTimeout(qTimer); $("tpQ").value = ""; ui.searchSeq++; ui.res = null; ui.br = null; renderResults(); renderBrowse(); }
    });
    $("tpDestOnly").addEventListener("change", function () { if ($("tpQ").value.trim()) runSearch(); });
    $("tpCustomForm").addEventListener("submit", function (e) {
        e.preventDefault();
        var label = $("tpCustomLabel").value.trim();
        if (!label) return;
        var c = $("tpCustomCost").value, d = ui.draft;
        if (addItem({ type: $("tpCustomKind").value, refId: null, name: label, cost: c === "" ? null : +c, day: ui.target,
            lat: d ? d.lat : null, lng: d ? d.lng : null, source: d ? d.source : null })) {
            $("tpCustomLabel").value = "";
            $("tpCustomCost").value = "";
            $("tpCustomLink").value = "";
            setDraft(null);
        }
    });
    $("tpCustomLink").addEventListener("input", function () {
        var v = this.value.trim();
        if (!v) { setDraft(null); return; }
        var p = G.parseMapsInput(v);
        if (p) { p.source = "link"; setDraft(p); return; }
        setDraft(null);
        $("tpPosMsg").textContent = G.isShortMapsLink(v) ? SHORT_MSG : BAD_POS_MSG;
    });
    $("tpPickMap").addEventListener("click", function () { startPick(null); });
    $("tpPosClear").addEventListener("click", function () { $("tpCustomLink").value = ""; setDraft(null); });

    document.querySelectorAll(".tp-tab").forEach(function (t) { t.addEventListener("click", function () { switchTab(t.getAttribute("data-tab")); }); });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && ui.pick) { endPick(); say("Đã hủy chọn vị trí."); return; }
        var tag = (e.target && e.target.tagName) || "";
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) return;
        if (!(e.ctrlKey || e.metaKey)) return;
        var k = (e.key || "").toLowerCase();
        if (k === "z" && !e.shiftKey) { e.preventDefault(); undoOnce(); }
        else if ((k === "z" && e.shiftKey) || k === "y") { e.preventDefault(); redoOnce(); }
        else if (k === "s") { e.preventDefault(); save(true); }
    });
    window.addEventListener("beforeunload", function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ""; }
    });

    /* ---------------- cộng tác: thông báo có người vừa sửa, mời bạn đồng hành ---------------- */
    function showSync(text, conflict) {
        var b = $("tpSync");
        if (!b) return;
        b.textContent = "";
        b.hidden = false;
        b.classList.toggle("tp-banner--bad", !!conflict);
        b.appendChild(el("span", null, text + " "));
        b.appendChild(btn("tp-mini", "Tải lại", "Tải lại chuyến đi", function () { dirty = false; location.reload(); }));
    }
    var pollMs = 20000;
    function pollRev() {
        if (!cfg.revUrl || document.hidden) return;
        fetch(cfg.revUrl, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) return;
                if (j.role && j.role !== ROLE) { showSync("Quyền của bạn trong chuyến đi vừa thay đổi.", false); return; }
                if (j.rev > rev) showSync((j.by || "Người khác") + " vừa cập nhật chuyến đi" + (dirty ? ", bạn có thay đổi chưa lưu nên có thể bị xung đột." : "."), dirty);
            })
            .catch(function () { /* mất mạng: bỏ qua lần này */ });
    }
    if (cfg.revUrl) setInterval(pollRev, pollMs);

    var memLoaded = false;
    function memberMsg(t, bad) {
        var m = $("tpInvMsg");
        if (!m) return;
        m.textContent = t || "";
        m.classList.toggle("tp-msg--bad", !!bad);
    }
    function renderMembers(j) {
        var ul = $("tpMemList");
        if (!ul) return;
        ul.textContent = "";
        (j.members || []).forEach(function (m) {
            var li = el("li", "tp-mem");
            li.appendChild(el("span", "tp-mem__av", (m.name || "?").trim().charAt(0).toUpperCase()));
            var who = el("div", "tp-mem__who");
            who.appendChild(el("b", null, m.name + (m.userId === cfg.me ? " (bạn)" : "")));
            if (m.email) who.appendChild(el("small", "tp-muted", m.email));
            li.appendChild(who);
            if (m.isOwner) li.appendChild(el("span", "tp-role tp-role--owner", "Chủ chuyến"));
            else if (ROLE === "owner") {
                var sel = el("select", "no-nice");
                sel.setAttribute("aria-label", "Quyền của " + m.name);
                [["view", "Chỉ xem"], ["edit", "Chỉnh sửa"]].forEach(function (o) { var op = el("option", null, o[1]); op.value = o[0]; sel.appendChild(op); });
                sel.value = m.role;
                sel.addEventListener("change", function () {
                    post(cfg.membersUrl + "/" + m.userId, { role: sel.value }).then(function (r) { memberMsg(r.ok ? "Đã đổi quyền của " + m.name + "." : "Không đổi được quyền.", !r.ok); });
                });
                li.appendChild(sel);
                li.appendChild(btn("tp-mini tp-mini--danger", "Gỡ", "Gỡ " + m.name + " khỏi chuyến đi", function () {
                    post(cfg.membersUrl + "/" + m.userId + "/go", {}).then(function (r) { if (r.ok) { memberMsg("Đã gỡ " + m.name + "."); loadMembers(); } else memberMsg("Không gỡ được.", true); });
                }));
            } else {
                li.appendChild(el("span", "tp-role tp-role--" + m.role, m.role === "edit" ? "Chỉnh sửa" : "Chỉ xem"));
                if (m.userId === cfg.me) li.appendChild(btn("tp-mini tp-mini--danger", "Rời chuyến", "Rời khỏi chuyến đi", function () {
                    if (!window.confirm("Rời khỏi chuyến đi này?")) return;
                    post(cfg.membersUrl + "/" + m.userId + "/go", {}).then(function (r) { if (r.ok) { dirty = false; location.href = "/len-ke-hoach"; } else memberMsg("Không rời được.", true); });
                }));
            }
            ul.appendChild(li);
        });
    }
    function loadMembers() {
        fetch(cfg.membersUrl, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j) { memLoaded = true; renderMembers(j); } else memberMsg("Chưa tải được danh sách.", true); })
            .catch(function () { memberMsg("Mất kết nối.", true); });
    }
    var memLastFocus = null;
    function openMembers() {
        var d = $("tpMembers");
        if (!d) return;
        memLastFocus = document.activeElement;
        d.hidden = false;
        loadMembers();
        var f = $("tpInvEmail") || d.querySelector("button");
        if (f) f.focus();
    }
    function closeMembers() {
        var d = $("tpMembers");
        if (!d) return;
        d.hidden = true;
        if (memLastFocus && memLastFocus.focus) memLastFocus.focus();
    }
    function inviteSubmit(e) {
        e.preventDefault();
        var email = $("tpInvEmail").value.trim(), role = $("tpInvRole").value;
        if (!email) return;
        memberMsg("Đang mời…");
        post(cfg.membersUrl, { email: email, role: role })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                var j = res.j || {};
                var errs = j.errors ? Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }) : [];
                memberMsg(j.message || errs[0] || (res.ok ? "Đã mời." : "Không mời được."), !res.ok);
                if (res.ok) { $("tpInvEmail").value = ""; loadMembers(); }
            })
            .catch(function () { memberMsg("Mất kết nối.", true); });
    }
    if ($("tpInviteBtn")) $("tpInviteBtn").addEventListener("click", openMembers);
    if ($("tpMembersClose")) $("tpMembersClose").addEventListener("click", closeMembers);
    if ($("tpMembers")) $("tpMembers").addEventListener("click", function (e) { if (e.target === $("tpMembers")) closeMembers(); });
    if ($("tpInvForm")) $("tpInvForm").addEventListener("submit", inviteSubmit);
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { if ($("tpMembers") && !$("tpMembers").hidden) closeMembers(); else if (ui.card) closeCard(); } });
    if ($("tpPlaceCard")) $("tpPlaceCard").hidden = true;

    if (RO) {
        var root = document.querySelector(".tp");
        if (root) root.classList.add("tp--readonly");
        ["tpTitle", "tpStart", "tpDays", "tpGroup", "tpBudget", "tpDest"].forEach(function (id) { if ($(id)) $(id).disabled = true; });
        say("Bạn đang xem chuyến đi này với quyền chỉ xem.");
    }

    clampDays();
    last = snap();
    savedSnap = last;
    renderAll();
    initMap();
    var startTab = (location.hash || "").replace("#", "");
    if (!/^(plan|cost|info|overview|explore)$/.test(startTab)) {
        try { startTab = sessionStorage.getItem("tpTab" + cfg.trip.id) || "plan"; } catch (e) { startTab = "plan"; }
    }
    switchTab(startTab);
})();
