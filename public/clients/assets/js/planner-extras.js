/* Trang sửa chuyến đi: danh sách đồ mang theo (A-13) và tải lịch trình PDF (A-10d).
   Không phụ thuộc planner.js; đọc URL từ data-* trên #tpPack và #tpPdf. */
(function () {
    "use strict";

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function getJson(url) {
        return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); });
    }

    /* ---------------- Đồ mang theo ---------------- */
    var box = document.getElementById("tpPack");
    if (box) {
        var key = "tp.pack." + box.getAttribute("data-trip");
        var done = {};
        try { done = JSON.parse(localStorage.getItem(key) || "{}") || {}; } catch (e) { done = {}; }
        var list = box.querySelector(".tp-pack__list");
        var status = box.querySelector(".tp-pack__status");
        var btn = box.querySelector(".tp-pack__go");

        function save() { try { localStorage.setItem(key, JSON.stringify(done)); } catch (e) { /* trình duyệt chặn lưu: bỏ qua */ } }
        function progress() {
            var all = list.querySelectorAll("input[type=checkbox]"), n = 0;
            all.forEach(function (c) { if (c.checked) n++; });
            box.querySelector(".tp-pack__count").textContent = all.length ? n + "/" + all.length + " món đã chuẩn bị" : "";
        }
        function render(d) {
            list.textContent = "";
            (d.groups || []).forEach(function (g, gi) {
                var sec = el("div", "tp-pack__grp");
                sec.appendChild(el("h3", null, g.name));
                var ul = el("ul");
                g.items.forEach(function (it, ii) {
                    var id = "tpk-" + gi + "-" + ii, k = g.name + "|" + it.name;
                    var li = el("li"), cb = el("input"), lb = el("label");
                    cb.type = "checkbox"; cb.id = id; cb.checked = !!done[k];
                    cb.addEventListener("change", function () { if (cb.checked) done[k] = 1; else delete done[k]; save(); progress(); });
                    lb.htmlFor = id; lb.appendChild(document.createTextNode(it.name));
                    if (it.why) lb.appendChild(el("small", null, it.why));
                    li.appendChild(cb); li.appendChild(lb); ul.appendChild(li);
                });
                sec.appendChild(ul);
                list.appendChild(sec);
            });
            if (d.note) list.appendChild(el("p", "tp-pack__note", d.note));
            status.textContent = d.source === "ai" ? "Gợi ý bởi La bàn (AI) theo điểm đến, tháng đi và hoạt động trong chuyến. Hãy xem lại trước khi đi."
                : "Danh sách cơ bản theo khí hậu tháng đi và các hoạt động trong chuyến.";
            btn.textContent = "Gợi ý lại";
            progress();
        }
        btn.addEventListener("click", function () {
            btn.disabled = true;
            status.textContent = "Đang soạn danh sách…";
            getJson(box.getAttribute("data-url")).then(render)
                .catch(function () { status.textContent = "Chưa lấy được danh sách. Thử lại sau."; })
                .finally(function () { btn.disabled = false; });
        });
    }

    /* ---------------- PDF lịch trình ---------------- */
    var pdfBtn = document.getElementById("tpPdf");
    if (!pdfBtn) return;

    function loadScript(src) {
        return new Promise(function (ok, fail) {
            var s = document.createElement("script");
            s.src = src; s.onload = ok; s.onerror = fail;
            document.head.appendChild(s);
        });
    }
    function money(v) { return Math.round(v || 0).toLocaleString("vi-VN") + " đ"; }

    function build(d) {
        var c = [];
        c.push({ text: d.title, style: "h1" });
        c.push({ text: d.meta, color: "#557080", margin: [0, 0, 0, 10] });
        if (d.cost || d.budget) {
            c.push({ text: "Chi phí dự kiến: " + money(d.cost) + (d.budget ? "  ·  Ngân sách: " + money(d.budget) : ""), margin: [0, 0, 0, 10] });
        }
        d.days.forEach(function (day) {
            if (!day.items.length) return;
            c.push({ text: "Ngày " + day.day + (day.date ? " — " + day.date : ""), style: "h2" });
            var body = [[{ text: "Giờ", style: "th" }, { text: "Hoạt động", style: "th" }, { text: "Ghi chú", style: "th" }, { text: "Chi phí", style: "th", alignment: "right" }]];
            day.items.forEach(function (it) {
                body.push([
                    { text: it.time || "", color: "#557080" },
                    { stack: [{ text: it.name, bold: true }, { text: [it.type, it.sub].filter(Boolean).join(" · "), color: "#6b8592", fontSize: 8 }] },
                    { text: it.note || "", fontSize: 9 },
                    { text: it.cost ? money(it.cost) : "", alignment: "right" },
                ]);
            });
            c.push({ table: { headerRows: 1, widths: [38, "*", "*", 70], body: body }, layout: "lightHorizontalLines", margin: [0, 0, 0, 4] });
            if (day.km) c.push({ text: "Di chuyển khoảng " + day.km + " km (ước tính)", fontSize: 8, color: "#6b8592", margin: [0, 0, 0, 8] });
        });
        if (d.unassigned.length) {
            c.push({ text: "Chưa xếp ngày", style: "h2" });
            c.push({ ul: d.unassigned, margin: [0, 0, 0, 8] });
        }
        var rainDays = Object.keys(d.rain || {});
        if (rainDays.length) {
            c.push({ text: "Phương án khi trời mưa", style: "h2" });
            rainDays.forEach(function (k) { c.push({ text: "Ngày " + k + ": " + d.rain[k].map(function (r) { return r.name; }).join(", "), margin: [0, 0, 0, 3] }); });
        }
        if (d.bookings.length) {
            c.push({ text: "Đơn đặt tour", style: "h2" });
            var bb = [[{ text: "Mã đơn", style: "th" }, { text: "Tour", style: "th" }, { text: "Khởi hành", style: "th" }, { text: "Khách", style: "th" }, { text: "Tổng tiền", style: "th", alignment: "right" }, { text: "Trạng thái", style: "th" }]];
            d.bookings.forEach(function (b) {
                bb.push([b.code, b.title, b.date, String(b.people), { text: money(b.total), alignment: "right" }, b.status + (b.paid ? " · đã thanh toán" : " · chưa thanh toán")]);
            });
            c.push({ table: { headerRows: 1, widths: [80, "*", 52, 30, 62, 80], body: bb }, layout: "lightHorizontalLines", fontSize: 8.5 });
        }
        if (d.packing.length) {
            c.push({ text: "Đồ mang theo", style: "h2", pageBreak: d.days.length > 2 ? "before" : undefined });
            c.push({ columns: [0, 1].map(function (col) {
                return { stack: d.packing.filter(function (_, i) { return i % 2 === col; }).map(function (g) {
                    return { stack: [{ text: g.name, bold: true, margin: [0, 4, 0, 2] }, { ul: g.items.map(function (i) { return "☐ " + i.name; }), type: "none", fontSize: 9 }] };
                }) };
            }), columnGap: 16 });
        }
        return {
            info: { title: d.title },
            pageMargins: [36, 40, 36, 44],
            content: c,
            defaultStyle: { fontSize: 9.5, lineHeight: 1.2 },
            styles: { h1: { fontSize: 18, bold: true, color: "#0f2f3a" }, h2: { fontSize: 12.5, bold: true, color: "#168F99", margin: [0, 10, 0, 5] }, th: { bold: true, color: "#557080", fontSize: 8.5 } },
            footer: function (p, n) { return { text: d.site + " · tạo lúc " + d.generatedAt + " · trang " + p + "/" + n, alignment: "center", fontSize: 7.5, color: "#94a3b8", margin: [0, 16, 0, 0] }; },
        };
    }

    pdfBtn.addEventListener("click", function () {
        var label = pdfBtn.querySelector("span") || pdfBtn;
        var old = label.textContent;
        label.textContent = "Đang tạo PDF…";
        pdfBtn.disabled = true;
        var base = pdfBtn.getAttribute("data-lib");
        (window.pdfMake ? Promise.resolve() : loadScript(base + "/pdfmake.min.js").then(function () { return loadScript(base + "/vfs_fonts.js"); }))
            .then(function () { return getJson(pdfBtn.getAttribute("data-url")); })
            .then(function (d) {
                var name = (d.title || "lich-trinh").normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D")
                    .replace(/[^A-Za-z0-9]+/g, "-").replace(/^-|-$/g, "").toLowerCase();
                window.pdfMake.createPdf(build(d)).download(name + ".pdf");
            })
            .catch(function () {
                var m = document.getElementById("tpMsg");
                if (m) m.textContent = "Chưa tạo được PDF. Hãy dùng “In / lưu thành PDF”.";
            })
            .finally(function () { label.textContent = old; pdfBtn.disabled = false; });
    });

    /* ---------------- A-05: ẩn banner "có tour giống lịch trình này" (nhớ theo tour, trong phiên trình duyệt) ---------------- */
    var tb = document.getElementById("tpTourBanner");
    if (tb) {
        var x = tb.querySelector("[data-tm-dismiss]");
        var tbKey = "tp.tourBanner." + (x ? x.getAttribute("data-tm-dismiss") : "");
        try { if (sessionStorage.getItem(tbKey)) tb.hidden = true; } catch (e) { /* bỏ qua */ }
        if (x) x.addEventListener("click", function () {
            tb.hidden = true;
            try { sessionStorage.setItem(tbKey, "1"); } catch (e) { /* bỏ qua */ }
        });
    }
})();
