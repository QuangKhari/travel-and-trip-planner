/* La bàn F2 "Tóm tắt chuyến đi" trong trình soạn /len-ke-hoach/{id}.
 * POST /len-ke-hoach/{id}/tom-tat-ai -> {headline, days:[{day,label,summary,items:[{key,name,why,ai}]}], tips:[{text,basedOn}], guards, engine}
 * Văn bản ở dạng đoạn: {t: chữ} hoặc {k: khóa mục, name, day}. Tên riêng là chip do MÁY CHỦ chèn; bấm chip thì
 * cuộn tới mục đó trong timeline. Mọi chữ gán bằng textContent.
 * Tóm tắt dựa trên BẢN ĐÃ LƯU: khi lịch vừa đổi (tự lưu xong, rev mới) thì báo "Lịch đã đổi, tóm tắt lại".
 */
(function () {
    "use strict";
    var btn = document.getElementById("aiStoryBtn"), body = document.getElementById("aiStoryBody");
    if (!btn || !body) return;
    var cfg = {};
    try { cfg = JSON.parse(document.getElementById("aiData").textContent) || {}; } catch (e) { cfg = {}; }
    var state = { loaded: false, busy: false, rev: null };
    var DAYC = ["#167A85", "#c9583a", "#4c57c9", "#a86a12", "#7a4bb3", "#2f7d32", "#b23a6b"];

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

    btn.addEventListener("click", function () {
        var open = btn.getAttribute("aria-expanded") !== "true";
        btn.setAttribute("aria-expanded", String(open));
        body.hidden = !open;
        if (open && !state.loaded) load(false);
    });

    function load(fresh) {
        if (state.busy) return;
        state.busy = true;
        body.textContent = "";
        var w = el("div", "ai-story__wait");
        w.appendChild(el("span", "ai-dot"));
        w.appendChild(el("span", null, cfg.on ? "La bàn đang viết từ dữ kiện của chuyến: lý do chọn, sức khỏe, mùa…" : "Đang ghép tóm tắt từ dữ kiện của chuyến…"));
        body.appendChild(w);
        var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, 40000) : null;
        fetch(cfg.explainUrl + (fresh ? "?fresh=1" : ""), {
            method: "POST", credentials: "same-origin", signal: ctl ? ctl.signal : undefined,
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: "{}",
        }).then(function (r) { clearTimeout(t); return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (r) { state.loaded = true; state.rev = r.rev; render(r); })
            .catch(function (s) {
                body.textContent = "";
                body.appendChild(el("p", "ai-err", s === 429 ? "Bạn bấm hơi nhanh. Đợi một phút rồi thử lại nhé." : "Chưa tóm tắt được lúc này. Lịch của bạn không bị ảnh hưởng."));
                var again = el("button", "tp-mini", "Thử lại");
                again.type = "button";
                again.addEventListener("click", function () { load(false); });
                body.appendChild(again);
            })
            .then(function () { state.busy = false; });
    }

    function segs(parent, list) {
        (list || []).forEach(function (s) {
            if (s.k) {
                var b = el("button", "ai-ent", s.name);
                b.type = "button";
                b.style.setProperty("--c", DAYC[((s.day || 1) - 1) % DAYC.length]);
                b.title = "Xem trong lịch";
                b.addEventListener("click", function () { jump(s.name); });
                parent.appendChild(b);
            } else parent.appendChild(document.createTextNode(s.t || ""));
        });
        return parent;
    }

    /** Tìm mục trong timeline theo tên (planner.js vẽ .tpx-stop .tpx-name) và làm sáng nó. */
    function jump(name) {
        var hit = null;
        document.querySelectorAll(".tpx-stop .tpx-name").forEach(function (n) { if (!hit && n.textContent.trim() === name) hit = n.closest(".tpx-stop"); });
        if (!hit) return;
        hit.scrollIntoView({ behavior: "smooth", block: "center" });
        hit.classList.add("ai-flash");
        setTimeout(function () { hit.classList.remove("ai-flash"); }, 1400);
    }

    function render(r) {
        body.textContent = "";
        if (!r.ok) { body.appendChild(el("p", "ai-small", r.message || "Chưa có gì để tóm tắt.")); return; }
        body.appendChild(segs(el("h3", "ai-story__title"), r.headline));
        var meta = el("p", "ai-story__meta");
        if (r.engine === "template") meta.appendChild(el("span", null, r.notice || "Chế độ cơ bản: ghép từ lý do chọn của bộ xếp lịch."));
        else {
            var g = r.guards || {};
            meta.appendChild(el("span", "ai-guard", (g.replaced ? "✓ " + g.replaced + " đoạn được thay bằng câu từ dữ liệu" : "✓ Mọi tên và con số khớp dữ liệu chuyến")));
            meta.appendChild(el("span", null, r.engine === "cache" ? "Bản đã lưu cho phiên bản lịch này" : "Viết lại khi bạn sửa lịch"));
        }
        body.appendChild(meta);

        var days = el("div", "ai-story__days");
        (r.days || []).forEach(function (d) {
            var box = el("div", "ai-sday");
            box.style.setProperty("--c", DAYC[(d.day - 1) % DAYC.length]);
            box.appendChild(el("b", "ai-sday__h", d.label));
            box.appendChild(segs(el("p"), d.summary));
            if (d.items && d.items.length) {
                var det = el("details", "ai-why");
                det.appendChild(el("summary", null, "Vì sao có " + d.items.length + " mục này?"));
                var ul = el("ul");
                d.items.forEach(function (it) {
                    var li = el("li");
                    var n = el("button", "ai-ent", it.name);
                    n.type = "button";
                    n.style.setProperty("--c", DAYC[(d.day - 1) % DAYC.length]);
                    n.addEventListener("click", function () { jump(it.name); });
                    li.appendChild(n);
                    li.appendChild(document.createTextNode(" "));
                    segs(li, it.why);
                    if (!it.ai) li.appendChild(el("span", "ai-basis", "lý do của bộ xếp lịch"));
                    ul.appendChild(li);
                });
                det.appendChild(ul);
                box.appendChild(det);
            }
            days.appendChild(box);
        });
        body.appendChild(days);

        if (r.tips && r.tips.length) {
            var tips = el("ul", "ai-tips");
            r.tips.forEach(function (t) {
                var li = el("li");
                segs(li, t.text);
                (t.basedOn || []).forEach(function (b) { li.appendChild(el("span", "ai-basis", basis(b))); });
                tips.appendChild(li);
            });
            body.appendChild(tips);
        }
        var foot = el("p", "ai-story__foot");
        var again = el("button", "tp-mini", "Viết lại");
        again.type = "button";
        again.addEventListener("click", function () { load(true); });
        foot.appendChild(el("span", null, "Tóm tắt không đổi lịch. "));
        foot.appendChild(again);
        body.appendChild(foot);
    }

    function basis(b) {
        if (b.indexOf("health:") === 0) return "sức khỏe chuyến đi";
        return { month: "mùa theo tháng", note: "ghi chú của bạn", schedule: "lịch đã xếp", reasons: "lý do chọn" }[b] || b;
    }

    // lịch được lưu bản mới (planner.js đổi trạng thái lưu) -> tóm tắt cũ không còn khớp
    var msg = document.getElementById("tpMsg");
    if (msg && typeof MutationObserver !== "undefined") {
        new MutationObserver(function () {
            if (!state.loaded || !/Đã lưu/.test(msg.textContent || "")) return;
            if (body.querySelector(".ai-stale")) return;
            var s = el("p", "ai-stale");
            s.appendChild(el("span", null, "Lịch vừa thay đổi, tóm tắt này có thể không còn khớp. "));
            var b = el("button", "tp-mini", "Tóm tắt lại");
            b.type = "button";
            b.addEventListener("click", function () { load(false); });
            s.appendChild(b);
            body.insertBefore(s, body.firstChild);
        }).observe(msg, { childList: true, characterData: true, subtree: true });
    }
})();
