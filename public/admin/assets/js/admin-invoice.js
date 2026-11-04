/* Hóa đơn PDF (D-10): dựng bằng pdfmake ngay trên trình duyệt (font Roboto trong vfs_fonts có đủ dấu tiếng Việt).
 * "Tải PDF" lưu file; "Gửi PDF cho khách" gửi file (base64) lên máy chủ để đính kèm email (qua hàng đợi). */
(function () {
    "use strict";
    var el = document.getElementById("inv-data");
    if (!el || !window.pdfMake) return;
    var d = JSON.parse(el.textContent);
    var teal = "#168F99", ink = "#0f172a", mute = "#64748b", line = "#e2e8f0";

    function doc() {
        var rows = [[{ text: "Hạng mục", style: "th" }, { text: "Số lượng", style: "th", alignment: "right" }, { text: "Đơn giá", style: "th", alignment: "right" }, { text: "Thành tiền", style: "th", alignment: "right" }]];
        d.lines.forEach(function (l) { rows.push([l[0], { text: String(l[1]), alignment: "right" }, { text: l[2], alignment: "right" }, { text: l[3], alignment: "right" }]); });
        var totals = [["Tạm tính", d.subtotal]];
        if (d.discount) totals.push(["Giảm giá", d.discount]);
        totals.push([{ text: "Tổng thanh toán", bold: true, fontSize: 12 }, { text: d.total, bold: true, fontSize: 12, color: teal }]);
        if (d.refund) totals.push([{ text: "Hoàn tiền", color: "#991b1b" }, { text: d.refund, color: "#991b1b" }]);

        return {
            pageSize: "A4", pageMargins: [40, 40, 40, 50],
            info: { title: "Hóa đơn " + d.code },
            defaultStyle: { font: "Roboto", fontSize: 10, color: ink, lineHeight: 1.25 },
            styles: { th: { bold: true, color: mute, fontSize: 9, fillColor: "#f8fafc" }, h: { fontSize: 9, color: mute, bold: true, margin: [0, 0, 0, 4] } },
            footer: function (p, n) { return { text: d.site[0] + " · " + d.site[2] + " · " + d.site[3] + "      Trang " + p + "/" + n, alignment: "center", fontSize: 8, color: mute, margin: [0, 20, 0, 0] }; },
            content: [
                { columns: [
                    { stack: [{ text: d.site[0].toUpperCase(), fontSize: 20, bold: true, color: teal }, { text: "Du lịch trong nước", color: mute, fontSize: 9 }] },
                    { stack: [{ text: "HÓA ĐƠN", fontSize: 18, bold: true, alignment: "right" },
                              { text: (d.invoiceNo || "Chưa cấp số") + "  ·  " + d.date, alignment: "right", color: mute },
                              { text: "Mã đơn " + d.code, alignment: "right", bold: true }] },
                ] },
                { canvas: [{ type: "line", x1: 0, y1: 8, x2: 515, y2: 8, lineWidth: 2, lineColor: teal }], margin: [0, 0, 0, 14] },
                { columns: [
                    { stack: [{ text: "KHÁCH HÀNG", style: "h" }].concat(d.customer.map(function (t, i) { return { text: t || "", bold: i === 0 }; })) },
                    { stack: [{ text: "ĐƠN VỊ CUNG CẤP", style: "h" }].concat(d.site.map(function (t, i) { return { text: t || "", bold: i === 0 }; })) },
                    { stack: [{ text: "THANH TOÁN", style: "h" }, d.method, "Trạng thái: " + d.pay, d.paidAt ? "Ngày thu: " + d.paidAt : ""] },
                ], columnGap: 16, margin: [0, 0, 0, 16] },
                { text: d.title, fontSize: 13, bold: true, margin: [0, 0, 0, 2] },
                { text: "Lịch trình: " + d.trip + "  ·  " + (d.time || "") + "  ·  " + (d.destination || ""), color: mute, margin: [0, 0, 0, 10] },
                { table: { headerRows: 1, widths: ["*", 60, 90, 100], body: rows },
                  layout: { hLineColor: function () { return line; }, vLineWidth: function () { return 0; }, paddingTop: function () { return 6; }, paddingBottom: function () { return 6; } } },
                { columns: [{ text: "" }, { width: 240, table: { widths: ["*", "auto"], body: totals.map(function (r) { return [r[0], { text: r[1].text || r[1], alignment: "right", bold: r[1].bold, color: r[1].color, fontSize: r[1].fontSize }]; }) }, layout: "noBorders", margin: [0, 12, 0, 0] }] },
                { text: "Cảm ơn quý khách đã đồng hành cùng " + d.site[0] + ". Hóa đơn được tạo tự động từ hệ thống quản trị.", color: mute, fontSize: 9, margin: [0, 28, 0, 0] },
            ],
        };
    }

    var dl = document.getElementById("inv-download");
    if (dl) dl.addEventListener("click", function () { pdfMake.createPdf(doc()).download("hoa-don-" + d.code + ".pdf"); });

    var send = document.getElementById("inv-send");
    if (send) send.addEventListener("click", function () {
        var go = function () {
            send.disabled = true;
            pdfMake.createPdf(doc()).getBase64(function (b64) {
                var fd = new FormData();
                fd.append("bookingId", send.dataset.id);
                fd.append("pdf", b64);
                fd.append("_token", document.querySelector('meta[name="csrf-token"]').content);
                fetch(send.dataset.url, { method: "POST", body: fd, headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
                    .then(function (r) { return r.json(); })
                    .then(function (r) { toastr[r.success ? "success" : "error"](r.message); if (r.success) setTimeout(function () { location.reload(); }, 900); })
                    .catch(function () { toastr.error("Không gửi được, vui lòng thử lại."); })
                    .finally(function () { send.disabled = false; });
            });
        };
        if (!window.Swal) return go();
        Swal.fire({ title: "Gửi hóa đơn PDF?", html: "Hóa đơn sẽ được gửi tới <b>" + send.dataset.email + "</b>.", icon: "question", showCancelButton: true, confirmButtonText: "Gửi", cancelButtonText: "Thôi", confirmButtonColor: "#168F99", reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) go(); });
    });
})();
