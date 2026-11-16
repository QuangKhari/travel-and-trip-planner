/* PWA trên trang "Chuyến đi của tôi": đăng ký service worker (để trang này và từng chuyến mở được khi mất mạng)
 * và nút "Cài lên điện thoại". Android / Chrome máy tính: dùng lời mời cài của trình duyệt.
 * iPhone (Safari không có lời mời cài): hiện hướng dẫn Chia sẻ → Thêm vào MH chính. Đã cài (standalone) thì ẩn nút. */
(function () {
    "use strict";
    if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(function () {});
    var b = document.getElementById("tpInstall");
    if (!b) return;
    var standalone = (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) || window.navigator.standalone === true;
    if (standalone) return;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    var ask = null;
    window.addEventListener("beforeinstallprompt", function (e) { e.preventDefault(); ask = e; b.hidden = false; });
    window.addEventListener("appinstalled", function () { b.hidden = true; ask = null; });
    if (ios) b.hidden = false;
    b.addEventListener("click", function () {
        if (ask) {
            ask.prompt();
            ask.userChoice.then(function () { ask = null; b.hidden = true; }).catch(function () {});
            return;
        }
        var tip = document.getElementById("tpInstallTip");
        if (tip) { tip.hidden = !tip.hidden; return; }
        tip = document.createElement("p");
        tip.id = "tpInstallTip"; tip.className = "tpx-notice"; tip.setAttribute("role", "status");
        tip.textContent = "Trên iPhone: mở trang này bằng Safari, bấm nút Chia sẻ rồi chọn \"Thêm vào MH chính\". Sau đó Travela mở như một ứng dụng, xem được chuyến đã mở cả khi mất mạng.";
        var head = b.closest("header");
        (head && head.parentNode ? head.parentNode.insertBefore(tip, head.nextSibling) : document.body.appendChild(tip));
    });
})();
