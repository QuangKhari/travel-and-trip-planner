/* Đặt chỗ cho chuyến (#tpBook): còn thiếu gì + nơi đặt, việc cần đặt có hạn, đã đặt / đã hủy. Máy chủ: BookingPlan + TravelController::plan*. */
(function () {
    "use strict";
    var root = document.getElementById("tpBook");
    if (!root) return;
    var data = document.getElementById("tpData");
    var cfg = data ? JSON.parse(data.textContent) : {};
    var body = root.querySelector(".tgb__body"), url = root.dataset.url;
    var rmBase = (document.getElementById("tpTravel") || {}).dataset ? document.getElementById("tpTravel").dataset.remove : null;
    var S = null;
    var ICON = { hotel: "fa-hotel", flight: "fa-plane", train: "fa-train", bus: "fa-bus", ticket: "fa-ticket-alt", transport: "fa-route" };
    var KIND = { hotel: "Chỗ ở", flight: "Vé máy bay", train: "Vé tàu", bus: "Vé xe khách", ticket: "Vé tham quan" };

    function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
    function icon(k) { var i = el("i", "fas " + (ICON[k] || "fa-ticket-alt")); i.setAttribute("aria-hidden", "true"); return i; }
    function dmy(s) { if (!s) return ""; var p = String(s).slice(0, 10).split("-"); return p[2] + "/" + p[1]; }
    function req(u, method, payload) {
        return fetch(u, { method: method || "GET", credentials: "same-origin", headers: { "Accept": "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": cfg.csrf, "X-Requested-With": "XMLHttpRequest" }, body: payload ? JSON.stringify(payload) : undefined })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.__ok = r.ok; return j; }); });
    }
    function flash(t, bad) {
        var m = root.querySelector(".tgb__msg");
        if (!m) { m = el("p", "tgb__msg"); m.setAttribute("role", "status"); root.insertBefore(m, body); }
        m.textContent = t || ""; m.classList.toggle("is-bad", !!bad);
    }
    function ymdPlus(ymd, n) {
        var d = new Date(ymd + "T00:00:00"); d.setDate(d.getDate() + n);
        return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
    }
    function apply(j) {
        if (j && j.needs) { S = j; render(); }
        // lịch từng ngày tính lại ngay: đường đi từ / về khách sạn, giờ tới nơi, giờ phải rời đi
        if (j && j.logistics && window.TpPlanner && window.TpPlanner.setLogistics) window.TpPlanner.setLogistics(j.logistics);
        if (j && j.message) flash(j.message, !j.__ok);
        else if (j && !j.__ok) flash(j.errors ? j.errors[Object.keys(j.errors)[0]][0] : "Chưa lưu được.", true);
    }
    function load() { req(url).then(apply).catch(function () { body.textContent = "Chưa tải được danh sách đặt chỗ."; }); }
    function btn(cls, text, fn, label) { var b = el("button", cls, text); b.type = "button"; if (label) b.setAttribute("aria-label", label); b.addEventListener("click", fn); return b; }

    /** Mở biểu mẫu "Lưu xác nhận đặt chỗ đã có" (planner-travel.js) và điền sẵn loại, ngày. */
    function openConfirm(kind, startAt, endAt, title) {
        var f = document.getElementById("tpConfirmForm");
        if (!f) return;
        var d = f.closest("details"); if (d) d.open = true;
        if (f.elements.kind) f.elements.kind.value = kind;
        if (startAt && f.elements.startAt) f.elements.startAt.value = startAt;
        if (f.elements.endAt) f.elements.endAt.value = endAt || "";
        if (title && f.elements.title && !f.elements.title.value) f.elements.title.value = title;
        f.scrollIntoView({ behavior: "smooth", block: "center" });
        setTimeout(function () { f.elements.title.focus(); }, 350);
    }

    function render() {
        body.textContent = "";
        var can = S.canEdit;
        // ---- còn thiếu
        if (!cfg.trip || !cfg.trip.startDate) {
            body.appendChild(el("p", "tgb__ok tgb__ok--muted", "Đặt ngày đi ở phần Thông tin để Travela kiểm tra chỗ ở và vé đi, về."));
        } else if (!S.needs.length) {
            body.appendChild(el("p", "tgb__ok", "Đã có chỗ ở cho mọi đêm và vé đi, về (hoặc bạn đã chọn không cần)."));
        } else {
            var ul = el("ul", "tgb__needs");
            S.needs.forEach(function (n) {
                var li = el("li", "tgb__need");
                var h = el("div", "tgb__nh"); h.appendChild(icon(n.kind)); h.appendChild(el("b", null, n.title)); li.appendChild(h);
                var links = el("div", "tgb__links");
                links.appendChild(el("span", "tgb__lab", "Tìm và đặt:"));
                n.links.forEach(function (l) { var a = el("a", "tgb__lnk", l.label); a.href = l.url; a.target = "_blank"; a.rel = "noopener nofollow"; links.appendChild(a); });
                li.appendChild(links);
                if (can) {
                    var acts = el("div", "tgb__acts");
                    acts.appendChild(btn("tp-mini tgb__primary", "Đã đặt, lưu xác nhận", function () {
                        if (n.kind === "hotel") {
                            var out = new Date(n.checkIn + "T00:00:00"); out.setDate(out.getDate() + n.nights);
                            var o = out.getFullYear() + "-" + String(out.getMonth() + 1).padStart(2, "0") + "-" + String(out.getDate()).padStart(2, "0");
                            openConfirm("hotel", n.checkIn + "T14:00", o + "T12:00", "");
                        } else openConfirm("bus", n.date + "T07:00", "", "");
                    }));
                    if (n.plannedId) acts.appendChild(el("span", "tgb__due", "Đã ghi vào “Cần đặt”"));
                    else acts.appendChild(btn("tp-mini", "Thêm vào cần đặt", function () {
                        var need = new Date((n.checkIn || n.date) + "T00:00:00"); need.setDate(need.getDate() - 7);
                        var t0 = new Date(); if (need < t0) need = t0;
                        var nb = need.getFullYear() + "-" + String(need.getMonth() + 1).padStart(2, "0") + "-" + String(need.getDate()).padStart(2, "0");
                        var payload = { kind: n.kind === "hotel" ? "hotel" : "bus", title: n.kind === "hotel" ? "Chỗ ở " + n.nights + " đêm từ " + dmy(n.checkIn) : (n.key === "go" ? "Vé đi" : "Vé về"),
                            startAt: (n.checkIn || n.date) + (n.kind === "hotel" ? "T14:00" : "T07:00"), needBy: nb, bookUrl: n.links[0].url };
                        if (n.kind === "hotel") payload.endAt = ymdPlus(n.checkIn, n.nights) + "T12:00";   // đủ số đêm còn thiếu, không chỉ 1 đêm
                        req(url, "POST", payload).then(apply);
                    }));
                    acts.appendChild(btn("tp-mini tgb__muted", "Không cần", function () { req(url + "/bo-qua", "POST", { key: n.key, skip: true }).then(apply); },
                        "Không cần nhắc: " + n.title));
                    li.appendChild(acts);
                }
                ul.appendChild(li);
            });
            body.appendChild(ul);
        }
        var sk = Object.keys(S.skip || {});
        if (sk.length && can) {
            var p = el("p", "tgb__skip tp-small");
            p.appendChild(document.createTextNode("Đã tắt nhắc: " + sk.map(function (k) { return { stay: "chỗ ở", go: "vé đi", back: "vé về" }[k]; }).join(", ") + ". "));
            p.appendChild(btn("tps__textbtn", "Bật lại", function () { sk.forEach(function (k) { req(url + "/bo-qua", "POST", { key: k, skip: false }).then(apply); }); }));
            body.appendChild(p);
        }

        // ---- cần đặt
        if (S.planned.length) {
            body.appendChild(el("h3", "tgb__h", "Cần đặt (" + S.planned.length + ")"));
            var pl = el("ul", "tgb__list");
            S.planned.forEach(function (x) {
                var li = el("li", "tgb__it" + (x.overdue ? " is-overdue" : ""));
                li.appendChild(icon(x.kind));
                var m = el("div", "tgb__m"); m.appendChild(el("b", null, x.title));
                var sub = KIND[x.kind] + (x.startAt ? " · " + dmy(x.startAt) : "");
                m.appendChild(el("small", null, sub));
                if (x.outside) m.appendChild(el("span", "tgb__due is-bad", "Ngoài ngày đi của chuyến"));
                if (x.needBy) m.appendChild(el("span", "tgb__due" + (x.overdue ? " is-bad" : ""), (x.overdue ? "Quá hạn đặt " : "Đặt trước ") + dmy(x.needBy)));
                if (x.bookUrl) { var a = el("a", "tgb__lnk", "Mở nơi đặt"); a.href = x.bookUrl; a.target = "_blank"; a.rel = "noopener nofollow"; m.appendChild(a); }
                locLine(m, x, can, li);
                li.appendChild(m);
                if (can) {
                    var acts = el("div", "tgb__acts");
                    acts.appendChild(btn("tp-mini tgb__primary", "Đã đặt", function () { bookedForm(li, x); }, "Đánh dấu đã đặt " + x.title));
                    if (rmBase) acts.appendChild(btn("tp-mini tp-mini--danger", "Xóa", function () {
                        if (!window.confirm("Xóa \"" + x.title + "\" khỏi danh sách cần đặt?")) return;
                        req(rmBase + "/" + x.id + "/xoa", "POST", {}).then(function () { load(); });
                    }, "Xóa " + x.title));
                    li.appendChild(acts);
                }
                pl.appendChild(li);
            });
            body.appendChild(pl);
        }

        // ---- đã đặt (đổi trạng thái)
        if (S.booked.length) {
            body.appendChild(el("h3", "tgb__h", "Đã đặt (" + S.booked.length + ")"));
            var bl = el("ul", "tgb__list");
            S.booked.forEach(function (x) {
                var li = el("li", "tgb__it is-booked");
                li.appendChild(icon(x.kind));
                var m = el("div", "tgb__m"); m.appendChild(el("b", null, x.title));
                m.appendChild(el("small", null, KIND[x.kind] + " · " + dmy(x.startAt) + (x.endAt ? "–" + dmy(x.endAt) : "") + (x.code ? " · mã " + x.code : "") + (x.simulated ? " · dữ liệu thử nghiệm" : "")));
                if (x.outside) m.appendChild(el("span", "tgb__due is-bad", "Ngoài ngày đi của chuyến — kiểm tra lại hoặc đổi đặt chỗ"));
                locLine(m, x, can, li);
                li.appendChild(m);
                if (can) {
                    var acts = el("div", "tgb__acts");
                    acts.appendChild(btn("tp-mini", "Đã hủy", function () {
                        if (!window.confirm("Đánh dấu \"" + x.title + "\" đã hủy? Mục tương ứng sẽ được gỡ khỏi lịch.")) return;
                        req(url + "/" + x.id, "POST", { status: "cancelled" }).then(function (j) { apply(j); setTimeout(function () { location.reload(); }, 900); });
                    }, "Đánh dấu đã hủy " + x.title));
                    li.appendChild(acts);
                }
                bl.appendChild(li);
            });
            body.appendChild(bl);
        }
        if (S.cancelled) body.appendChild(el("p", "tp-muted tp-small", S.cancelled + " đặt chỗ đã hủy (giữ lại để đối chiếu)."));
        if (can) body.appendChild(addForm());
    }

    /** Chỗ ở: vị trí (để lịch tính đường từ / về khách sạn) + nút gắn / đổi vị trí. */
    function locLine(m, x, can, li) {
        if (x.kind !== "hotel") return;
        var p = el("span", "tgb__loc" + (x.lat == null ? " is-missing" : ""));
        var ic = el("i", "fas fa-map-marker-alt"); ic.setAttribute("aria-hidden", "true"); p.appendChild(ic);
        p.appendChild(document.createTextNode(x.lat == null ? " Chưa có vị trí: lịch chưa tính đường đi từ khách sạn" : " " + (x.address || "Đã gắn vị trí")));
        if (can) p.appendChild(btn("tp-link tp-small", x.lat == null ? "Gắn vị trí" : "Đổi", function () { locateForm(li, x); }, "Gắn vị trí cho " + x.title));
        m.appendChild(p);
    }
    function locateForm(li, x) {
        var old = li.querySelector(".tgb__locate"); if (old) { old.remove(); return; }
        var f = el("form", "tgb__locate");
        var inp = el("input"); inp.type = "search"; inp.maxLength = 80; inp.value = x.title.replace(/^Chỗ ở \d+ đêm từ .*$/, "").replace(/\s*\(\d+ đêm\)\s*$/, "");
        inp.placeholder = "Tên khách sạn hoặc địa chỉ"; inp.setAttribute("aria-label", "Tìm vị trí chỗ ở");
        var go = el("button", "tp-mini tgb__primary", "Tìm"); go.type = "submit";
        var res = el("ul", "tgb__locres");
        f.appendChild(inp); f.appendChild(go);
        if (window.TpPlanner && window.TpPlanner.pickOnMap) f.appendChild(btn("tp-mini", "Chọn trên bản đồ", function () {
            var ok = window.TpPlanner.pickOnMap(function (pos) {
                req(url + "/" + x.id + "/vi-tri", "POST", { lat: pos.lat, lng: pos.lng, address: "Vị trí chọn trên bản đồ" }).then(apply);
            });
            if (ok) flash("Bấm vào vị trí chỗ ở trên bản đồ (Esc để hủy).");
        }));
        if (x.lat != null) f.appendChild(btn("tp-mini tgb__muted", "Bỏ vị trí", function () { req(url + "/" + x.id + "/vi-tri", "POST", { clear: true }).then(apply); }));
        f.appendChild(res);
        f.addEventListener("submit", function (e) {
            e.preventDefault();
            var q = inp.value.trim();
            if (q.length < 2) { inp.focus(); return; }
            res.textContent = "";
            res.appendChild(el("li", "tp-muted tp-small", "Đang tìm…"));
            var c = S.center || {}, jobs = [];
            if (cfg.searchUrl) jobs.push(req(cfg.searchUrl + "?type=place&q=" + encodeURIComponent(q)).then(function (j) {
                return (j.results || []).filter(function (r) { return r.lat != null && r.lng != null; }).map(function (r) { return { name: r.name, sub: [r.sub, r.destName].filter(Boolean).join(" · "), lat: r.lat, lng: r.lng }; });
            }).catch(function () { return []; }));
            if (cfg.searchExtUrl) jobs.push(req(cfg.searchExtUrl + "?q=" + encodeURIComponent(q) + (c.lat != null ? "&lat=" + c.lat + "&lng=" + c.lng : "")).then(function (j) {
                return (j.results || []).map(function (r) { return { name: r.name, sub: r.address || "", lat: r.lat, lng: r.lng }; });
            }).catch(function () { return []; }));
            Promise.all(jobs).then(function (lists) {
                var all = [].concat.apply([], lists).slice(0, 8);
                res.textContent = "";
                if (!all.length) { res.appendChild(el("li", "tp-muted tp-small", "Không tìm thấy. Thử tên ngắn hơn hoặc kèm tên đường.")); return; }
                all.forEach(function (r) {
                    var it = el("li");
                    var b = btn("tgb__pick", null, function () {
                        req(url + "/" + x.id + "/vi-tri", "POST", { lat: r.lat, lng: r.lng, address: [r.name, r.sub].filter(Boolean).join(", ") }).then(apply);
                    }, "Chọn " + r.name);
                    b.appendChild(el("b", null, r.name));
                    if (r.sub) b.appendChild(el("small", null, r.sub));
                    it.appendChild(b);
                    res.appendChild(it);
                });
            });
        });
        li.appendChild(f);
        inp.focus();
    }

    function bookedForm(li, x) {
        if (li.querySelector(".tgb__bf")) return;
        var f = el("form", "tgb__bf");
        f.innerHTML = "<label>Mã xác nhận<input name='code' maxlength='40' placeholder='Không bắt buộc'></label>" +
            "<label>Ngày giờ<input type='datetime-local' name='startAt'></label><label>Đã trả (đ)<input name='price' inputmode='numeric' placeholder='0'></label>" +
            "<button class='tp-btn' type='submit'>Lưu và đưa vào lịch</button>";
        if (x.startAt) f.elements.startAt.value = String(x.startAt).replace(" ", "T").slice(0, 16);
        f.addEventListener("submit", function (e) {
            e.preventDefault();
            var p = { status: "booked", code: f.elements.code.value || null, startAt: f.elements.startAt.value || null, price: parseInt(String(f.elements.price.value).replace(/\D/g, ""), 10) || null };
            req(url + "/" + x.id, "POST", p).then(function (j) { apply(j); if (j.__ok) setTimeout(function () { location.reload(); }, 900); });
        });
        li.appendChild(f);
        f.elements.code.focus();
    }

    function addForm() {
        var d = el("details", "tgb__add");
        d.appendChild(el("summary", null, "Thêm việc cần đặt khác"));
        var f = el("form");
        f.innerHTML = "<div class='tgb__grid'>" +
            "<label>Loại<select name='kind' class='no-nice'></select></label>" +
            "<label class='tgb__w2'>Tên<input name='title' maxlength='120' required placeholder='Ví dụ: Vé cáp treo Trúc Lâm'></label>" +
            "<label>Ngày dùng<input type='datetime-local' name='startAt'></label>" +
            "<label>Đặt trước ngày<input type='date' name='needBy'></label>" +
            "<label class='tgb__w2'>Nơi đặt (đường dẫn)<input type='url' name='bookUrl' maxlength='500' placeholder='https://'></label>" +
            "</div><button type='submit' class='tp-btn tp-btn--ghost'>Thêm vào cần đặt</button>";
        Object.keys(KIND).forEach(function (k) { var o = el("option", null, KIND[k]); o.value = k; f.elements.kind.appendChild(o); });
        f.addEventListener("submit", function (e) {
            e.preventDefault();
            req(url, "POST", { kind: f.elements.kind.value, title: f.elements.title.value, startAt: f.elements.startAt.value || null, needBy: f.elements.needBy.value || null, bookUrl: f.elements.bookUrl.value || null }).then(apply);
        });
        d.appendChild(f);
        return d;
    }

    var loaded = false;
    function maybeLoad() { if (!loaded && !root.closest("[hidden]")) { loaded = true; load(); } }
    document.addEventListener("click", function (e) { if (e.target.closest('[data-tab="info"]')) setTimeout(maybeLoad, 50); });
    maybeLoad();
    // từ thẻ "Sắp đi" ở Lịch trình (#tpBook): tab Thông tin vừa mở
    var mo = new MutationObserver(maybeLoad); var panel = root.closest(".tp-panel"); if (panel) mo.observe(panel, { attributes: true, attributeFilter: ["hidden"] });
})();
