/* C-11: chọn 2–3 tour để so sánh cạnh nhau. Lưu trong localStorage (tv.compare), thanh nổi ở cuối trang dẫn tới /so-sanh-tour. */
(function () {
    "use strict";
    var KEY = "tv.compare", MAX = 3;
    function load() { try { return JSON.parse(localStorage.getItem(KEY) || "[]").slice(0, MAX); } catch (e) { return []; } }
    function save(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
    var css = document.createElement("style");
    css.textContent = ".trc__img{position:relative}.trc-cmp{position:absolute;left:12px;bottom:12px;z-index:3;border:0;border-radius:999px;padding:5px 11px;font-size:12.5px;font-weight:600;background:rgba(255,255,255,.92);color:#0f2f3a;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.12)}" +
        ".trc-cmp[aria-pressed=true]{background:#0d9488;color:#fff}" +
        ".tv-cmpbar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:1050;display:flex;align-items:center;gap:12px;padding:10px 12px 10px 18px;border-radius:999px;background:#0f2f3a;color:#fff;box-shadow:0 14px 40px rgba(0,0,0,.25);font-size:14px;max-width:calc(100% - 24px)}" +
        ".tv-cmpbar a{background:#14b8a6;color:#fff;border-radius:999px;padding:8px 16px;font-weight:700;text-decoration:none;white-space:nowrap}.tv-cmpbar a[aria-disabled=true]{opacity:.5;pointer-events:none}" +
        ".tv-cmpbar button{background:none;border:0;color:#cbd5e1;cursor:pointer;font-size:13px}";
    document.head.appendChild(css);
    var bar = document.createElement("div");
    bar.className = "tv-cmpbar"; bar.hidden = true; bar.setAttribute("role", "region"); bar.setAttribute("aria-label", "So sánh tour");
    document.body.appendChild(bar);

    function render() {
        var v = load();
        document.querySelectorAll("[data-cmp]").forEach(function (b) {
            var on = v.some(function (x) { return String(x.id) === b.dataset.cmp; });
            b.setAttribute("aria-pressed", on ? "true" : "false");
            b.innerHTML = '<i class="fal fa-exchange-alt" aria-hidden="true"></i> ' + (on ? "Đang so sánh" : "So sánh");
        });
        bar.hidden = v.length === 0;
        if (!v.length) return;
        var url = (window.TV_COMPARE_URL || "/so-sanh-tour") + "?ids=" + v.map(function (x) { return x.id; }).join(",");
        bar.innerHTML = "<span>Đã chọn <b>" + v.length + "</b>/" + MAX + " tour</span>" +
            '<a href="' + url + '"' + (v.length < 2 ? ' aria-disabled="true" title="Chọn ít nhất 2 tour"' : "") + ">So sánh ngay</a>" +
            '<button type="button" data-cmp-clear>Bỏ chọn</button>';
    }
    document.addEventListener("click", function (e) {
        var c = e.target.closest("[data-cmp-clear]");
        if (c) { save([]); render(); return; }
        var b = e.target.closest("[data-cmp]");
        if (!b) return;
        e.preventDefault();
        var v = load(), id = b.dataset.cmp, i = v.findIndex(function (x) { return String(x.id) === id; });
        if (i >= 0) v.splice(i, 1);
        else {
            if (v.length >= MAX) { if (window.toastr) toastr.info("So sánh tối đa " + MAX + " tour. Bỏ bớt một tour trước."); return; }
            v.push({ id: id, t: b.dataset.cmpTitle || "" });
        }
        save(v); render();
    });
    window.addEventListener("storage", render);
    render();
})();
