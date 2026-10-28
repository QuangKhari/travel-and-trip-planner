/* Trình soạn Trip Planner (/len-ke-hoach/{id}). JS thuần. Dữ liệu từ <script type="application/json" id="tpData">.
 * Trạng thái nằm trong mảng `items`; mọi chữ động gán bằng textContent (không innerHTML). Quãng đường/cảnh báo do máy chủ
 * tính sau khi bấm Lưu (trang tải lại). Bản đồ: Leaflet + OpenStreetMap; nếu cấu hình osrmUrl thì vẽ đường bộ, không thì đường thẳng.
 */
(function () {
    "use strict";
    var node = document.getElementById("tpData");
    if (!node) return;
    var cfg = JSON.parse(node.textContent);
    var items = cfg.items.map(function (x) {
        x.day = x.day == null ? null : +x.day;
        x.cost = x.cost == null || x.cost === "" ? null : +x.cost;
        x.note = x.note || "";
        return x;
    });
    var sugg = cfg.suggestions || {};
    var TYPE = { destination: "Điểm đến", place: "Địa điểm", food: "Món ăn", event: "Lễ hội", experience: "Trải nghiệm", tour: "Tour" };
    var SUGG_TITLE = { tray: "Từ khay chuyến đi", tour: "Tour sắp khởi hành", experience: "Trải nghiệm", food: "Món nên thử", event: "Lễ hội" };
    var COLORS = ["#0d6f6a", "#c2410c", "#1d4ed8", "#a21caf", "#4d7c0f", "#be123c", "#0e7490", "#92400e", "#4338ca", "#15803d", "#b45309", "#6d28d9", "#0369a1", "#9f1239"];
    var $ = function (id) { return document.getElementById(id); };
    var box = $("tpDaysBox"), totals = $("tpTotals"), msg = $("tpMsg"), suggBox = $("tpSugg");

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function safeUrl(u) { return /^(\/|https?:\/\/)/.test(u || "") ? u : "#"; }
    function money(n) { return Math.round(n).toLocaleString("vi-VN") + "đ"; }
    function daysCount() {
        var n = parseInt($("tpDays").value, 10);
        return isNaN(n) ? 1 : Math.max(1, Math.min(14, n));
    }
    function key(it) { return it.type + ":" + it.refId; }
    function dayLabel(d) {
        var s = $("tpStart").value;
        if (!s) return "Ngày " + d;
        var dt = new Date(s + "T00:00:00");
        if (isNaN(dt.getTime())) return "Ngày " + d;
        dt.setDate(dt.getDate() + d - 1);
        return "Ngày " + d + " · " + dt.toLocaleDateString("vi-VN", { weekday: "short", day: "2-digit", month: "2-digit" });
    }

    function clampDays() {
        var n = daysCount();
        items.forEach(function (it) { if (it.day != null && it.day > n) it.day = n; });
    }

    /* ---------- danh sách theo ngày ---------- */
    function itemRow(it) {
        var li = el("li", "tp-item");
        var top = el("div", "tp-item__top");
        top.appendChild(el("span", "tp-type", TYPE[it.type] || ""));
        var a = el("a", "tp-item__name", it.name);
        a.href = safeUrl(it.url);
        a.target = "_blank";
        a.rel = "noopener";
        top.appendChild(a);
        var sub = [it.sub, it.when ? "diễn ra " + it.when : ""].filter(Boolean).join(" · ");
        if (sub) top.appendChild(el("small", "tp-item__sub", sub));
        li.appendChild(top);
        if (it.reasons && it.reasons.length) {
            var why = el("p", "tp-why", "Vì sao chọn: " + it.reasons.join("; "));
            li.appendChild(why);
        }

        var ctl = el("div", "tp-item__ctl");
        var sel = el("select");
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
            it.day = sel.value === "" ? null : +sel.value;
            render();
        });
        ctl.appendChild(sel);

        [["↑", -1, "Lên"], ["↓", 1, "Xuống"]].forEach(function (b) {
            var btn = el("button", "tp-mini", b[0]);
            btn.type = "button";
            btn.setAttribute("aria-label", b[2] + ": " + it.name);
            btn.addEventListener("click", function () { move(it, b[1]); });
            ctl.appendChild(btn);
        });
        var rm = el("button", "tp-mini tp-mini--danger", "Bỏ");
        rm.type = "button";
        rm.setAttribute("aria-label", "Bỏ " + it.name + " khỏi chuyến đi");
        rm.addEventListener("click", function () {
            items.splice(items.indexOf(it), 1);
            render();
        });
        ctl.appendChild(rm);
        li.appendChild(ctl);

        var more = el("div", "tp-item__more");
        var note = el("input");
        note.type = "text";
        note.maxLength = 255;
        note.placeholder = "Ghi chú";
        note.value = it.note;
        note.setAttribute("aria-label", "Ghi chú cho " + it.name);
        note.addEventListener("input", function () { it.note = note.value; });
        var cost = el("input");
        cost.type = "number";
        cost.min = "0";
        cost.step = "1000";
        cost.placeholder = "Chi phí (đ)";
        cost.value = it.cost == null ? "" : String(it.cost);
        cost.setAttribute("aria-label", "Chi phí của " + it.name);
        cost.addEventListener("input", function () {
            it.cost = cost.value === "" ? null : +cost.value;
            renderTotals();
        });
        more.appendChild(note);
        more.appendChild(cost);
        li.appendChild(more);
        return li;
    }

    function move(it, dir) {
        var group = items.filter(function (x) { return x.day === it.day; });
        var i = group.indexOf(it), j = i + dir;
        if (j < 0 || j >= group.length) return;
        var a = items.indexOf(it), b = items.indexOf(group[j]);
        items[a] = group[j];
        items[b] = it;
        render();
    }

    function renderDays() {
        box.textContent = "";
        var n = daysCount();
        var groups = [];
        for (var d = 1; d <= n; d++) groups.push(d);
        groups.push(null);
        groups.forEach(function (d) {
            var list = items.filter(function (x) { return x.day === d; });
            if (d === null && !list.length) return;
            var sec = el("section", "tp-day");
            var h = el("h3", null, d === null ? "Chưa xếp ngày" : dayLabel(d));
            if (d !== null) h.style.borderLeftColor = COLORS[(d - 1) % COLORS.length];
            sec.appendChild(h);
            if (!list.length) {
                sec.appendChild(el("p", "tp-muted", "Chưa có mục nào. Thêm từ phần “Gợi ý thêm” hoặc chọn ngày cho mục ở ngày khác."));
            } else {
                var ul = el("ul", "tp-items");
                list.forEach(function (it) { ul.appendChild(itemRow(it)); });
                sec.appendChild(ul);
            }
            box.appendChild(sec);
        });
        if (!items.length) {
            box.insertBefore(el("p", "tp-muted", "Chuyến đi chưa có mục nào. Chọn từ “Gợi ý thêm” bên phải."), box.firstChild);
        }
    }

    function renderTotals() {
        var sum = items.reduce(function (s, x) { return s + (x.cost || 0); }, 0);
        var budget = $("tpBudget").value === "" ? null : +$("tpBudget").value;
        var t = "Chi phí đã nhập: " + money(sum);
        if (budget != null) t += " / ngân sách " + money(budget) + (sum > budget ? " (vượt " + money(sum - budget) + ")" : "");
        totals.textContent = t;
        totals.classList.toggle("tp-totals--over", budget != null && sum > budget);
    }

    /* ---------- gợi ý ---------- */
    function renderSugg() {
        if (!suggBox) return;
        suggBox.textContent = "";
        var have = {};
        items.forEach(function (x) { have[key(x)] = true; });
        var any = false;
        ["tray", "tour", "experience", "food", "event"].forEach(function (g) {
            var list = (sugg[g] || []).filter(function (s) { return !have[key(s)]; });
            if (!list.length) return;
            any = true;
            suggBox.appendChild(el("h3", null, SUGG_TITLE[g]));
            var ul = el("ul", "tp-list tp-sugg");
            list.forEach(function (s) {
                var li = el("li");
                var a = el("a", null, s.name);
                a.href = safeUrl(s.url);
                a.target = "_blank";
                a.rel = "noopener";
                li.appendChild(a);
                if (s.sub) li.appendChild(el("small", null, s.sub));
                var add = el("button", "tp-mini", "+ Thêm");
                add.type = "button";
                add.setAttribute("aria-label", "Thêm " + s.name + " vào chuyến đi");
                add.addEventListener("click", function () {
                    items.push({ type: s.type, refId: s.refId, name: s.name, sub: s.sub || "", url: s.url, day: null, note: "", cost: s.cost == null ? null : +s.cost, lat: s.lat || null, lng: s.lng || null, when: null });
                    render();
                });
                li.appendChild(add);
                ul.appendChild(li);
            });
            suggBox.appendChild(ul);
        });
        if (!any) suggBox.appendChild(el("p", "tp-muted", "Chưa có gợi ý. Thêm một điểm đến trước, các món, lễ hội và tour tại đó sẽ hiện ở đây."));
    }

    /* ---------- bản đồ ---------- */
    var map = null, layer = null, routeCache = {};
    function initMap() {
        var m = $("tpMap");
        if (!m || !window.L) return;
        map = L.map(m, { scrollWheelZoom: false });
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 18, attribution: "© OpenStreetMap" }).addTo(map);
        layer = L.layerGroup().addTo(map);
        map.setView([16.0, 106.0], 5);
    }
    function drawRoute(pts, color) {
        var straight = pts.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(straight, { color: color, weight: 4, opacity: 0.8 }).addTo(layer);
        if (!cfg.osrmUrl || pts.length < 2) return;
        var coords = pts.map(function (p) { return p.lng + "," + p.lat; }).join(";");
        var ck = coords;
        function paint(g) { layer.removeLayer(line); line = L.geoJSON(g, { style: { color: color, weight: 4, opacity: 0.85 } }).addTo(layer); }
        if (routeCache[ck]) { paint(routeCache[ck]); return; }
        fetch(cfg.osrmUrl.replace(/\/$/, "") + "/route/v1/driving/" + coords + "?overview=simplified&geometries=geojson")
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.routes && j.routes[0]) { routeCache[ck] = j.routes[0].geometry; if (layer.hasLayer(line)) paint(routeCache[ck]); }
            })
            .catch(function () { /* giữ đường thẳng */ });
    }
    function renderMap() {
        if (!map) return;
        layer.clearLayers();
        var all = [];
        var n = daysCount();
        for (var d = 1; d <= n + 1; d++) {
            var day = d <= n ? d : null;
            var color = day === null ? "#6b7280" : COLORS[(day - 1) % COLORS.length];
            var pts = items.filter(function (x) { return x.day === day && x.lat != null && x.lng != null; });
            if (!pts.length) continue;
            drawRoute(pts, color);
            pts.forEach(function (p, i) {
                var mk = L.circleMarker([p.lat, p.lng], { radius: 8, color: "#fff", weight: 2, fillColor: color, fillOpacity: 1 }).addTo(layer);
                mk.bindTooltip((day === null ? "" : "N" + day + "·" + (i + 1) + " ") + p.name);
                all.push([p.lat, p.lng]);
            });
        }
        if (all.length > 1) map.fitBounds(all, { padding: [24, 24], maxZoom: 12 });
        else if (all.length === 1) map.setView(all[0], 10);
    }

    function render() {
        clampDays();
        renderDays();
        renderTotals();
        renderSugg();
        renderMap();
    }

    /* ---------- lưu / chia sẻ ---------- */
    function post(url, body) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify(body),
        });
    }
    function say(t, bad) {
        msg.textContent = t;
        msg.classList.toggle("tp-msg--bad", !!bad);
    }
    function save() {
        clampDays();
        var body = {
            title: $("tpTitle").value.trim(),
            startDate: $("tpStart").value || null,
            days: daysCount(),
            groupSize: parseInt($("tpGroup").value, 10) || 1,
            budget: $("tpBudget").value === "" ? null : +$("tpBudget").value,
            items: items.map(function (x) { return { type: x.type, refId: x.refId, day: x.day, note: x.note, cost: x.cost, slot: x.slot || null, reasons: x.reasons || [] }; }),
        };
        if (!body.title) { say("Hãy nhập tên chuyến đi.", true); return; }
        $("tpSave").disabled = true;
        say("Đang lưu…");
        post(cfg.saveUrl, body)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok) { say("Đã lưu."); location.reload(); return; }
                var errs = res.j && res.j.errors ? Object.keys(res.j.errors).map(function (k) { return res.j.errors[k][0]; }) : [];
                say(errs[0] || (res.j && res.j.message) || "Không lưu được, thử lại sau.", true);
                $("tpSave").disabled = false;
            })
            .catch(function () { say("Mất kết nối, chưa lưu được.", true); $("tpSave").disabled = false; });
    }
    function share() {
        var btn = $("tpShareBtn"), inp = $("tpShareUrl");
        var on = inp.hidden;
        btn.disabled = true;
        post(cfg.shareUrl, { on: on ? 1 : 0 })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.url) { inp.value = j.url; inp.hidden = false; btn.textContent = "Tắt chia sẻ"; inp.select(); }
                else { inp.value = ""; inp.hidden = true; btn.textContent = "Bật liên kết chia sẻ"; }
            })
            .catch(function () { /* giữ trạng thái cũ */ })
            .then(function () { btn.disabled = false; });
    }

    $("tpSave").addEventListener("click", save);
    if ($("tpShareBtn")) $("tpShareBtn").addEventListener("click", share);
    ["tpDays", "tpStart"].forEach(function (id) { $(id).addEventListener("change", render); });
    $("tpBudget").addEventListener("input", renderTotals);
    initMap();
    render();
})();
