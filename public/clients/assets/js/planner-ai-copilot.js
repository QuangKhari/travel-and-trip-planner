/* La bàn F3 "Copilot" trong trình soạn /len-ke-hoach/{id}: nói bằng lời -> đề xuất ĐÃ KIỂM CHỨNG -> người dùng bấm Áp dụng.
 *
 *   POST {url} {text, mentions:[{name,day}], history:[{role,text}]}
 *     -> {kind:"proposal", id, baseRev, say:[seg], steps_human:[{op,text}], ghost:[...], changes:[...], receipt:{before,after,resolved,warnings,effects,checked}}
 *      | {kind:"reply", say:[seg]} | {kind:"fallback", notice, say}
 *   POST {recheckUrl} {proposal}         lịch đã đổi: máy chủ ánh xạ + mô phỏng lại, không gọi LLM
 *   POST {feedbackUrl} {proposal, applied}
 *
 * Mọi thay đổi lịch đi qua window.TpPlanner.applyAi (planner.js) = CHÍNH planner-resolve.js của nút "Xem cách xử lý",
 * nên xem trước = áp dụng, tự lưu (rev, 409) và hoàn tác như mọi thao tác khác. File này không tự sửa danh sách mục.
 * Mọi chữ gán bằng textContent; tên riêng là đoạn {k,name} do MÁY CHỦ chèn.
 */
