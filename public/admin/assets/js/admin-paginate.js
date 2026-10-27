/* Admin — phân trang + tìm nhanh + lọc trạng thái phía trình duyệt cho các bảng danh sách.
 * Áp dụng cho: #datatable-listTours, #datatable-booking, #datatable-promotion
 * Các thao tác Thêm/Sửa/Xóa/Xác nhận trong admin thay lại nội dung <tbody> bằng AJAX; script này tự
 * phân trang lại (giữ nguyên trang đang xem nếu còn) mà không cần sửa code cũ.
 * v2: thêm lọc theo thuộc tính data-status / data-pay của <tr> (xem chip trong booking.blade.php):
 *     table.__adSetStatus('n' | 'y' | 'f' | 'c' | 'unpaid' | '') */
(function () {
    "use strict";
    var IDS = ["datatable-listTours", "datatable-booking", "datatable-promotion"];

    IDS.forEach(function (id) {
        var table = document.getElementById(id);
        if (!table || !table.tBodies.length) return;
        var tbody = table.tBodies[0];
        var state = { page: 1, size: 10, q: "", status: "" };

        var tools = document.createElement("div");
        tools.className = "tv-admin-tools";
        tools.innerHTML =
            '<input type="search" class="form-control" placeholder="Tìm trong danh sách…" aria-label="Tìm trong danh sách">' +
            '<label>Hiển thị <select class="form-control"><option>10</option><option>25</option><option>50</option></select> dòng</label>';
        var foot = document.createElement("div");
        foot.className = "tv-admin-pager";
        foot.innerHTML = '<span class="tv-admin-pager__info"></span><div class="tv-admin-pager__btns"></div>';

        var host = table.closest(".table-responsive") || table;
        host.parentNode.insertBefore(tools, host);
        host.parentNode.insertBefore(foot, host.nextSibling);

        var search = tools.querySelector("input"),
            size = tools.querySelector("select");
        var info = foot.querySelector(".tv-admin-pager__info"),
            btns = foot.querySelector(".tv-admin-pager__btns");

        function rows() {
            return Array.prototype.slice.call(tbody.rows);
        }

        function statusOk(r) {
            if (!state.status) return true;
            if (state.status === "unpaid") return r.dataset.pay === "n" && r.dataset.status !== "c";
            return r.dataset.status === state.status;
        }

        function render() {
            var q = state.q.toLowerCase();
            var all = rows();
            var match = all.filter(function (r) {
                return statusOk(r) && (!q || r.textContent.toLowerCase().indexOf(q) !== -1);
            });
            var pages = Math.max(1, Math.ceil(match.length / state.size));
            if (state.page > pages) state.page = pages;
            var from = (state.page - 1) * state.size,
                to = from + state.size;

            all.forEach(function (r) {
                r.hidden = true;
            });
            match.slice(from, to).forEach(function (r) {
                r.hidden = false;
            });

            info.textContent = match.length
                ? "Hiển thị " + (from + 1) + "–" + Math.min(to, match.length) + " / " + match.length + " dòng"
                : "Không có dòng nào";

            var html = "";
            var add = function (label, p, cls) {
                html += '<button type="button" class="' + (cls || "") + '" data-p="' + p + '">' + label + "</button>";
            };
            if (pages > 1) {
                if (state.page > 1) add("‹", state.page - 1);
                var s = Math.max(1, state.page - 2),
                    e = Math.min(pages, state.page + 2);
                if (s > 1) {
                    add("1", 1);
                    if (s > 2) html += "<span>…</span>";
                }
                for (var p = s; p <= e; p++) add(p, p, p === state.page ? "is-active" : "");
                if (e < pages) {
                    if (e < pages - 1) html += "<span>…</span>";
                    add(pages, pages);
                }
                if (state.page < pages) add("›", state.page + 1);
            }
            btns.innerHTML = html;
        }

        btns.addEventListener("click", function (ev) {
            var b = ev.target.closest("button[data-p]");
            if (!b) return;
            state.page = parseInt(b.dataset.p, 10);
            render();
            table.scrollIntoView({ behavior: "smooth", block: "nearest" });
        });
        search.addEventListener("input", function () {
            state.q = search.value.trim();
            state.page = 1;
            render();
        });
        size.addEventListener("change", function () {
            state.size = parseInt(size.value, 10);
            state.page = 1;
            render();
        });

        table.__adSetStatus = function (s) {
            state.status = s || "";
            state.page = 1;
            render();
        };

        // AJAX thay <tbody> => phân trang lại (chỉ nghe thay đổi con của tbody nên việc ẩn/hiện dòng không gây vòng lặp)
        new MutationObserver(render).observe(tbody, { childList: true });
        render();
    });
})();
