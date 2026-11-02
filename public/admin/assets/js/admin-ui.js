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
    // ---- 5) menu trái: thu gọn (máy tính) / trượt ra (điện thoại), nhớ lựa chọn ----
    var root = document.documentElement, side = document.getElementById("ad-side"),
        scrim = document.getElementById("ad-scrim"), tgl = document.getElementById("ad-nav-toggle");
    var mobile = function () { return window.matchMedia("(max-width: 991px)").matches; };
    if (root.classList.contains("ad-pre-sm")) { document.body.classList.add("ad-sm"); root.classList.remove("ad-pre-sm"); }
    function closeMobile() { document.body.classList.remove("ad-open"); if (scrim) scrim.hidden = true; }
    if (tgl) tgl.addEventListener("click", function () {
        if (mobile()) {
            var open = !document.body.classList.contains("ad-open");
            document.body.classList.toggle("ad-open", open);
            if (scrim) scrim.hidden = !open;
            return;
        }
        var sm = document.body.classList.toggle("ad-sm");
        try { localStorage.setItem("ad.nav", sm ? "sm" : "md"); } catch (e) {}
    });
    if (scrim) scrim.addEventListener("click", closeMobile);
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeMobile(); });
    // menu thu gọn: mở một nhóm thì đóng các nhóm khác cho gọn
    if (side) side.querySelectorAll("details.ad-nav__fold").forEach(function (d) {
        d.addEventListener("toggle", function () {
            if (!d.open || !document.body.classList.contains("ad-sm")) return;
            side.querySelectorAll("details.ad-nav__fold[open]").forEach(function (o) { if (o !== d) o.open = false; });
        });
    });
    // giữ vị trí cuộn của menu giữa các trang
    if (side) {
        var nav = side.querySelector(".ad-nav");
        try { var y = +sessionStorage.getItem("ad.navY") || 0; if (nav && y) nav.scrollTop = y; } catch (e) {}
        window.addEventListener("beforeunload", function () { try { sessionStorage.setItem("ad.navY", nav ? nav.scrollTop : 0); } catch (e) {} });
    }

    // ---- 6) đăng xuất có hỏi lại ----
    var out = document.getElementById("btn-logout-admin");
    if (out) out.addEventListener("click", function () {
        var f = document.getElementById("form-logout-admin");
        if (!window.Swal) { if (confirm("Đăng xuất khỏi trang quản trị?")) f.submit(); return; }
        Swal.fire({ title: "Đăng xuất?", text: "Bạn sẽ cần đăng nhập lại để quản trị.", icon: "question", showCancelButton: true,
            confirmButtonText: "Đăng xuất", cancelButtonText: "Ở lại", confirmButtonColor: "#dc2626", reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) f.submit(); });
    });

    // ---- 7) bảng có thuộc tính data-href: bấm cả dòng để mở ----
    document.addEventListener("click", function (e) {
        var tr = e.target.closest("tr[data-href]");
        if (!tr || e.target.closest("a,button,input,select,label,form")) return;
        window.location = tr.getAttribute("data-href");
    });

    // ---- 8) nút sao chép: data-copy="text" ----
    document.addEventListener("click", function (e) {
        var b = e.target.closest("[data-copy]");
        if (!b) return;
        var t = b.getAttribute("data-copy");
        (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
            if (window.toastr) toastr.success("Đã sao chép " + t);
        }).catch(function () {});
    });
})();