(function () {
    "use strict";
    var data = {};
    try { data = JSON.parse(document.getElementById("aiData").textContent) || {}; } catch (e) { data = {}; }
    var cfg = data.copilot;
    var drawer = document.getElementById("aiCo");
    if (!cfg || !drawer) return;
    var tp = {};
    try { tp = JSON.parse(document.getElementById("tpData").textContent) || {}; } catch (e) { tp = {}; }

    var $ = function (id) { return document.getElementById(id); };
    var feed = $("aiCoFeed"), form = $("aiCoForm"), q = $("aiCoQ"), chipsBox = $("aiCoChips"), sugBox = $("aiCoSug"),
        busyBox = $("aiCoBusy"), busyT = $("aiCoBusyT"), pickBtn = $("aiCoPick"), ctxBox = $("aiCoCtx"), draft = $("aiDraft");
    var DAYC = ["#167A85", "#c9583a", "#4c57c9", "#a86a12", "#7a4bb3", "#2f7d32", "#b23a6b"];
    var OPN = { move: "chuyển", time: "giờ", reorder: "xếp lại", replace: "thay", add: "thêm", rainplan: "dự phòng" };
    var st = { open: false, busy: false, ctl: null, t0: 0, tick: null, history: [], mentions: [], cur: null, picking: false, health: null, greeted: false, editedWhileBusy: false, alerts: null, intent: null };

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function button(cls, text, fn, title) { var b = el("button", cls, text); b.type = "button"; if (title) b.title = title; if (fn) b.addEventListener("click", fn); return b; }
    function P() { return window.TpPlanner || null; }
    function small() { return window.matchMedia ? window.matchMedia("(max-width: 991.98px)").matches : window.innerWidth < 992; }
    function mark() {
        var s = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        s.setAttribute("viewBox", "0 0 24 24");
        s.innerHTML = '<circle cx="12" cy="12" r="9.2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/>';
        var w = el("span", "ai-mark ai-mark--sm");
        w.setAttribute("aria-hidden", "true");
        w.appendChild(s);
        return w;
    }
    function post(url, body, signal) {
        return fetch(url, {
            method: "POST", credentials: "same-origin", signal: signal,
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": data.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify(body || {}),
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
    }

    /* ---------------- mở / đóng ---------------- */
    var focusOrigin = null;
    function focusableInDrawer() {
        return Array.prototype.filter.call(drawer.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href], textarea:not([disabled]), [tabindex="0"]'), function (node) {
            return !node.hidden && node.getClientRects().length > 0;
        });
    }
    function setOpen(on) {
        if (on && !st.open) focusOrigin = document.activeElement;
        st.open = on;
        drawer.hidden = !on;
        drawer.setAttribute('aria-modal', on && small() ? 'true' : 'false');
        document.documentElement.classList.toggle("ai-co-on", on);
        ["aiCoOpen", "aiCoOpen2"].forEach(function (id) { if ($(id)) $(id).setAttribute("aria-expanded", String(on)); });
        if (on) {
            if (!st.greeted) { st.greeted = true; idle(); loadHealth(); }
            renderCtx();
            fit();
            setTimeout(function () { q.focus(); }, 30);
        } else {
            setPicking(false);
            if (focusOrigin && focusOrigin.isConnected && typeof focusOrigin.focus === 'function') focusOrigin.focus();
        }
        document.dispatchEvent(new CustomEvent('travela:copilot', { detail: { open: on } }));
    }
    ["aiCoOpen", "aiCoOpen2"].forEach(function (id) { if ($(id)) $(id).addEventListener("click", function () { setOpen(!st.open); }); });
    /** Desktop: ngăn nằm ĐÚNG trên cột bản đồ (cột này dính khi cuộn), không che thanh công cụ / lịch. Điện thoại: CSS toàn màn hình. */
    var mapcol = document.querySelector(".tpx-mapcol"), fitRaf = 0;
    function fit() {
        fitRaf = 0;
        if (!st.open) return;
        var r = mapcol && !small() ? mapcol.getBoundingClientRect() : null;
        if (!r || r.width < 300 || r.height < 300) { drawer.removeAttribute("style"); return; }
        // cột bản đồ còn ở thấp (đầu trang): kéo ngăn lên để khung trò chuyện đủ cao (≥ 620px khi màn hình cho phép)
        var w = Math.min(420, r.width), bottom = Math.min(r.bottom, window.innerHeight - 8);
        var top = Math.max(8, Math.min(r.top, bottom - Math.min(620, window.innerHeight - 16)));
        drawer.style.top = top + "px";
        drawer.style.left = (r.right - w) + "px";
        drawer.style.right = "auto";
        drawer.style.bottom = "auto";
        drawer.style.width = w + "px";
        drawer.style.height = Math.max(320, bottom - top) + "px";
    }
    function fitSoon() { if (!fitRaf) fitRaf = requestAnimationFrame(fit); }
    window.addEventListener("scroll", fitSoon, { passive: true });
    window.addEventListener("resize", fitSoon);
    $("aiCoClose").addEventListener("click", function () { setOpen(false); });
    document.addEventListener("keydown", function (e) {
        if (!st.open) return;
        if (e.key === 'Tab' && small()) {
            var list = focusableInDrawer();
            if (!list.length) { e.preventDefault(); drawer.focus(); return; }
            var first = list[0], last = list[list.length - 1];
            if (e.shiftKey && (document.activeElement === first || document.activeElement === drawer)) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            return;
        }
        if (e.key === 'Escape') {
            if (st.picking) setPicking(false); else setOpen(false);
        }
    });

    /* ---------------- bối cảnh + gợi ý bắt đầu (lấy từ Sức khỏe chuyến đi, không phải câu chung chung) ---------------- */
    function loadHealth() {
        if (!tp.healthUrl) return;
        fetch(tp.healthUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (h) { st.health = h; renderCtx(); renderSug(); if (!feed.querySelector(".ai-msg")) idle(); })
            .catch(function () { /* không có gợi ý thì thôi */ });
    }
    function quick() {
        var out = [], seen = {};
        var add = function (label, prompt) { if (!seen[label] && out.length < 4) { seen[label] = 1; out.push({ label: label, prompt: prompt, hot: true }); } };
        ((st.health && st.health.checks) || []).forEach(function (c) {
            if ((c.level !== "bad" && c.level !== "warn") || c.day == null) return;
            var d = c.day, k = c.code || "";
            if (k === "capacity.overload" || k === "capacity.tight") add("Ngày " + d + " quá dày", "Ngày " + d + " đang quá dày, chuyển bớt một mục sang ngày nhẹ hơn.");
            else if (k.indexOf("hours.") === 0) add("Giờ mở cửa ngày " + d, "Ngày " + d + " có mục không khớp giờ mở cửa, sửa giúp tôi.");
            else if (k === "time.overlap" || k === "time.travel") add("Chồng giờ ngày " + d, "Ngày " + d + " bị chồng giờ, sắp xếp lại giúp tôi.");
            else if (k === "route.long_day" || k === "route.long_leg") add("Đi lại nhiều ngày " + d, "Ngày " + d + " đi lại nhiều quá, sắp lại cho gọn.");
            else if (k === "context.rain") add("Nếu mưa ngày " + d, "Ngày " + d + " nếu mưa thì có nơi nào trong nhà gần đó?");
        });
        [["Thêm quán cà phê", "Thêm một quán cà phê gần lịch ngày 1."], ["Cần mang gì?", "Chuyến này cần mang theo gì?"], ["Ngày nào nhẹ nhất?", "Ngày nào trong chuyến đang nhẹ nhất?"]]
            .forEach(function (x) { if (out.length < 4) out.push({ label: x[0], prompt: x[1] }); });
        return out;
    }
    function renderSug() {
        sugBox.textContent = "";
        quick().forEach(function (x) { sugBox.appendChild(button("ai-chip" + (x.hot ? " is-hot" : ""), x.label, function () { send(x.prompt); })); });
    }
    function renderCtx() {
        ctxBox.textContent = "";
        var s = P() ? P().state() : { rev: tp.rev, count: 0 };
        ctxBox.appendChild(el("span", null, "La bàn đọc bản đã lưu:"));
        ctxBox.appendChild(el("span", "ai-pill", (tp.trip && tp.trip.days ? tp.trip.days + " ngày · " : "") + s.count + " mục"));
        var h = st.health;
        if (h && h.summary) {
            var n = (h.checks || []).filter(function (c) { return c.level === "bad" || c.level === "warn"; }).length;
            ctxBox.appendChild(el("span", "ai-pill" + (n ? " ai-pill--warn" : ""), n ? n + " điều cần xem" : "Lịch ổn"));
        }
        if (s.dirty) ctxBox.appendChild(el("span", "ai-pill ai-pill--warn", "có thay đổi chưa lưu"));
    }
    function idle() {
        feed.textContent = "";
        var box = el("div", "ai-co__idle");
        box.appendChild(el("h3", null, "Bạn muốn chỉnh gì trong chuyến này?"));
        if (cfg.llm === false) box.appendChild(el("p", null, "Chọn một chủ đề ở trên (món ăn, điểm tham quan, lễ hội, cảnh báo) để La bàn gợi ý từ dữ liệu đã duyệt của Travela; mỗi gợi ý được kiểm tra giờ mở cửa và quãng đường trước khi bạn bấm Áp dụng. Bạn cũng có thể hỏi về chuyến (“cần mang áo mưa không?”)."));
        else box.appendChild(el("p", null, "Nhờ sửa: La bàn đề xuất, công cụ Sức khỏe chuyến đi kiểm tra giờ mở cửa, trùng giờ, quãng đường; lịch chỉ đổi khi bạn bấm Áp dụng. Hỏi (“cần mang áo mưa không?”): La bàn trả lời từ lịch của bạn và cẩm nang Travela, có ghi nguồn."));
        if (st.alerts && st.alerts.length) box.appendChild(alertBox());
        var acts = el("div", "ai-co__acts");
        quick().forEach(function (x) {
            var b = button("ai-act" + (x.hot ? " is-hot" : ""), null, function () { send(x.prompt); });
            b.appendChild(el("b", null, x.label));
            b.appendChild(el("small", null, x.prompt));
            acts.appendChild(b);
        });
        box.appendChild(acts);
        feed.appendChild(box);
        renderSug();
    }

    /* ---------------- nhắc tới một mục bằng @ ---------------- */
    function setPicking(on) {
        st.picking = on;
        pickBtn.setAttribute("aria-pressed", String(on));
        document.documentElement.classList.toggle("ai-picking", on);
        var bar = $("aiPickBar");
        if (on && !bar) {
            bar = el("div", "ai-pickbar");
            bar.id = "aiPickBar";
            bar.setAttribute("role", "status");
            bar.appendChild(el("span", null, "Chạm vào một mục trong lịch để nhắc tới nó"));
            bar.appendChild(button("tp-mini", "Hủy", function () { setPicking(false); }));
            document.body.appendChild(bar);
        }
        if (bar) bar.hidden = !on;
    }
    pickBtn.addEventListener("click", function () { setPicking(!st.picking); });
    document.addEventListener("click", function (e) {
        if (!st.picking) return;
        var stop = e.target.closest && e.target.closest(".tpx-stop[data-uid]");
        if (!stop || drawer.contains(stop)) return;
        e.preventDefault();
        e.stopPropagation();
        var it = P() && P().item(stop.getAttribute("data-uid"));
        setPicking(false);
        if (!it) return;
        if (!st.mentions.some(function (m) { return m.name === it.name && m.day === it.day; }) && st.mentions.length < 4) st.mentions.push(it);
        renderChips();
        q.focus();
    }, true);
    function renderChips() {
        chipsBox.textContent = "";
        st.mentions.forEach(function (m, i) {
            var c = el("span", "ai-mchip");
            c.style.setProperty("--c", m.day ? DAYC[(m.day - 1) % DAYC.length] : "#6b7280");
            c.appendChild(el("span", null, "@" + m.name));
            var x = button(null, "×", function () { st.mentions.splice(i, 1); renderChips(); }, "Bỏ");
            x.setAttribute("aria-label", "Bỏ " + m.name);
            c.appendChild(x);
            chipsBox.appendChild(c);
        });
    }

    /* ---------------- gửi ---------------- */
    form.addEventListener("submit", function (e) { e.preventDefault(); send(q.value); });

    function send(text) {
        text = (text || "").trim();
        if (!text || st.busy) return;
        var p = P();
        if (!p) return;
        if (st.cur && st.cur.status === "pending") setStatus(st.cur, "skipped");   // đề xuất cũ chưa xử lý: coi như bỏ qua
        var mentions = st.mentions.slice();
        if (!feed.querySelector(".ai-msg")) feed.textContent = "";
        var me = el("div", "ai-msg ai-msg--me");
        mentions.forEach(function (m) { me.appendChild(el("span", "ai-mchip", "@" + m.name)); me.appendChild(document.createTextNode(" ")); });
        me.appendChild(document.createTextNode(text));
        feed.appendChild(me);
        q.value = "";
        st.mentions = [];
        renderChips();
        var bubble = aiBubble();
        var trace = el("ol", "ai-trace");
        // câu hỏi (F5) và yêu cầu sửa (F3) đi hai luồng khác nhau ở máy chủ: kể đúng việc sẽ làm
        var asking = looksLikeQuestion(text);
        var steps = asking
            ? [el("li", null, "Đọc lịch đã lưu"), el("li", null, "Tìm trong lịch và cẩm nang Travela"), el("li", null, "Kiểm tra từng ý có nguồn")]
            : [el("li", null, "Đọc lịch đã lưu"), el("li", null, "Tìm nơi phù hợp và soạn đề xuất"), el("li", null, "Kiểm chứng bằng Sức khỏe chuyến đi")];
        steps.forEach(function (li) { trace.appendChild(li); });
        bubble.body.appendChild(trace);
        scroll();
        setBusy(true);

        var go = function () {
            steps[0].className = "is-done";
            steps[1].className = "is-run";
            var ph = setTimeout(function () { steps[1].className = "is-done"; steps[2].className = "is-run"; }, 3500);
            st.editedWhileBusy = false;
            post(cfg.url, { text: text, mentions: mentions, history: st.history.slice(-4) }, st.ctl ? st.ctl.signal : undefined)
                .then(function (r) {
                    clearTimeout(ph);
                    bubble.body.textContent = "";
                    st.history.push({ role: "user", text: (mentions.length ? mentions.map(function (m) { return "@" + m.name; }).join(" ") + " " : "") + text });
                    st.history.push({ role: "ai", text: plain(r.say) });
                    st.history = st.history.slice(-6);
                    if (r.kind === "proposal") proposal(bubble, r);
                    else if (r.kind === "suggest") suggestView(bubble, r);
                    else if (r.kind === "answer") answer(bubble, r);
                    else reply(bubble, r);
                })
                .catch(function (s) {
                    clearTimeout(ph);
                    bubble.body.textContent = "";
                    var msg = s && s.name === "AbortError" ? "Đã hủy. Lịch của bạn không đổi."
                        : s === 429 ? "Bạn gửi hơi nhanh. Đợi một phút rồi thử lại nhé."
                        : s === 422 ? "Câu hơi dài hoặc chưa hợp lệ, bạn viết ngắn lại nhé."
                        : "La bàn chưa trả lời được lúc này. Lịch của bạn không bị ảnh hưởng.";
                    bubble.body.appendChild(el("p", "ai-err", msg));
                })
                .then(function () { setBusy(false); scroll(); renderCtx(); });
        };
        // đề xuất dựa trên BẢN ĐÃ LƯU: còn thay đổi chưa lưu thì lưu trước rồi mới hỏi
        if (p.state().dirty) {
            steps[0].textContent = "Lưu thay đổi của bạn trước";
            steps[0].className = "is-run";
            waitSaved(8000).then(function (ok) {
                if (!st.busy) return;
                if (!ok) { bubble.body.textContent = ""; bubble.body.appendChild(el("p", "ai-err", "Chưa lưu được thay đổi của bạn nên La bàn chưa đề xuất. Bấm “Lưu ngay” rồi thử lại.")); setBusy(false); return; }
                go();
            });
            p.saveNow();
        } else go();
    }
    function waitSaved(ms) {
        return new Promise(function (res) {
            var done = false;
            var h = function () { if (done) return; done = true; document.removeEventListener("tp:saved", h); res(true); };
            document.addEventListener("tp:saved", h);
            setTimeout(function () { if (done) return; done = true; document.removeEventListener("tp:saved", h); res(!(P() && P().state().dirty)); }, ms);
        });
    }
    function setBusy(on) {
        st.busy = on;
        busyBox.hidden = !on;
        form.classList.toggle("is-busy", on);
        document.documentElement.classList.toggle("ai-co-busy", on);
        clearInterval(st.tick);
        if (on) {
            st.ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
            st.t0 = Date.now();
            busyT.textContent = "0s";
            st.tick = setInterval(function () {
                var s = Math.round((Date.now() - st.t0) / 1000);
                busyT.textContent = s + "s";
                if (s >= 60 && st.ctl) st.ctl.abort();
            }, 1000);
        } else st.ctl = null;
    }
    $("aiCoCancel").addEventListener("click", function () { if (st.ctl) st.ctl.abort(); });

    function aiBubble() {
        var w = el("div", "ai-msg ai-msg--ai");
        w.appendChild(mark());
        var body = el("div", "ai-msg__b");
        w.appendChild(body);
        feed.appendChild(w);
        return { wrap: w, body: body };
    }
    function scroll() { feed.scrollTop = feed.scrollHeight; }
    function plain(segs) { return (segs || []).map(function (s) { return s.k ? s.name : s.t || ""; }).join("").slice(0, 300); }
    function segs(parent, list) {
        (list || []).forEach(function (s) {
            if (s.k && !s.new) parent.appendChild(button("ai-ent", s.name, function () { jump(s.name); }, "Xem trong lịch"))
                .style.setProperty("--c", s.day ? DAYC[(s.day - 1) % DAYC.length] : "#6b7280");
            else if (s.k) parent.appendChild(el("span", "ai-ent ai-ent--new", s.name));
            else parent.appendChild(document.createTextNode(s.t || ""));
        });
        return parent;
    }
    function jump(name) {
        var hit = findStop(name, null);
        if (!hit) return;
        hit.scrollIntoView({ behavior: "smooth", block: "center" });
        hit.classList.add("ai-flash");
        setTimeout(function () { hit.classList.remove("ai-flash"); }, 1400);
    }
    function findStop(name, day) {
        var hit = null;
        document.querySelectorAll("#tpDaysBox .tpx-stop").forEach(function (li) {
            if (hit) return;
            var n = li.querySelector(".tpx-name");
            if (!n || n.textContent.trim() !== name) return;
            if (day != null) {
                var sec = li.closest(".tpx-day[data-day]");
                if (!sec || +sec.getAttribute("data-day") !== day) return;
            }
            hit = li;
        });
        return hit;
    }

    function reply(bubble, r) {
        if (r.notice) bubble.body.appendChild(el("p", "ai-notice", r.notice));
        bubble.body.appendChild(segs(el("p"), r.say));
        if (r.remaining != null && r.remaining <= 5) bubble.body.appendChild(el("p", "ai-small", "Còn " + r.remaining + " lượt La bàn hôm nay."));
    }

    /** Chỉ để chọn lời kể tiến trình; máy chủ (QuestionRouter) mới quyết định luồng thật. */
    function looksLikeQuestion(t) {
        var low = " " + t.toLowerCase() + " ";
        if (/(chuyển|thêm|đổi|dời|bớt|bỏ|xóa|xoá|xếp|thay bằng|nhẹ hơn|sửa|tối ưu|cân bằng|lùi)(?![a-zà-ỹ])/.test(low)) return false;
        return t.indexOf("?") >= 0 || /\s(có|không|nên|cần|gì|nào|mấy giờ|bao nhiêu|ở đâu|thế nào)\s/.test(low);
    }

    /* ---------------- F5: trả lời có nguồn (lịch của bạn + cẩm nang), không đổi lịch ---------------- */
    function answer(bubble, r) {
        if (r.notice) bubble.body.appendChild(el("p", "ai-notice", r.notice));
        var txt = (r.say && r.say[0] && r.say[0].t) || "";
        var byN = {};
        (r.sources || []).forEach(function (s) { byN[s.n] = s; });
        txt.split("\n").forEach(function (line) {
            if (!line.trim()) return;
            var p = el("p", "ai-ans");
            line.split(/(\s?\[\d{1,2}\])/).forEach(function (part) {
                var m = part.match(/\[(\d{1,2})\]/);
                if (!m) { p.appendChild(document.createTextNode(part)); return; }
                var s = byN[+m[1]];
                var sup = button("ai-cite", m[1], function () { if (s && s.day) jumpDay(s.day); else if (s && s.url) window.open(s.url, "_blank", "noopener"); }, s ? s.title : "");
                p.appendChild(sup);
            });
            bubble.body.appendChild(p);
        });
        var src = (r.sources || []).filter(function (s) { return s.cited; });
        if (!src.length) src = r.sources || [];
        if (src.length) {
            var ul = el("ul", "ai-src");
            src.forEach(function (s) {
                var li = el("li");
                li.appendChild(el("span", "ai-src__n", String(s.n)));
                if (s.day) li.appendChild(button("ai-src__day", "Ngày " + s.day + " trong lịch của bạn", function () { jumpDay(s.day); }));
                else if (s.url) {
                    var a = el("a", null, s.title);
                    a.href = s.url;
                    a.target = "_blank";
                    a.rel = "noopener";
                    li.appendChild(a);
                } else li.appendChild(el("span", null, s.title));
                if (s.trip && !s.day) li.appendChild(el("small", null, " · từ chuyến của bạn"));
                ul.appendChild(li);
            });
            bubble.body.appendChild(ul);
        }
        (r.suggest || []).forEach(function (x) {
            var a = el("a", "ai-chip", x.title);
            a.href = x.url;
            bubble.body.appendChild(a);
        });
        var meta = el("p", "ai-small", r.refused ? "La bàn không đoán khi thiếu căn cứ." : "Chỉ trả lời từ lịch đã lưu và cẩm nang đã duyệt của Travela. Lịch không đổi.");
        bubble.body.appendChild(meta);
    }
    function jumpDay(d) {
        var sec = document.querySelector('#tpDaysBox .tpx-day[data-day="' + d + '"]');
        if (!sec) return;
        if (small()) setOpen(false);
        sec.scrollIntoView({ behavior: "smooth", block: "start" });
        sec.classList.add("ai-flash-day");
        setTimeout(function () { sec.classList.remove("ai-flash-day"); }, 1500);
    }

    /* ---------------- Gợi ý để CHỌN: món ăn, điểm tham quan, lễ hội, cảnh báo (máy chủ: TripSuggest, chạy bằng luật) ----------------
     * POST {suggestUrl} {intent, day?} -> {kind:"suggest", cards:[{kind,title,sub,text,tags,img,url,tone,source,actions:[{label,apply|prompt|url,pickDay}]}], more}
     * POST {suggestApplyUrl} {op,type,refId,toDay,item,startMin,rev} -> phiếu đề xuất như Copilot (kiểm chứng trước, chỉ đổi lịch khi bấm Áp dụng)
     */
    var TOPICS = [["food", "Món nên thử", "fa-utensils"], ["places", "Điểm tham quan", "fa-map-marker-alt"], ["festival", "Lễ hội dịp đi", "fa-calendar-alt"], ["alerts", "Cảnh báo", "fa-exclamation-triangle"]];
    var topicBox = $("aiCoTopics");
    var ICON = { food: "fa-utensils", place: "fa-map-marker-alt", event: "fa-calendar-alt", alert: "fa-exclamation-triangle" };
    function renderTopics() {
        if (!topicBox || !cfg.suggestUrl) return;
        topicBox.textContent = "";
        TOPICS.forEach(function (t) {
            var b = button("ai-topic" + (t[0] === "alerts" && st.alerts && st.alerts.length ? " is-warn" : ""), null, function () { askSuggest(t[0], t[1]); });
            var i = el("i", "fas " + t[2]); i.setAttribute("aria-hidden", "true");
            b.appendChild(i);
            b.appendChild(el("span", null, t[1]));
            if (t[0] === "alerts" && st.alerts && st.alerts.length) b.appendChild(el("b", "ai-topic__n", String(st.alerts.length)));
            topicBox.appendChild(b);
        });
    }
    function alertBox() {
        var n = st.alerts.length, a = st.alerts[0];
        var box = el("div", "ai-alertbox is-" + (a.tone || "warn"));
        var i = el("i", "fas fa-exclamation-triangle"); i.setAttribute("aria-hidden", "true");
        box.appendChild(i);
        var t = el("div");
        t.appendChild(el("b", null, n === 1 ? a.title : "La bàn thấy " + n + " cảnh báo cho lịch của bạn"));
        t.appendChild(el("small", null, n === 1 ? (a.sub || "") : a.title + (n > 1 ? " và " + (n - 1) + " điều khác" : "")));
        box.appendChild(t);
        box.appendChild(button("ai-alertbox__go", "Xem cách đổi lịch", function () { askSuggest("alerts", "Cảnh báo cho chuyến"); }));
        return box;
    }
    function setAlertBadge(n) {
        ["aiCoOpen", "aiCoOpen2", "tpuxMobAi"].forEach(function (id) {
            var b = $(id);
            if (!b) return;
            var x = b.querySelector(".ai-co-badge");
            if (!n) { if (x) x.remove(); b.removeAttribute("data-alerts"); return; }
            if (!x) { x = el("span", "ai-co-badge"); b.appendChild(x); }
            x.textContent = n > 9 ? "9+" : String(n);
            x.setAttribute("aria-label", n + " cảnh báo cho chuyến");
            b.setAttribute("data-alerts", String(n));
        });
    }
    var alertTimer = null;
    function checkAlerts() {
        if (!cfg.suggestUrl) return;
        post(cfg.suggestUrl, { intent: "alerts" }).then(function (r) {
            st.alerts = (r && r.cards) || [];
            setAlertBadge(st.alerts.length);
            renderTopics();
            var idleBox = feed.querySelector(".ai-co__idle");
            if (idleBox && !idleBox.querySelector(".ai-alertbox") && st.alerts.length) idleBox.insertBefore(alertBox(), idleBox.children[2] || null);
        }).catch(function () { /* cảnh báo là phụ */ });
    }
    function askSuggest(intent, label, day) {
        if (st.busy || !cfg.suggestUrl) return;
        if (st.cur && st.cur.status === "pending") setStatus(st.cur, "skipped");
        if (!feed.querySelector(".ai-msg")) feed.textContent = "";
        feed.appendChild(el("div", "ai-msg ai-msg--me", label));
        var bubble = aiBubble();
        bubble.body.appendChild(el("p", "ai-small ai-loading", intent === "alerts" ? "Đang đối chiếu dự báo theo giờ, giờ mở cửa và cảnh báo điểm đến…" : "Đang tìm trong dữ liệu đã duyệt của Travela…"));
        scroll();
        setBusy(true);
        post(cfg.suggestUrl, { intent: intent, day: day || null }, st.ctl ? st.ctl.signal : undefined)
            .then(function (r) {
                bubble.body.textContent = "";
                st.history.push({ role: "user", text: label });
                st.history.push({ role: "ai", text: plain(r.say) });
                st.history = st.history.slice(-6);
                suggestView(bubble, r);
            })
            .catch(function (s) {
                bubble.body.textContent = "";
                bubble.body.appendChild(el("p", "ai-err", s === 429 ? "Bạn bấm hơi nhanh. Đợi một phút rồi thử lại nhé." : "La bàn chưa lấy được gợi ý lúc này. Lịch của bạn không đổi."));
            })
            .then(function () { setBusy(false); scroll(); renderCtx(); });
    }
    function suggestView(bubble, r) {
        if (!r || r.kind !== "suggest") { reply(bubble, r || {}); return; }
        st.intent = r.intent;
        bubble.body.appendChild(segs(el("p"), r.say));
        if (r.intent === "alerts") { st.alerts = r.cards || []; setAlertBadge(st.alerts.length); renderTopics(); }
        if ((r.cards || []).length) {
            var list = el("div", "ai-sg");
            list.setAttribute("role", "list");
            r.cards.forEach(function (c) { list.appendChild(sgCard(c, r)); });
            bubble.body.appendChild(list);
        }
        if ((r.more || []).length) {
            var more = el("div", "ai-sg__more");
            more.appendChild(el("span", "ai-small", "Xem thêm:"));
            r.more.forEach(function (m) { more.appendChild(button("ai-chip", m.label, function () { askSuggest(m.intent, m.label); })); });
            bubble.body.appendChild(more);
        }
    }
    function sgCard(c, r) {
        var card = el("article", "ai-sg__c is-" + c.kind + (c.tone ? " is-" + c.tone : ""));
        card.setAttribute("role", "listitem");
        var h = el("header", "ai-sg__h");
        if (c.img) {
            var im = el("img", "ai-sg__img"); im.src = c.img; im.alt = ""; im.loading = "lazy"; im.width = 56; im.height = 56;
            im.addEventListener("error", function () { im.remove(); });
            h.appendChild(im);
        } else {
            var ic = el("span", "ai-sg__ic"); var ii = el("i", "fas " + (ICON[c.kind] || "fa-map-marker-alt")); ii.setAttribute("aria-hidden", "true"); ic.appendChild(ii); h.appendChild(ic);
        }
        var tt = el("div", "ai-sg__tt");
        if (c.url) { var a = el("a", "ai-sg__name", c.title); a.href = c.url; a.target = "_blank"; a.rel = "noopener"; tt.appendChild(a); }
        else tt.appendChild(el("b", "ai-sg__name", c.title));
        if (c.sub) {
            if (c.item && c.sub) tt.appendChild(button("ai-sg__sub ai-sg__sub--btn", c.sub, function () { if (c.day) jumpDay(c.day); }, "Xem trong lịch"));
            else tt.appendChild(el("small", "ai-sg__sub", c.sub));
        }
        h.appendChild(tt);
        card.appendChild(h);
        if ((c.tags || []).length) {
            var tg = el("div", "ai-sg__tags");
            c.tags.forEach(function (t) { tg.appendChild(el("span", "ai-sg__tag" + (t.tone ? " is-" + t.tone : ""), t.t)); });
            card.appendChild(tg);
        }
        if (c.text) card.appendChild(el("p", "ai-sg__t", c.text));
        if (c.source) card.appendChild(el("p", "ai-sg__src", "Nguồn: " + c.source));
        var acts = el("div", "ai-sg__acts");
        (c.actions || []).forEach(function (x) {
            if (x.url) {
                var l = el("a", "ai-sg__btn ai-sg__btn--link", x.label); l.href = x.url; l.target = "_blank"; l.rel = "noopener"; acts.appendChild(l);
            } else if (x.prompt) {
                acts.appendChild(button("ai-sg__btn" + (x.primary ? " is-primary" : ""), x.label, function () { send(x.prompt); }));
            } else if (x.apply) {
                var ap = Object.assign({}, x.apply);
                var b = button("ai-sg__btn" + (x.primary ? " is-primary" : ""), x.label, function () { applySg(ap, b); }, x.hint || "");
                if (x.pickDay && r.days > 0) {
                    var sel = el("select", "ai-sg__day no-nice");
                    sel.setAttribute("aria-label", "Chọn ngày để thêm " + c.title);
                    for (var d = 1; d <= r.days; d++) {
                        var o = el("option", null, (r.dayLabels && r.dayLabels[d]) || ("Ngày " + d)); o.value = String(d);
                        if (ap.toDay === d) o.selected = true;
                        sel.appendChild(o);
                    }
                    var o2 = el("option", null, "Đã lưu (chưa xếp ngày)"); o2.value = ""; if (!ap.toDay) o2.selected = true; sel.appendChild(o2);
                    sel.addEventListener("change", function () {
                        ap.toDay = sel.value ? +sel.value : null;
                        b.textContent = ap.toDay ? "Thêm vào ngày " + ap.toDay : "Thêm vào Đã lưu";
                    });
                    var g = el("span", "ai-sg__pick"); g.appendChild(b); g.appendChild(sel); acts.appendChild(g);
                } else acts.appendChild(b);
            }
        });
        if (acts.childNodes.length) card.appendChild(acts);
        return card;
    }
    function applySg(ap, btn) {
        var p = P();
        if (st.busy || !p) return;
        if (st.cur && st.cur.status === "pending") setStatus(st.cur, "skipped");
        var bubble = aiBubble();
        bubble.body.appendChild(el("p", "ai-small ai-loading", "Đang kiểm tra giờ mở cửa, trùng giờ và quãng đường…"));
        scroll();
        setBusy(true);
        btn.disabled = true;
        var go = function () {
            post(cfg.suggestApplyUrl, Object.assign({}, ap, { rev: p.state().rev }), st.ctl ? st.ctl.signal : undefined)
                .then(function (r) {
                    bubble.body.textContent = "";
                    if (r.kind === "proposal") { proposal(bubble, r); return; }
                    reply(bubble, r);
                    if (r.stale && st.intent) bubble.body.appendChild(button("ai-chip", "Gợi ý lại", function () { askSuggest(st.intent, "Gợi ý lại"); }));
                })
                .catch(function () { bubble.body.textContent = ""; bubble.body.appendChild(el("p", "ai-err", "Chưa kiểm tra được gợi ý này. Lịch của bạn không đổi.")); })
                .then(function () { setBusy(false); btn.disabled = false; scroll(); });
        };
        if (p.state().dirty) {
            waitSaved(8000).then(function (ok) {
                if (!ok) { bubble.body.textContent = ""; bubble.body.appendChild(el("p", "ai-err", "Chưa lưu được thay đổi của bạn. Bấm “Lưu ngay” rồi thử lại.")); setBusy(false); btn.disabled = false; return; }
                go();
            });
            p.saveNow();
        } else go();
    }
    renderTopics();
    setTimeout(checkAlerts, 1500);
    /* mở La bàn ở một chủ đề từ nơi khác (chuông thông báo, chip trên lịch) */
    window.TpLaBan = {
        ask: function (intent, day) {
            if (!cfg.suggestUrl) return false;
            var t = TOPICS.filter(function (x) { return x[0] === intent; })[0];
            setOpen(true); askSuggest(intent, t ? t[1] : "Gợi ý", day || null);
            return true;
        },
        prompt: function (text) { setOpen(true); var i = document.getElementById("aiCoQ"); if (i) { i.value = text; i.focus(); } return !!i; },
    };
    // ?la-ban=food|places|festival|alerts (từ trang trò chuyện tạo chuyến): mở ngăn và hỏi ngay chủ đề đó
    (function () {
        var m = /[?&]la-ban=(food|places|festival|alerts)\b/.exec(location.search);
        if (!m || !cfg.suggestUrl) return;
        var t = TOPICS.filter(function (x) { return x[0] === m[1]; })[0];
        try { history.replaceState(null, "", location.pathname + location.search.replace(/([?&])la-ban=[a-z]+&?/, "$1").replace(/[?&]$/, "") + location.hash); } catch (e) { /* bỏ qua */ }
        setTimeout(function () { setOpen(true); askSuggest(m[1], t ? t[1] : "Gợi ý"); }, 400);
    })();
    document.addEventListener("tp:saved", function () { clearTimeout(alertTimer); alertTimer = setTimeout(checkAlerts, 4000); });

    /* ---------------- thẻ đề xuất ---------------- */
    function proposal(bubble, r) {
        var card = el("article", "ai-prop");
        var o = { id: r.id, data: r, card: card, status: "pending", bubble: bubble };
        st.cur = o;
        drawProp(o);
        bubble.body.appendChild(card);
        var p = P();
        if (p && p.state().rev !== r.baseRev) setStatus(o, "stale");   // lịch đã đổi trong lúc chờ
        else showGhost(o);
    }
    function drawProp(o) {
        var r = o.data, card = o.card;
        card.textContent = "";
        card.className = "ai-prop is-" + o.status;
        var h = el("header", "ai-prop__h");
        var badge = { pending: "Đề xuất · chưa áp dụng", applied: "Đã áp dụng", skipped: "Đã bỏ qua", stale: "Lịch đã đổi" }[o.status];
        h.appendChild(el("span", "ai-prop__badge", badge));
        if (r.rechecked) h.appendChild(el("span", "ai-small", "đã kiểm tra lại trên bản mới"));
        card.appendChild(h);
        var b = el("div", "ai-prop__b");
        b.appendChild(segs(el("p", "ai-prop__say"), r.say));
        var ul = el("ul", "ai-chg");
        (r.steps_human || []).forEach(function (x) {
            var li = el("li");
            li.appendChild(el("span", "ai-op", OPN[x.op] || x.op));
            li.appendChild(el("span", null, x.text));
            ul.appendChild(li);
        });
        b.appendChild(ul);
        var rc = r.receipt || {};
        if (rc.effects && rc.effects.length) {
            var fx = el("div", "ai-fx");
            rc.effects.forEach(function (e) {
                var row = el("div", "ai-fx__r");
                row.appendChild(el("span", null, "Ngày " + e.day));
                var bar = el("div", "ai-fx__bar");
                bar.setAttribute("role", "img");
                bar.setAttribute("aria-label", "Độ dày ngày " + e.day + " từ " + e.beforePct + "% thành " + e.afterPct + "%");
                var bb = el("span", "ai-fx__b"); bb.style.width = Math.min(100, e.beforePct) + "%";
                var aa = el("span", "ai-fx__a" + (e.afterPct > 100 ? " is-over" : e.afterPct > 85 ? " is-warn" : "")); aa.style.width = Math.min(100, e.afterPct) + "%";
                bar.appendChild(bb); bar.appendChild(aa);
                row.appendChild(bar);
                row.appendChild(el("span", "ai-fx__n", e.beforePct + "% → " + e.afterPct + "%"));
                fx.appendChild(row);
            });
            b.appendChild(fx);
        }
        // phiếu kiểm chứng: đúng những gì TripHealth đã chạy trên bản sau khi áp dụng
        var rec = el("div", "ai-rcpt");
        var rh = el("p", "ai-rcpt__h");
        rh.appendChild(el("span", null, "Phiếu kiểm chứng"));
        if (rc.before && rc.after) rh.appendChild(el("span", null, "Sức khỏe " + rc.before.score + " → " + rc.after.score));
        rec.appendChild(rh);
        var dayed = function (x) { return (x.day && !/^Ngày \d/.test(x.text) ? "Ngày " + x.day + " · " : "") + x.text; };
        var line = function (cls, k, t) { var p = el("p", "ai-rc ai-rc--" + cls); p.appendChild(el("b", null, k)); p.appendChild(el("span", null, t)); rec.appendChild(p); };
        (rc.resolved || []).forEach(function (x) { line("ok", "✓", "Hết: " + dayed(x)); });
        line("ok", "✓", "Không tạo lỗi cứng mới (trùng giờ, đóng cửa, không kịp di chuyển)");
        (rc.warnings || []).forEach(function (x) { line("warn", "!", dayed(x)); });
        if (rc.checked) line("mut", "·", "Đã kiểm tra: " + rc.checked.join(", "));
        b.appendChild(rec);
        card.appendChild(b);

        var f = el("footer", "ai-prop__f");
        if (o.status === "pending") {
            f.appendChild(button("tp-btn ai-apply", "Áp dụng", function () { apply(o); }));
            f.appendChild(button("tp-mini", "Bỏ qua", function () { setStatus(o, "skipped"); }));
            if (small()) f.appendChild(button("tp-mini ai-ghosttoggle", "Xem trên lịch", function () { setOpen(false); o.scrolled = false; showGhost(o); }));
            else f.appendChild(button("tp-mini ai-ghosttoggle", document.documentElement.classList.contains("ai-ghost-off") ? "Hiện trên lịch" : "Ẩn khỏi lịch", function (e) {
                var off = document.documentElement.classList.toggle("ai-ghost-off");
                e.target.textContent = off ? "Hiện trên lịch" : "Ẩn khỏi lịch";
                if (draft) draft.hidden = off;
            }));
        } else if (o.status === "stale") {
            f.appendChild(el("span", "ai-small", "Lịch vừa đổi sau khi La bàn đề xuất."));
            f.appendChild(button("tp-btn ai-apply", "Kiểm tra lại", function (e) { recheck(o, e.target); }));
        } else if (o.status === "applied") {
            f.appendChild(el("span", "ai-small", "Đã áp dụng và tự lưu. Hoàn tác bằng nút ↶ trên thanh công cụ."));
        } else {
            f.appendChild(el("span", "ai-small", "Lịch không đổi."));
        }
        card.appendChild(f);
    }
    function setStatus(o, s) {
        var prev = o.status;
        o.status = s;
        if (s !== "pending") clearGhost();
        drawProp(o);
        if (prev === "pending" && (s === "applied" || s === "skipped")) post(cfg.feedbackUrl, { proposal: o.id, applied: s === "applied" }).catch(function () {});
    }
    function apply(o, retried) {
        var p = P();
        if (!p || o.status !== "pending") return;
        st.applying = true;   // applyAi -> commit -> tp:dirty: không được coi là "lịch đổi ngoài ý muốn"
        var res;
        try { res = p.applyAi({ changes: o.data.changes, baseRev: o.data.baseRev }); } finally { st.applying = false; }
        if (res.ok) { setStatus(o, "applied"); return; }
        if (res.reason === "dirty" && !retried) {
            p.saveNow();
            waitSaved(8000).then(function () {
                if (P().state().rev !== o.data.baseRev) setStatus(o, "stale"); else apply(o, true);
            });
            return;
        }
        setStatus(o, "stale");
    }
    function recheck(o, btn) {
        btn.disabled = true;
        btn.textContent = "Đang kiểm tra…";
        var go = function () {
            post(cfg.recheckUrl, { proposal: o.id }).then(function (r) {
                if (r.kind === "proposal") {
                    o.id = r.id;
                    o.data = r;
                    o.status = P() && P().state().rev === r.baseRev ? "pending" : "stale";
                    drawProp(o);
                    if (o.status === "pending") showGhost(o);
                } else {
                    o.status = "skipped";
                    drawProp(o);
                    var b = aiBubble();
                    reply(b, r);
                    scroll();
                }
            }).catch(function () { btn.disabled = false; btn.textContent = "Kiểm tra lại"; });
        };
        if (P() && P().state().dirty) { P().saveNow(); waitSaved(8000).then(go); } else go();
    }

    /* ---------------- bản nháp trên lịch (ghost) ---------------- */
    function clearGhost() {
        document.querySelectorAll("#tpDaysBox .ai-gin").forEach(function (n) { n.remove(); });
        document.querySelectorAll("#tpDaysBox .ai-gout, #tpDaysBox .ai-gtime").forEach(function (n) { n.classList.remove("ai-gout", "ai-gtime"); });
        document.querySelectorAll("#tpDaysBox .ai-gtag").forEach(function (n) { n.remove(); });
        document.querySelectorAll("#tpDaysBox .tpx-day.ai-gday").forEach(function (n) { n.classList.remove("ai-gday"); });
        if (draft) draft.hidden = true;
    }
    function tag(li, text) {
        var host = li.querySelector(".tpx-name");
        host = host ? host.parentNode : li;
        host.appendChild(el("span", "ai-gtag", text));
    }
    function dayBox(d) { return document.querySelector('#tpDaysBox .tpx-day[data-day="' + d + '"]'); }
    function showGhost(o) {
        clearGhost();
        if (!o || o.status !== "pending") return;
        var first = null;
        (o.data.ghost || []).forEach(function (g) {
            var li, sec;
            if (g.kind === "out" || g.kind === "replace" || g.kind === "time") {
                li = findStop(g.name, g.day);
                if (!li) return;
                li.classList.add(g.kind === "time" ? "ai-gtime" : "ai-gout");
                tag(li, g.kind === "out" ? "→ " + (g.toDay ? "Ngày " + g.toDay : "Đã lưu") : g.kind === "replace" ? "→ thay bằng " + g.withName
                    : "→ " + (g.startMin == null ? "bỏ giờ cố định" : hhmm(g.startMin)));
                first = first || li;
                if (g.kind === "out" && g.toDay) ghostIn(g.toDay, null, g.name, "từ ngày " + g.day, "CHUYỂN ĐẾN");
            }
            if (g.kind === "replace") ghostIn(g.day, g.name, g.withName, "thay cho " + g.name, "MỚI");
            if (g.kind === "add" && g.toDay) { var gi = ghostIn(g.toDay, g.afterName, g.name, g.sub || "", "MỚI"); first = first || gi; }
            if (g.kind === "reorder" || g.kind === "rainplan") {
                sec = dayBox(g.day);
                if (!sec) return;
                sec.classList.add("ai-gday");
                var ol = sec.querySelector(".tpx-stops");
                var note = el("li", "ai-gin ai-gin--note", g.kind === "reorder" ? "Thứ tự mới: " + g.names.join(" → ") : "Lưu phương án dự phòng khi mưa cho ngày này");
                if (ol) ol.insertBefore(note, ol.firstChild);
                first = first || sec;
            }
        });
        if (draft) {
            draft.textContent = "";
            draft.appendChild(mark());
            var t = el("span");
            t.appendChild(el("b", null, "Đang xem bản nháp của La bàn"));
            t.appendChild(document.createTextNode(" · viền vàng là thay đổi chưa áp dụng"));
            draft.appendChild(t);
            var acts = el("span", "ai-draft__act");
            acts.appendChild(button("tp-btn ai-apply", "Áp dụng", function () { apply(o); }));
            acts.appendChild(button("tp-mini", "Bỏ qua", function () { setStatus(o, "skipped"); }));
            draft.appendChild(acts);
            draft.hidden = document.documentElement.classList.contains("ai-ghost-off");
        }
        if (first && !o.scrolled) {
            o.scrolled = true;
            var r = first.getBoundingClientRect();
            if (r.top < 80 || r.bottom > window.innerHeight) first.scrollIntoView({ behavior: "smooth", block: "center" });
        }
    }
    function ghostIn(day, afterName, name, sub, label) {
        var sec = dayBox(day);
        if (!sec) return null;
        sec.classList.add("ai-gday");
        var ol = sec.querySelector(".tpx-stops");
        if (!ol) return null;
        var li = el("li", "ai-gin");
        var b = el("b", null, name);
        b.appendChild(el("span", "ai-newtag", label));
        li.appendChild(b);
        if (sub) li.appendChild(el("small", null, sub));
        var after = afterName ? findStop(afterName, day) : null;
        if (after) {
            var nx = after.nextSibling;
            while (nx && nx.classList && !nx.classList.contains("tpx-stop")) nx = nx.nextSibling;   // bỏ qua dòng đường đi
            ol.insertBefore(li, nx || null);
        } else {
            var empty = ol.querySelector(".tpx-empty");
            if (empty) empty.remove();
            ol.appendChild(li);
        }
        return li;
    }
    function hhmm(m) { return String(Math.floor(m / 60)).padStart(2, "0") + ":" + String(m % 60).padStart(2, "0"); }

    // planner.js dựng lại timeline sau mỗi thay đổi: vẽ lại bản nháp; lịch đổi (người dùng sửa / người khác lưu) thì đề xuất hết hiệu lực
    document.addEventListener("tp:render", function () { if (st.cur && st.cur.status === "pending" && !document.documentElement.classList.contains("ai-ghost-off")) showGhost(st.cur); });
    document.addEventListener("tp:dirty", function () {
        if (st.busy) st.editedWhileBusy = true;
        if (!st.applying && st.cur && st.cur.status === "pending") setStatus(st.cur, "stale");
        if (st.open) renderCtx();
    });
    document.addEventListener("tp:saved", function (e) {
        if (st.cur && st.cur.status === "pending" && e.detail && e.detail.rev !== st.cur.data.baseRev) setStatus(st.cur, "stale");
        if (st.open) renderCtx();
    });
})();
