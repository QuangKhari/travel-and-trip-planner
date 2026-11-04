/* Đơn đặt tour (danh sách + chi tiết): xác nhận, ghi nhận thanh toán, hoàn tất, hủy (kèm hoàn tiền).
 * Nút: <button class="js-bk" data-act="confirm|paid|finish|cancel" data-id data-code data-url>.
 * Trang danh sách: máy chủ trả lại <tbody> và số đếm; trang chi tiết (data-context="detail"): tải lại trang. */
(function () {
    "use strict";
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute("content");
    var card = document.getElementById("bk-card");
    var detail = document.body.querySelector('[data-context="detail"]');
    var money = function (n) { return Number(n || 0).toLocaleString("vi-VN") + " đ"; };

    function post(url, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
        fd.append("_token", token);
        if (detail) fd.append("context", "detail");
        return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: "Phản hồi không hợp lệ (" + r.status + ")." }; }); });
    }

    function done(res) {
        if (!res.success) { toastr.error(res.message || "Không thực hiện được."); return; }
        toastr.success(res.message);
        if (detail) { setTimeout(function () { location.reload(); }, 600); return; }
        var tb = document.getElementById("tbody-booking");
        if (tb && res.data !== undefined) tb.innerHTML = res.data;
        if (res.counts) Object.keys(res.counts).forEach(function (k) {
            var b = document.querySelector('[data-count="' + k + '"]');
            if (b) b.textContent = res.counts[k];
        });
    }

    function ask(opts) {
        if (!window.Swal) return Promise.resolve(confirm(opts.text) ? { isConfirmed: true, value: {} } : { isConfirmed: false });
        return Swal.fire(Object.assign({ icon: "question", showCancelButton: true, cancelButtonText: "Thôi", confirmButtonColor: "#168F99", reverseButtons: true, focusCancel: false }, opts));
    }

    var TEXT = {
        confirm: function (b) { return { title: "Xác nhận đơn " + b.dataset.code + "?", text: "Chỗ đang giữ sẽ chuyển thành chỗ đã bán và khách nhận email xác nhận.", confirmButtonText: "Xác nhận đơn" }; },
        paid: function (b) { return { title: "Đã thu tiền?", html: "Ghi nhận khách đã thanh toán <b>" + (b.dataset.amount || "") + " đ</b> cho đơn <b>" + b.dataset.code + "</b>.<br>Đơn sẽ không còn bị tự hủy vì hết hạn giữ chỗ.", confirmButtonText: "Đã thu tiền" }; },
        finish: function (b) { return { title: "Hoàn tất đơn " + b.dataset.code + "?", text: "Dùng khi chuyến đi đã kết thúc. Khách sẽ đánh giá được tour.", confirmButtonText: "Hoàn tất" }; },
    };

    function cancelFlow(b) {
        var previewUrl = (card && card.dataset.urlPreview) || (detail && detail.dataset.urlPreview);
        var go = function (p) {
            var paid = p && p.paid;
            var html = paid
                ? '<p style="margin:0 0 10px">Đơn đã thanh toán <b>' + money(p.amount) + "</b>, còn <b>" + p.days + " ngày</b> đến ngày khởi hành. Theo chính sách hoàn <b>" + p.percent + "%</b>.</p>" +
                  '<label style="display:block;text-align:left;font-weight:600;font-size:13px;margin:8px 0 4px">Phần trăm hoàn cho khách</label>' +
                  '<select id="bk-pct" class="swal2-select" style="width:100%;margin:0;display:block">' +
                  [100, 50, 0].map(function (v) { return '<option value="' + v + '"' + (v === p.percent ? " selected" : "") + ">" + v + "%" + (v === p.percent ? " (theo chính sách)" : v === 100 ? " – Travela hủy tour / lỗi phía Travela" : "") + "</option>"; }).join("") +
                  "</select>"
                : '<p style="margin:0 0 10px">Đơn chưa thanh toán: hủy và trả chỗ ngay, không phát sinh hoàn tiền.</p>';
            html += '<label style="display:block;text-align:left;font-weight:600;font-size:13px;margin:12px 0 4px">Lý do (khách sẽ thấy trong email)</label>' +
                '<input id="bk-reason" class="swal2-input" style="width:100%;margin:0" maxlength="255" placeholder="Vd: Khách đổi lịch, tour không đủ khách…">';
            return ask({
                title: "Hủy đơn " + b.dataset.code + "?", html: html, icon: "warning", confirmButtonText: "Hủy đơn", confirmButtonColor: "#dc2626",
                preConfirm: function () {
                    var pct = document.getElementById("bk-pct");
                    return { reason: document.getElementById("bk-reason").value.trim(), refundPercent: pct ? pct.value : null };
                },
            }).then(function (r) {
                if (!r.isConfirmed) return;
                return post(b.dataset.url, { bookingId: b.dataset.id, reason: r.value.reason, refundPercent: r.value.refundPercent }).then(done);
            });
        };
        if (!previewUrl) return go(null);
        return fetch(previewUrl + "?bookingId=" + encodeURIComponent(b.dataset.id), { headers: { Accept: "application/json" } })
            .then(function (r) { return r.json(); }).then(go).catch(function () { go(null); });
    }

    document.addEventListener("click", function (e) {
        var b = e.target.closest(".js-bk");
        if (!b || b.disabled) return;
        e.preventDefault();
        e.stopPropagation();
        var act = b.dataset.act;
        if (act === "cancel") { cancelFlow(b); return; }
        ask(TEXT[act](b)).then(function (r) {
            if (!r.isConfirmed) return;
            b.disabled = true;
            post(b.dataset.url, { bookingId: b.dataset.id }).then(done).finally(function () { b.disabled = false; });
        });
    });
})();
