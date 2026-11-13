/* Trang sửa chuyến đi: tìm chuyến bay / khách sạn, thêm vào lịch trình, trạng thái chuyến bay (T-36…T-38). */
(function () {
    "use strict";
    var box = document.getElementById("tpTravel");
    if (!box) return;
    var canEdit = box.getAttribute("data-edit") === "1";
    var csrf = box.getAttribute("data-csrf");

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function money(v) { return Math.round(v || 0).toLocaleString("vi-VN") + " đ"; }
    function hm(iso) { return String(iso).substr(11, 5); }
    function req(method, url, data) {
        var opt = { method: method, credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } };
        if (data) { var fd = new FormData(); fd.append("_token", csrf); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); opt.body = fd; }
        return fetch(url, opt).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw j; return j; }); });
    }
    function qs(form) { var o = {}; new FormData(form).forEach(function (v, k) { o[k] = v; }); return o; }
    function errText(e) { return (e && (e.message || (e.errors && Object.values(e.errors)[0][0]))) || "Có lỗi, thử lại sau."; }
    function head(res, j) {
        if (j.error) res.appendChild(el("p", "tp-muted tp-small", j.error));
        if (j.simulated) { var p = el("p", "tp-small"); p.appendChild(el("span", "tp-sim", j.label || "Dữ liệu thử nghiệm")); p.appendChild(document.createTextNode(" Kết quả mô phỏng, không phải giá thật.")); res.appendChild(p); }
    }
    function adder(url, data, btn) {
        btn.disabled = true; btn.textContent = "Đang thêm…";
        req("POST", url, data).then(function () { location.reload(); })
            .catch(function (e) { btn.disabled = false; btn.textContent = "Thêm vào lịch trình"; btn.parentNode.appendChild(el("small", null, errText(e))); });
    }

    // A-12: lưu xác nhận đặt chỗ ngoài + tệp (multipart)
    var cf = document.getElementById("tpConfirmForm");
    if (cf) cf.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var msg = cf.querySelector(".tp-confirm__msg"), btn = cf.querySelector("[type=submit]"), fd = new FormData(cf);
        var file = cf.elements.file && cf.elements.file.files[0];
        if (file && file.size > 5 * 1024 * 1024) { msg.textContent = "Tệp tối đa 5 MB."; return; }
        if (!(file && file.size)) fd.delete("file");
        fd.append("_token", csrf);
        btn.disabled = true; msg.textContent = "Đang lưu…";
        fetch(cf.getAttribute("data-url"), { method: "POST", body: fd, credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw j; return j; }); })
            .then(function (j) { msg.textContent = j.message || "Đã lưu."; location.reload(); })
            .catch(function (e) { btn.disabled = false; msg.textContent = errText(e); });
    });

    var ff = document.getElementById("tpFlightForm");
    ff.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var res = ff.querySelector(".tp-travel__res"), q = qs(ff);
        res.textContent = "Đang tìm…";
        req("GET", box.getAttribute("data-flights") + "?" + new URLSearchParams(q)).then(function (j) {
            res.textContent = ""; head(res, j);
            if (!j.offers.length) { res.appendChild(el("p", "tp-muted tp-small", "Không có chuyến bay phù hợp.")); return; }
            j.offers.forEach(function (o) {
                var row = el("div", "tp-offer"), info = el("div");
                info.appendChild(el("b", null, hm(o.departAt) + " → " + hm(o.arriveAt) + "  ·  " + o.flightNo));
                info.appendChild(el("small", null, o.carrier + " · " + Math.floor(o.durationMin / 60) + "g" + String(o.durationMin % 60).padStart(2, "0") + (o.stops ? " · " + o.stops + " điểm dừng" : " · bay thẳng")));
                row.appendChild(info);
                row.appendChild(el("span", "tp-offer__p", money(o.price) + (o.currency !== "VND" ? " " + o.currency : "")));
                if (canEdit) {
                    var b = el("button", "tp-mini", "Thêm vào lịch trình"); b.type = "button";
                    b.addEventListener("click", function () { adder(box.getAttribute("data-flights"), Object.assign({}, q, { offerId: o.id }), b); });
                    row.appendChild(b);
                }
                res.appendChild(row);
            });
        }).catch(function (e) { res.textContent = errText(e); });
    });

    var hf = document.getElementById("tpHotelForm");
    /* Deep-link Booking.com / Agoda với cùng điểm đến, ngày nhận phòng, số đêm và số khách */
    function deepLinks(name) {
        var q = qs(hf), city = hf.getAttribute("data-city") || "";
        var inD = q.checkIn || "", n = Math.max(1, +q.nights || 1), ad = Math.max(1, +q.adults || 1), out = "";
        if (inD) { var d = new Date(inD + "T00:00:00"); d.setDate(d.getDate() + n); out = d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
        var ss = (name ? name + ", " : "") + city;
        return {
            booking: "https://www.booking.com/searchresults.html?" + new URLSearchParams({ ss: ss, checkin: inD, checkout: out, group_adults: String(ad), no_rooms: String(Math.ceil(ad / 2)) }),
            agoda: "https://www.agoda.com/search?" + new URLSearchParams({ textToSearch: ss, checkIn: inD, checkOut: out, adults: String(ad), rooms: String(Math.ceil(ad / 2)) }),
        };
    }
    function paintDeep() {
        var l = deepLinks("");
        hf.querySelectorAll(".tp-travel__deep [data-deep]").forEach(function (a) { a.href = l[a.getAttribute("data-deep")]; });
    }
    if (hf.querySelector(".tp-travel__deep")) { paintDeep(); hf.addEventListener("input", paintDeep); }
    hf.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var res = hf.querySelector(".tp-travel__res"), q = qs(hf);
        res.textContent = "Đang tìm…";
        req("GET", box.getAttribute("data-hotels") + "?" + new URLSearchParams(q)).then(function (j) {
            res.textContent = ""; head(res, j);
            j.hotels.forEach(function (h) {
                var row = el("div", "tp-offer"), info = el("div");
                info.appendChild(el("b", null, h.name + "  " + "★".repeat(h.stars)));
                info.appendChild(el("small", null, (h.address ? h.address + " · " : "") + "cách trung tâm " + (h.distanceM >= 1000 ? (h.distanceM / 1000).toFixed(1) + " km" : h.distanceM + " m")));
                row.appendChild(info);
                row.appendChild(el("span", "tp-offer__p", "≈ " + money(h.price)));
                if (hf.getAttribute("data-city")) {
                    var dl = el("a", "tp-link tp-small", "Xem trên Booking");
                    dl.href = deepLinks(h.name).booking; dl.target = "_blank"; dl.rel = "noopener nofollow";
                    row.appendChild(dl);
                }
                if (canEdit) {
                    var b = el("button", "tp-mini", "Thêm vào lịch trình"); b.type = "button";
                    b.addEventListener("click", function () { adder(box.getAttribute("data-hotels"), Object.assign({}, q, { hotelId: h.id }), b); });
                    row.appendChild(b);
                }
                res.appendChild(row);
            });
            res.appendChild(el("p", "tp-muted tp-small", "Giá là ước tính cho " + j.nights + " đêm, " + j.rooms + " phòng, chưa phải giá đặt phòng thật."));
        }).catch(function (e) { res.textContent = errText(e); });
    });

    // trạng thái chuyến bay đã thêm (chỉ hỏi cho chuyến trong 2 ngày tới)
    var list = document.getElementById("tpExtList");
    if (list) {
        if (list.querySelector(".fa-plane")) {
            req("GET", box.getAttribute("data-status")).then(function (j) {
                j.flights.forEach(function (f) {
                    var s = f.status, t = list.querySelector('[data-status-for="' + f.externalId + '"]');
                    if (!s || !t) return;
                    t.textContent = s.label + (s.simulated ? " (thử nghiệm)" : "") + (s.stale ? " · lúc " + s.fetchedAt : "");
                    t.title = "Nguồn: " + (s.simulated ? "giả lập" : s.source === "aerodatabox" ? "AeroDataBox" : s.source) + " · cập nhật " + s.fetchedAt;
                    t.classList.toggle("is-bad", s.status === "cancelled");
                    t.classList.toggle("is-warn", s.status === "delayed");
                });
            }).catch(function () {});
        }
        list.addEventListener("click", function (ev) {
            var b = ev.target.closest(".tp-ext__rm");
            if (!b) return;
            var li = b.closest("li");
            b.disabled = true;
            req("POST", box.getAttribute("data-remove") + "/" + li.getAttribute("data-id") + "/xoa", {}).then(function () { location.reload(); })
                .catch(function () { b.disabled = false; });
        });
    }
})();
