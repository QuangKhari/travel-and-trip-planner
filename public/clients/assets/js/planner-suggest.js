/* Trang "Để Travela đề xuất": ô chọn nơi đến (điểm đến Travela + nơi bất kỳ trên bản đồ), nút +/−, chọn tháng,
 * tóm tắt trực tiếp và kiểm tra trước khi gửi. Mọi chữ động gán bằng textContent (không innerHTML). */
(function () {
    "use strict";
    var form = document.getElementById("sgForm");
    if (!form) return;
    var data = {};
    try { data = JSON.parse(document.getElementById("sgData").textContent) || {}; } catch (e) { data = {}; }
    var DESTS = data.dests || [];
    var $ = function (id) { return document.getElementById(id); };
    var input = $("sgWhere"), list = $("sgWhereList"), picked = $("sgPicked");
    var destId = $("sgDestId"), pName = $("sgPlaceName"), pLat = $("sgPlaceLat"), pLng = $("sgPlaceLng");
    var state = { sel: null, opts: [], active: -1, seq: 0, timer: null };
    var start = $("sgStart"), month = $("sgMonth"), budget = $("sgBudget"), ready = false;   // ready: chỉ vẽ tóm tắt sau khi gắn xong mọi phần

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function icon(cls) { var i = el("i", "fas " + cls); i.setAttribute("aria-hidden", "true"); return i; }
    function fold(s) {
        return String(s || "").toLowerCase().replace(/đ/g, "d").normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, " ").trim();
    }
    function money(n) { return Math.round(n).toLocaleString("vi-VN") + "đ"; }
    function short(n) { return n >= 1e6 ? (Math.round(n / 1e5) / 10).toLocaleString("vi-VN") + " triệu" : money(n); }

    /* ---------- ô chọn nơi đến ---------- */
    function modeOf(o) {
        if (o.type === "place") return "open";
        return o.ready ? "curated" : "open";
    }
    function select(o) {
        state.sel = o;
        if (o.type === "destination") { destId.value = o.id; pName.value = ""; pLat.value = ""; pLng.value = ""; }
        else { destId.value = ""; pName.value = o.name; pLat.value = o.lat; pLng.value = o.lng; }
        input.value = "";
        close();
        renderPicked();
        $("sgWhereErr").hidden = true;
        document.querySelectorAll(".tpf-dest").forEach(function (b) { b.classList.toggle("is-on", o.type === "destination" && String(b.dataset.dest) === String(o.id)); b.setAttribute("aria-pressed", o.type === "destination" && String(b.dataset.dest) === String(o.id) ? "true" : "false"); });
        summary();
    }
    function clearSel() {
        state.sel = null; destId.value = ""; pName.value = ""; pLat.value = ""; pLng.value = "";
        renderPicked();
        document.querySelectorAll(".tpf-dest").forEach(function (b) { b.classList.remove("is-on"); b.setAttribute("aria-pressed", "false"); });
        summary();
        input.focus();
    }
    function renderPicked() {
        picked.textContent = "";
        var o = state.sel;
        picked.hidden = !o;
        input.parentNode.hidden = !!o;
        if (!o) return;
        var m = modeOf(o);
        picked.appendChild(icon(o.type === "place" ? "fa-map-marker-alt" : "fa-map-signs"));
        var t = el("span", "tpf-picked__t");
        t.appendChild(el("b", null, o.name));
        t.appendChild(el("small", null, o.sub || (o.type === "place" ? "Nơi trên bản đồ" : "")));
        picked.appendChild(t);
        picked.appendChild(el("span", "tpf-badge " + (m === "curated" ? "is-ready" : "is-open"), m === "curated" ? "Đủ dữ liệu biên tập" : "Đề xuất mở rộng"));
        var b = el("button", "tp-mini", "Đổi");
        b.type = "button";
        b.setAttribute("aria-label", "Đổi nơi đến");
        b.addEventListener("click", clearSel);
        picked.appendChild(b);
    }
    function open() { list.hidden = false; input.setAttribute("aria-expanded", "true"); }
    function close() { list.hidden = true; input.setAttribute("aria-expanded", "false"); state.active = -1; input.removeAttribute("aria-activedescendant"); }
    function option(o, i) {
        var li = el("li", "tpf-opt");
        li.id = "sgOpt" + i;
        li.setAttribute("role", "option");
        li.appendChild(icon(o.type === "place" ? "fa-map-marker-alt" : "fa-map-signs"));
        var t = el("span", "tpf-opt__t");
        t.appendChild(el("b", null, o.name));
        if (o.sub) t.appendChild(el("small", null, o.sub));
        li.appendChild(t);
        var m = modeOf(o);
        if (m === "open" && !data.openMode) li.setAttribute("aria-disabled", "true");
        li.appendChild(el("span", "tpf-badge " + (m === "curated" ? "is-ready" : "is-open"), m === "curated" ? "Đủ dữ liệu" : "Mở rộng"));
        li.addEventListener("mousedown", function (e) { e.preventDefault(); if (li.getAttribute("aria-disabled") !== "true") select(o); });
        return li;
    }
    function render(opts, loading, failed) {
        list.textContent = "";
        state.opts = opts;
        var dests = opts.filter(function (o) { return o.type === "destination"; }), places = opts.filter(function (o) { return o.type === "place"; });
        var i = 0;
        if (dests.length) {
            list.appendChild(el("li", "tpf-opt__h", "Điểm đến của Travela")).setAttribute("role", "presentation");
            dests.forEach(function (o) { list.appendChild(option(o, i++)); });
        }
        if (data.openMode) {
            list.appendChild(el("li", "tpf-opt__h", "Nơi khác trên bản đồ")).setAttribute("role", "presentation");
            if (loading) list.appendChild(el("li", "tpf-opt__note", "Đang tìm trên bản đồ…")).setAttribute("role", "presentation");
            else if (failed) list.appendChild(el("li", "tpf-opt__note", "Chưa tra được bản đồ lúc này, thử lại sau ít giây.")).setAttribute("role", "presentation");
            else if (!places.length) list.appendChild(el("li", "tpf-opt__note", "Không thấy nơi nào khác. Thử tên tỉnh hoặc thành phố.")).setAttribute("role", "presentation");
            places.forEach(function (o) { list.appendChild(option(o, i++)); });
        }
        if (!dests.length && !data.openMode) list.appendChild(el("li", "tpf-opt__note", "Không có điểm đến khớp.")).setAttribute("role", "presentation");
        open();
    }
    function search() {
        var q = input.value.trim();
        if (q.length < 1) { close(); return; }
        var f = fold(q);
        // xếp: tên bắt đầu bằng chữ gõ > một từ trong tên bắt đầu bằng chữ gõ > tên chứa > tỉnh chứa; cùng mức thì đủ dữ liệu trước
        var rank = function (d) {
            var n = fold(d.name);
            if (n.indexOf(f) === 0) return 0;
            if ((" " + n).indexOf(" " + f) >= 0) return 1;
            if (n.indexOf(f) >= 0) return 2;
            return fold(d.sub + " " + d.slug).indexOf(f) >= 0 ? 3 : 9;
        };
        var local = DESTS.map(function (d) { return { d: d, r: rank(d) }; }).filter(function (x) { return x.r < 9; })
            .sort(function (a, b) { return a.r - b.r || (b.d.ready - a.d.ready) || (b.d.core - a.d.core) || a.d.name.localeCompare(b.d.name, "vi"); })
            .slice(0, 6).map(function (x) { return { type: "destination", id: x.d.id, name: x.d.name, sub: x.d.sub, ready: x.d.ready }; });
        var mine = ++state.seq;
        if (!data.openMode || q.length < 2) { render(local, false, false); return; }
        render(local, true, false);
        fetch(data.searchUrl + "?q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (j) {
                if (mine !== state.seq) return;
                var known = {};
                local.forEach(function (o) { known[fold(o.name)] = true; });
                var places = (j.places || []).filter(function (p) { return !known[fold(p.name)]; }).slice(0, 6)
                    .map(function (p) { return { type: "place", name: p.name, sub: p.sub, lat: p.lat, lng: p.lng }; });
                render(local.concat(places), false, j.ok === false && !places.length);
            })
            .catch(function () { if (mine === state.seq) render(local, false, true); });
    }
    function move(d) {
        var opts = list.querySelectorAll('[role="option"]:not([aria-disabled="true"])');
        if (!opts.length) return;
        state.active = (state.active + d + opts.length) % opts.length;
        opts.forEach(function (o, i) { o.classList.toggle("is-active", i === state.active); });
        input.setAttribute("aria-activedescendant", opts[state.active].id);
        opts[state.active].scrollIntoView({ block: "nearest" });
    }
    input.addEventListener("input", function () { clearTimeout(state.timer); state.timer = setTimeout(search, 220); });
    input.addEventListener("focus", function () { if (input.value.trim()) search(); });
    input.addEventListener("blur", function () { setTimeout(close, 120); });
    input.addEventListener("keydown", function (e) {
        if (e.key === "ArrowDown") { e.preventDefault(); if (list.hidden) search(); else move(1); }
        else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
        else if (e.key === "Enter") {
            var opts = list.querySelectorAll('[role="option"]:not([aria-disabled="true"])');
            if (!list.hidden && opts.length) { e.preventDefault(); var idx = state.active >= 0 ? state.active : 0; opts[idx].dispatchEvent(new MouseEvent("mousedown")); }
        } else if (e.key === "Escape") { close(); }
    });
    document.querySelectorAll(".tpf-dest").forEach(function (b) {
        b.setAttribute("aria-pressed", "false");
        b.addEventListener("click", function () {
            var d = DESTS.filter(function (x) { return String(x.id) === b.dataset.dest; })[0];
            if (d) select({ type: "destination", id: d.id, name: d.name, sub: d.sub, ready: d.ready });
        });
    });

    /* ---------- nút +/− ---------- */
    document.querySelectorAll(".tpf-stepper").forEach(function (s) {
        var inp = s.querySelector("input"), min = +s.dataset.min, max = +s.dataset.max;
        function clamp() { var v = parseInt(inp.value, 10); if (isNaN(v)) v = min; inp.value = Math.max(min, Math.min(max, v)); sync(); }
        function sync() {
            var v = parseInt(inp.value, 10);
            s.querySelector('[data-d="-1"]').disabled = v <= min;
            s.querySelector('[data-d="1"]').disabled = v >= max;
            summary();
        }
        s.querySelectorAll(".tpf-stepper__b").forEach(function (b) {
            b.addEventListener("click", function () { inp.value = (parseInt(inp.value, 10) || 0) + (+b.dataset.d); clamp(); });
        });
        inp.addEventListener("change", clamp);
        inp.addEventListener("input", sync);
        sync();
    });

    /* ---------- ngày / tháng ---------- */
    function syncMonths() {
        var m = start.value ? parseInt(start.value.slice(5, 7), 10) : parseInt(month.value, 10);
        if (start.value) month.value = m;
        document.querySelectorAll(".tpf-month").forEach(function (b) {
            var on = +b.dataset.m === m;
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-pressed", on ? "true" : "false");
            b.disabled = !!start.value && !on;
        });
        $("sgMonths").classList.toggle("is-locked", !!start.value);
        if (start.value || month.value) $("sgWhenErr").hidden = true;
        summary();
    }
    document.querySelectorAll(".tpf-month").forEach(function (b) {
        b.addEventListener("click", function () { if (start.value) return; month.value = month.value === b.dataset.m ? "" : b.dataset.m; syncMonths(); });
    });
    start.addEventListener("change", syncMonths);

    /* ---------- ngân sách ---------- */
    document.querySelectorAll(".tpf-budget").forEach(function (b) {
        b.addEventListener("click", function () { budget.value = b.dataset.b; summary(); });
    });
    budget.addEventListener("input", summary);
    form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function (x) { x.addEventListener("change", summary); });

    /* ---------- tóm tắt ---------- */
    function summary() {
        if (!ready) return;
        var o = state.sel, days = parseInt($("sgDays").value, 10) || 1, ad = parseInt($("sgAdults").value, 10) || 1, ch = parseInt($("sgChildren").value, 10) || 0;
        $("sgSumDest").textContent = o ? o.name : "Chưa chọn nơi đến";
        var mode = $("sgSumMode");
        mode.hidden = !o;
        if (o) {
            var m = modeOf(o);
            mode.className = "tpf-sum__mode " + (m === "curated" ? "is-ready" : "is-open");
            mode.textContent = m === "curated" ? "Chỉ dùng nội dung Travela đã duyệt" : "Đề xuất mở rộng: có bổ sung từ bản đồ OpenStreetMap";
        }
        var when;
        if (start.value) {
            var d0 = new Date(start.value + "T00:00:00"), d1 = new Date(d0.getTime() + (days - 1) * 864e5);
            var f = function (d) { return ("0" + d.getDate()).slice(-2) + "/" + ("0" + (d.getMonth() + 1)).slice(-2); };
            when = f(d0) + " – " + f(d1) + " · " + days + " ngày";
        } else if (month.value) when = "Tháng " + month.value + " · " + days + " ngày";
        else when = days + " ngày, chưa chọn ngày đi";
        $("sgSumWhen").textContent = when;
        $("sgSumWho").textContent = ad + " người lớn" + (ch ? ", " + ch + " trẻ em" : "");
        var b = parseFloat(budget.value), ppl = ad + ch;
        if (b > 0) {
            $("sgSumBudget").textContent = short(b) + " cả nhóm";
            $("sgPer").textContent = "Khoảng " + money(b / ppl / days) + " mỗi người mỗi ngày. Giá đã biết vượt ngân sách sẽ bị loại.";
        } else { $("sgSumBudget").textContent = "Không giới hạn ngân sách"; $("sgPer").textContent = ""; }
        document.querySelectorAll(".tpf-budget").forEach(function (x) { x.classList.toggle("is-on", +x.dataset.b === b); });
        var pace = form.querySelector('input[name="pace"]:checked'), ints = form.querySelectorAll('input[name="interests[]"]:checked').length;
        var pl = { relaxed: "thong thả", normal: "vừa phải", packed: "dày" };
        $("sgSumStyle").textContent = "Nhịp " + (pl[pace ? pace.value : "normal"]) + (ints ? " · " + ints + " sở thích" : "");
        $("sgMbar").textContent = (o ? o.name : "Chưa chọn nơi đến") + " · " + days + " ngày · " + ppl + " người";
    }

    /* ---------- gửi ---------- */
    form.addEventListener("submit", function (e) {
        var ok = true;
        if (!destId.value && !(pLat.value && pLng.value)) { $("sgWhereErr").hidden = false; ok = false; }
        if (!start.value && !month.value) { $("sgWhenErr").hidden = false; ok = false; }
        if (!ok) {
            e.preventDefault();
            var first = !$("sgWhereErr").hidden ? (state.sel ? picked : input) : $("sgMonths");
            first.scrollIntoView({ behavior: "smooth", block: "center" });
            if (first === input) input.focus();
            return;
        }
        // không gửi các ô rỗng cho đường dẫn gọn
        [pName, pLat, pLng, destId, month, budget, start].forEach(function (x) { if (!x.value) x.disabled = true; });
        form.querySelectorAll('button[type="submit"]').forEach(function (x) { x.disabled = true; x.classList.add("is-busy"); });
    });
    window.addEventListener("pageshow", function () {
        [pName, pLat, pLng, destId, month, budget, start].forEach(function (x) { x.disabled = false; });
        form.querySelectorAll('button[type="submit"]').forEach(function (x) { x.disabled = false; x.classList.remove("is-busy"); });
    });

    /* ---------- điền sẵn ---------- */
    var pre = data.pre || {};
    if (pre.dest) {
        var d = DESTS.filter(function (x) { return x.id === pre.dest; })[0];
        if (d) select({ type: "destination", id: d.id, name: d.name, sub: d.sub, ready: d.ready });
    } else if (pre.place && pre.place.lat != null) {
        select({ type: "place", name: pre.place.name, sub: "Nơi trên bản đồ", lat: pre.place.lat, lng: pre.place.lng });
    }
    ready = true;
    renderPicked();
    syncMonths();

    /* ---------- API nhỏ cho La bàn (planner-ai-brief.js) điền form từ "vé hành trình" ---------- */
    function setNum(id, v) { var x = $(id); if (!x || v == null) return; x.value = v; x.dispatchEvent(new Event("change")); }
    window.TpSuggest = {
        selectDest: function (id) {
            var d = DESTS.filter(function (x) { return String(x.id) === String(id); })[0];
            if (d) select({ type: "destination", id: d.id, name: d.name, sub: d.sub, ready: d.ready });
            return !!d;
        },
        setStart: function (ymd) { start.value = ymd || ""; syncMonths(); },
        setMonth: function (m) { if (start.value) return; month.value = m ? String(m) : ""; syncMonths(); },
        setDays: function (n) { setNum("sgDays", n); },
        setPeople: function (ad, ch) { setNum("sgAdults", ad); setNum("sgChildren", ch == null ? 0 : ch); },
        setBudget: function (b) { budget.value = b == null ? "" : b; summary(); },
        setRadio: function (name, v) { var r = form.querySelector('input[name="' + name + '"][value="' + v + '"]'); if (r) { r.checked = true; summary(); } },
        setChecks: function (name, vals) {
            form.querySelectorAll('input[name="' + name + '[]"]').forEach(function (c) { c.checked = vals.indexOf(c.value) >= 0; });
            summary();
        },
        people: function () { return (parseInt($("sgAdults").value, 10) || 1) + (parseInt($("sgChildren").value, 10) || 0); },
        submit: function () { if (form.requestSubmit) form.requestSubmit(); else form.submit(); },
    };
})();
