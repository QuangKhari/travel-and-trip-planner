/* Travela Admin UI v2 — chức năng nhỏ dùng chung cho mọi trang admin.
 * 1) data-confirm="..."  : hỏi xác nhận trước khi gửi form / bấm nút (dùng SweetAlert nếu có)
 * 2) .ad-flash[data-auto]: tự ẩn thông báo sau 6 giây
 * 3) menu: bật trạng thái mở cho nhóm con đang xem (server đã gắn class ad-on)
 * 4) chip lọc bảng danh sách phía trình duyệt: [data-ad-status-chip] (xem admin-paginate.js)
 */
(function () {
    "use strict";

    // ---- 1) xác nhận ----
    document.addEventListener(
        "click",
        function (ev) {
            var el = ev.target.closest("[data-confirm]");
            if (!el || el.dataset.adOk === "1") return;
            ev.preventDefault();
            ev.stopPropagation();
            var msg = el.getAttribute("data-confirm") || "Bạn chắc chắn?";
            var go = function () {
                if (el.form && el.tagName !== "A") {
                    // nút trong form: requestSubmit giữ name=value của nút và kiểm tra hợp lệ (không phát sinh click)
                    el.form.requestSubmit ? el.form.requestSubmit(el) : el.form.submit();
                    return;
                }
                // liên kết / nút AJAX: bấm lại để các trình xử lý jQuery cũ chạy; cờ adOk tránh hỏi lần hai
                el.dataset.adOk = "1";
                el.click();
                setTimeout(function () {
                    delete el.dataset.adOk;
                }, 0);
            };
            if (window.Swal) {
                Swal.fire({
                    title: "Xác nhận",
                    text: msg,
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "Đồng ý",
                    cancelButtonText: "Hủy",
                    confirmButtonColor: "#0d9488",
                    reverseButtons: true,
                }).then(function (r) {
                    if (r.isConfirmed) go();
                });
            } else if (window.confirm(msg)) {
                go();
            }
        },
        true,
    );

    // ---- 2) tự ẩn thông báo ----
    document.querySelectorAll(".ad-flash[data-auto]").forEach(function (n) {
        setTimeout(function () {
            n.style.transition = "opacity .4s";
            n.style.opacity = "0";
            setTimeout(function () {
                n.remove();
            }, 450);
        }, 6000);
    });

    // ---- 4) chip lọc theo trạng thái cho bảng có admin-paginate ----
    document.querySelectorAll("[data-ad-status-chip]").forEach(function (chip) {
        chip.addEventListener("click", function () {
            var table = document.getElementById(chip.getAttribute("data-target"));
            if (!table || typeof table.__adSetStatus !== "function") return;
            table.__adSetStatus(chip.getAttribute("data-ad-status-chip"));
            document.querySelectorAll('[data-ad-status-chip][data-target="' + table.id + '"]').forEach(function (c) {
                c.classList.toggle("is-on", c === chip);
            });
        });
    });
})();
