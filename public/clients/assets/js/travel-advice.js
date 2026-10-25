/* Khối "Thời điểm đi": chọn tháng bằng nút. Mặc định tháng hiện tại đã hiện sẵn từ máy chủ. */
(function () {
    "use strict";
    var root = document.querySelector("[data-advice-root]");
    if (!root) return;
    var tabs = [].slice.call(root.querySelectorAll("[data-advice-tab]"));
    var panels = [].slice.call(root.querySelectorAll("[data-advice-panel]"));
    function show(month) {
        tabs.forEach(function (t) {
            var on = t.getAttribute("data-advice-tab") === month;
            t.classList.toggle("is-on", on);
            t.setAttribute("aria-selected", on ? "true" : "false");
            t.tabIndex = on ? 0 : -1;
        });
        panels.forEach(function (p) {
            p.hidden = p.getAttribute("data-advice-panel") !== month;
        });
    }
    tabs.forEach(function (t, i) {
        t.addEventListener("click", function () { show(t.getAttribute("data-advice-tab")); });
        t.addEventListener("keydown", function (e) {
            var j = e.key === "ArrowRight" ? i + 1 : e.key === "ArrowLeft" ? i - 1 : -1;
            if (j < 0 || j >= tabs.length) return;
            e.preventDefault();
            tabs[j].focus();
            show(tabs[j].getAttribute("data-advice-tab"));
        });
    });
})();
