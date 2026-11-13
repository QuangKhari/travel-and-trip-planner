/*
 * A-25: chip "Kết quả có đúng ý bạn không?" dưới mỗi kết quả AI (La bàn hiểu yêu cầu, tóm tắt chuyến, Copilot, Tour Match).
 * Đúng ý → ghi ngay. Chưa đúng → hỏi thêm lý do (một chạm) rồi ghi. Máy chủ gắn với lượt AI gần nhất trong tbl_ai_runs.
 * Cấu hình: <script src="ai-feedback.js" data-url="/la-ban/danh-gia" data-csrf="..." data-trip="12">
 * Khối được gắn chip: #aiResult (brief), #aiStoryBody (explain), .ai-msg--ai trong #aiCoFeed (copilot), [data-ai-fb] (tự khai báo).
 */
(function () {
    "use strict";
    var me = document.currentScript;
    if (!me || !me.dataset.url) return;
    var URL_ = me.dataset.url, CSRF = me.dataset.csrf || "", TRIP = me.dataset.trip ? +me.dataset.trip : null;
    var REASONS = [["wrong_place", "Sai nơi / điểm đến"], ["wrong_time", "Sai ngày, giờ"], ["not_my_taste", "Không hợp sở thích"], ["too_generic", "Chung chung quá"], ["other", "Khác"]];

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function send(feature, helpful, reason) {
        return fetch(URL_, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CSRF, "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ feature: feature, helpful: helpful, reason: reason || null, tripId: TRIP }),
        }).then(function (r) { return r.ok; }).catch(function () { return false; });
    }
    function chip(feature) {
        var box = el("div", "ai-fb");
        box.setAttribute("role", "group");
        box.setAttribute("aria-label", "Đánh giá kết quả AI");
        var q = el("span", "ai-fb__q", "Kết quả có đúng ý bạn không?");
        var yes = el("button", "ai-fb__b", "Đúng ý"), no = el("button", "ai-fb__b", "Chưa đúng");
        yes.type = no.type = "button";
        function done(text) { box.textContent = ""; box.appendChild(el("span", "ai-fb__thanks", text)); }
        yes.addEventListener("click", function () { yes.disabled = no.disabled = true; send(feature, true).then(function () { done("Cảm ơn bạn! La bàn sẽ giữ cách làm này."); }); });
        no.addEventListener("click", function () {
            box.textContent = "";
            box.appendChild(el("span", "ai-fb__q", "Chưa đúng ở đâu?"));
            REASONS.forEach(function (r) {
                var b = el("button", "ai-fb__b ai-fb__b--r", r[1]);
                b.type = "button";
                b.addEventListener("click", function () { send(feature, false, r[0]).then(function () { done("Đã ghi nhận. Cảm ơn bạn đã góp ý."); }); });
                box.appendChild(b);
            });
        });
        box.appendChild(q); box.appendChild(yes); box.appendChild(no);
        return box;
    }
    function hasText(node) { return node && (node.textContent || "").trim().length > 20; }
    function attach(node, feature) {
        if (!node || node.querySelector(":scope > .ai-fb") || !hasText(node)) return;
        if (node.querySelector(".is-run, .ai-co__think, .ai-loading, [aria-busy='true']")) return;   // đang chạy: chờ có kết quả
        node.appendChild(chip(feature));
    }
    function scan() {
        var r = document.getElementById("aiResult");
        if (r && !r.hidden) attach(r, "brief");
        var st = document.getElementById("aiStoryBody");
        if (st && !st.hidden) attach(st, "explain");
        // gắn vào thân bong bóng (.ai-msg__b), không gắn thành phần tử con thứ ba của hàng flex (làm bong bóng bị bóp hẹp)
        document.querySelectorAll("#aiCoFeed .ai-msg--ai > .ai-msg__b").forEach(function (m) { attach(m, "copilot"); });
        document.querySelectorAll("[data-ai-fb]").forEach(function (m) { attach(m, m.getAttribute("data-ai-fb")); });
    }
    var css = el("style");
    css.textContent = ".ai-fb{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:10px 0 2px;padding-top:8px;border-top:1px dashed #e5e7eb;font-size:.82rem}"
        + ".ai-fb__q{color:#4b5563;margin-right:4px}.ai-fb__b{border:1px solid #cfe7e3;background:#f3fbf9;color:#135D65;border-radius:999px;padding:3px 12px;font:inherit;cursor:pointer}"
        + ".ai-fb__b:hover{background:#e6f4f1}.ai-fb__b--r{background:#fffaf0;border-color:#efdcae;color:#7a5b12}.ai-fb__thanks{color:#0f5f57;font-weight:600}";
    document.head.appendChild(css);
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(scan, 600); }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["hidden"] });
    scan();
})();
