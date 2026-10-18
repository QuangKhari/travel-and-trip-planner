/* Menu "Sắp xếp" trang tour: chỉ lo đóng/mở và hiện nhãn đang chọn.
   Việc đổi thứ tự do travel-tours.js xử lý như cũ qua các nút [data-sort]. */
(function () {
    "use strict";
    var menu = document.getElementById("tvSortMenu");
    if (!menu) return;
    var btn = menu.querySelector(".tl-sortmenu__btn");
    var list = document.getElementById("tvSortBar");
    var cur = document.getElementById("tvSortCur");
    if (!btn || !list) return;
    var lastKey = "";

    function sync() {
        var on = list.querySelector("[data-sort].is-on");
        if (on && cur)
            cur.textContent =
                on.getAttribute("data-label") || on.textContent.trim();
    }
    function open() {
        list.hidden = false;
        menu.classList.add("is-open");
        btn.setAttribute("aria-expanded", "true");
        var on =
            list.querySelector("[data-sort].is-on") ||
            list.querySelector("[data-sort]");
        if (on) on.focus();
    }
    function close(back) {
        list.hidden = true;
        menu.classList.remove("is-open");
        btn.setAttribute("aria-expanded", "false");
        if (back) btn.focus();
    }

    btn.addEventListener("click", function () {
        list.hidden ? open() : close(false);
    });
    list.addEventListener("keydown", function (e) {
        lastKey = e.key;
        if (e.key === "Escape") {
            e.preventDefault();
            close(true);
        }
    });
    list.addEventListener("mousedown", function () {
        lastKey = "";
    });
    // Chọn bằng chuột / Enter thì đóng; mũi tên chỉ di chuyển (travel-tours.js vẫn chọn ngay)
    list.addEventListener("click", function (e) {
        if (!e.target.closest("[data-sort]")) return;
        var arrow = /^Arrow/.test(lastKey);
        lastKey = "";
        if (!arrow) close(true);
    });
    document.addEventListener("click", function (e) {
        if (!list.hidden && !menu.contains(e.target)) close(false);
    });
    menu.addEventListener("focusout", function (e) {
        if (!list.hidden && e.relatedTarget && !menu.contains(e.relatedTarget))
            close(false);
    });
    new MutationObserver(sync).observe(list, {
        subtree: true,
        attributes: true,
        attributeFilter: ["class", "aria-checked"],
    });
    sync();
})();
