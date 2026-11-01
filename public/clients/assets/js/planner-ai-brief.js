/* La bàn F1 "Vé hành trình" trên /len-ke-hoach/de-xuat.
 * Câu mô tả -> POST /len-ke-hoach/hieu-yeu-cau -> vẽ (1) câu đã tô màu theo trường, (2) vé hành trình với trạng thái
 * nêu rõ / AI đoán / còn thiếu, (3) cuống vé: điều Travela chưa lọc được. Đồng thời điền form bên dưới qua window.TpSuggest.
 * Rê chuột vào một ô vé thì cụm chữ sinh ra nó sáng lên (và ngược lại).
 * Mọi chữ động gán bằng textContent: không innerHTML với dữ liệu từ máy chủ / người dùng. */
(function () {
    "use strict";
    var form = document.getElementById("aiSay"), box = document.getElementById("aiResult");
    if (!form || !box) return;
    var cfg = {};
    try { cfg = JSON.parse(document.getElementById("aiData").textContent) || {}; } catch (e) { cfg = {}; }
    var L = cfg.labels || {};
    var txt = document.getElementById("aiText"), btn = document.getElementById("aiGo");
    var last = null, busy = false;

    var FIELDS = [
        ["destination", "Điểm đến"], ["days", "Số ngày"], ["when", "Thời gian"], ["people", "Người đi"],
        ["budget", "Ngân sách"], ["pace", "Nhịp đi"], ["interests", "Sở thích", true], ["dislikes", "Không thích", true],
    ];
    var STATE = { said: ["Bạn nói rõ", "said"], guess: ["AI đoán, xem lại", "guess"], miss: ["Còn thiếu", "miss"] };
    var STEP = { destination: "sgS1", when: "sgS2", days: "sgS2", people: "sgS3", budget: "sgS3", pace: "sgS4", maxDifficulty: "sgS4", interests: "sgS4", dislikes: "sgS4" };

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function money(n) { return Math.round(n).toLocaleString("vi-VN") + "đ"; }
    function label(field, v) {
        if (v == null || v === "" || (Array.isArray(v) && !v.length)) return null;
        if (field === "pace" || field === "maxDifficulty") return (L[field] || {})[v] || v;
        if (field === "interests" || field === "dislikes") return v.map(function (k) { return (L[field] || {})[k] || k; }).join(" · ");
        return String(v);
    }

    /* ---------------- gọi máy chủ ---------------- */
    function ask(text) {
        var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, 35000) : null;   // trần phía trình duyệt; máy chủ tự lùi sớm hơn
        return fetch(cfg.briefUrl, {
            method: "POST", credentials: "same-origin", signal: ctl ? ctl.signal : undefined,
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ text: text }),
        }).then(function (r) { clearTimeout(t); return r.ok ? r.json() : Promise.reject(r.status); }, function (e) { clearTimeout(t); return Promise.reject(e); });
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        var text = txt.value.trim();
        if (!text || busy) { txt.focus(); return; }
        busy = true;
        btn.disabled = true;
        btn.classList.add("is-busy");
        btn.textContent = "Đang đọc…";
        box.hidden = false;
        box.textContent = "";
        box.appendChild(skeleton());
        ask(text).then(function (r) {
            last = r;
            render(r);
            if (r.ok) fill(r);
        }).catch(function (s) {
            box.textContent = "";
            var p = el("p", "ai-err", s === 401 ? "Phiên đăng nhập đã hết. Hãy đăng nhập lại để dùng La bàn." : s === 429 ? "Bạn gửi hơi nhanh. Đợi một phút rồi thử lại nhé." : s === 422 ? "Câu hơi dài hoặc trống. Hãy viết gọn trong khoảng 500 ký tự." : "Chưa đọc được câu này lúc này. Bạn vẫn điền form bên dưới như bình thường.");
            box.appendChild(p);
        }).then(function () {
            busy = false;
            btn.disabled = false;
            btn.classList.remove("is-busy");
            btn.textContent = "Hiểu lại";
        });
    });
    txt.addEventListener("keydown", function (e) { if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit")); } });

    function skeleton() {
        var w = el("div", "ai-grid is-loading");
        var a = el("div", "ai-echo"); a.appendChild(el("p", "ai-echo__h", "La bàn đang đọc câu của bạn…")); a.appendChild(el("div", "ai-sk"));
        var b = el("div", "ai-ticket"); b.appendChild(el("div", "ai-ticket__head")); b.appendChild(el("div", "ai-sk ai-sk--tall"));
        w.appendChild(a); w.appendChild(b);
        return w;
    }

    /* ---------------- vẽ kết quả ---------------- */
    function render(r) {
        box.textContent = "";
        if (r.refused) { box.appendChild(el("p", "ai-err", r.refused)); return; }
        var grid = el("div", "ai-grid");
        grid.appendChild(echo(r));
        grid.appendChild(ticket(r));
        box.appendChild(grid);
    }

    /** Câu người dùng, mỗi cụm đã hiểu được tô theo trạng thái của trường. */
    function echo(r) {
        var wrap = el("div", "ai-echo");
        wrap.appendChild(el("p", "ai-echo__h", r.engine === "llm" ? "La bàn đã đọc" : "Travela đã đọc (chế độ cơ bản)"));
        var p = el("p", "ai-echo__t");
        var text = r.text || "", low = text.toLowerCase(), marks = [];
        (r.spans || []).forEach(function (s) {
            var at = low.indexOf(s.text.toLowerCase());
            while (at >= 0 && marks.some(function (m) { return at < m.b && at + s.text.length > m.a; })) at = low.indexOf(s.text.toLowerCase(), at + 1);
            if (at >= 0) marks.push({ a: at, b: at + s.text.length, f: s.field, st: s.state });
        });
        marks.sort(function (x, y) { return x.a - y.a; });
        var pos = 0;
        marks.forEach(function (m) {
            if (m.a > pos) p.appendChild(document.createTextNode(text.slice(pos, m.a)));
            var mk = el("mark", "ai-hl", text.slice(m.a, m.b));
            mk.dataset.k = m.f;
            mk.dataset.st = m.st;
            mk.title = (STATE[m.st] || STATE.said)[0];
            p.appendChild(mk);
            pos = m.b;
        });
        if (pos < text.length) p.appendChild(document.createTextNode(text.slice(pos)));
        wrap.appendChild(p);
        if (!marks.length) wrap.appendChild(el("p", "ai-small", r.engine === "llm" ? "" : "Chế độ cơ bản không tô được từng cụm chữ, nhưng vẫn điền form như bên cạnh."));
        if (r.notice) wrap.appendChild(el("p", "ai-notice", r.notice));
        (r.warnings || []).filter(function (w) { return w.type !== "unsupported"; }).forEach(function (w) { wrap.appendChild(el("p", "ai-notice", w.text)); });
        var lg = el("p", "ai-legend");
        ["said", "guess", "miss"].forEach(function (k) { lg.appendChild(el("span", "ai-st ai-st--" + k, STATE[k][0])); });
        wrap.appendChild(lg);
        return wrap;
    }

    function ticket(r) {
        var t = el("article", "ai-ticket");
        t.setAttribute("aria-label", "Vé hành trình");
        var head = el("div", "ai-ticket__head");
        var h = el("div"); h.appendChild(el("b", null, "Vé hành trình")); h.appendChild(el("small", null, r.engine === "llm" ? "La bàn đọc từ câu của bạn" : "Chế độ cơ bản"));
        head.appendChild(h);
        var req = ["destination", "days", "when"].filter(function (f) { return r.fields[f].state !== "miss"; }).length;
        head.appendChild(el("span", "ai-ticket__count", req + "/3 mục bắt buộc"));
        t.appendChild(head);

        var route = el("div", "ai-ticket__route");
        var dv = r.fields.destination.value;
        route.appendChild(el("span", "ai-ticket__code", dv ? code(dv) : "???"));
        route.appendChild(el("span", "ai-ticket__dest", dv ? dv + (r.ready === false ? " · đề xuất mở rộng" : r.ready ? " · đủ dữ liệu biên tập" : "") : (r.mention ? "“" + r.mention + "” chưa có trên Travela" : "Chưa rõ điểm đến")));
        t.appendChild(route);

        var grid = el("div", "ai-fields");
        var needBy = {};
        (r.needs || []).forEach(function (n) { needBy[n.field === "budget_scope" ? "budget" : n.field] = n; });
        FIELDS.forEach(function (f) {
            var d = r.fields[f[0]];
            if (!d) return;
            grid.appendChild(field(f[0], f[1], d, needBy[f[0]], f[2]));
        });
        t.appendChild(grid);
        if (needBy.single_destination) t.appendChild(askBox(needBy.single_destination, null));

        t.appendChild(el("div", "ai-perf"));
        var stub = el("div", "ai-stub");
        stub.appendChild(el("p", "ai-stub__h", "Ghi chú cho chuyến · điều Travela chưa lọc được"));
        var uns = (r.warnings || []).filter(function (w) { return w.type === "unsupported"; });
        if (uns.length) {
            uns.forEach(function (w) {
                var c = el("div", "ai-honest");
                c.appendChild(el("b", null, "“" + w.text + "”"));
                c.appendChild(el("span", null, "Travela chưa có dữ liệu để lọc theo ý này, nên lịch chưa được lọc. Hãy kiểm tra từng nơi trong lịch."));
                stub.appendChild(c);
            });
        }
        var notes = (r.prefill && r.prefill.notes) || "";
        if (notes) stub.appendChild(el("p", "ai-small", "Ghi chú lưu cùng chuyến: " + notes));
        if (!uns.length && !notes) stub.appendChild(el("p", "ai-small", "Không có ý nào ngoài các lựa chọn có sẵn."));
        t.appendChild(stub);

        var foot = el("div", "ai-ticket__foot");
        foot.appendChild(el("span", "ai-small", "Form bên dưới đã được điền. Bạn sửa được mọi ô trước khi xếp lịch."));
        var go = el("button", "tp-btn", "Xem trước lịch trình");
        go.type = "button";
        go.addEventListener("click", function () {
            var miss = (last.needs || []).filter(function (n) { return !n.optional; });
            if (miss.length) { var q = t.querySelector(".ai-ask button"); if (q) { q.focus(); q.scrollIntoView({ behavior: "smooth", block: "center" }); } return; }
            if (window.TpSuggest) window.TpSuggest.submit();
        });
        foot.appendChild(go);
        t.appendChild(foot);
        return t;
    }

    function field(key, name, d, need, wide) {
        var f = el("div", "ai-field" + (wide ? " ai-field--wide" : ""));
        f.dataset.k = key;
        f.dataset.st = d.state;
        f.tabIndex = 0;
        var k = el("div", "ai-field__k", name + " ");
        k.appendChild(el("span", "ai-st ai-st--" + d.state, d.state === "said" ? "nêu rõ" : d.state === "guess" ? "AI đoán" : key === "destination" || key === "days" || key === "when" ? "cần chọn" : "chưa nói"));
        f.appendChild(k);
        f.appendChild(el("div", "ai-field__v", label(key, d.value) || "—"));
        if (d.note) f.appendChild(el("div", "ai-small", d.note));
        if (need) f.appendChild(askBox(need, f));
        return f;
    }

    function askBox(need, fieldEl) {
        var w = el("div", "ai-ask");
        if (need.question) w.appendChild(el("p", "ai-small", need.question));
        var row = el("div", "ai-ask__opts");
        (need.options || []).forEach(function (o) {
            var b = el("button", "tp-chip", o.label);
            b.type = "button";
            b.addEventListener("click", function () { choose(need.field, o, fieldEl, w); });
            row.appendChild(b);
        });
        w.appendChild(row);
        return w;
    }

    /** Người dùng trả lời câu hỏi lại: điền form, ô chuyển sang "nêu rõ". */
    function choose(field, o, fieldEl, w) {
        var S = window.TpSuggest;
        var shown = o.label;
        if (field === "destination" && S) S.selectDest(o.value);
        else if (field === "days" && S) S.setDays(o.value);
        else if (field === "when" && S) S.setMonth(o.value);
        else if (field === "budget_scope" && S && last.prefill.budget) {
            var total = o.value === "perPerson" ? last.prefill.budget * S.people() : last.prefill.budget;
            S.setBudget(total);
            shown = money(total) + (o.value === "perPerson" ? " (" + money(last.prefill.budget) + " × " + S.people() + " người)" : " cho cả nhóm");
        } else if (field === "single_destination") {
            txt.value = txt.value + " (bắt đầu với " + o.label + ")";
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit"));
            return;
        }
        last.needs = (last.needs || []).filter(function (n) { return n.field !== field; });
        if (fieldEl) {
            fieldEl.dataset.st = "said";
            var st = fieldEl.querySelector(".ai-st");
            st.className = "ai-st ai-st--said";
            st.textContent = "bạn chọn";
            fieldEl.querySelector(".ai-field__v").textContent = shown;
            if (field === "destination") { var c = box.querySelector(".ai-ticket__code"); if (c) c.textContent = code(o.label); }
        }
        w.remove();
        var cnt = box.querySelector(".ai-ticket__count");
        if (cnt) cnt.textContent = (3 - (last.needs || []).filter(function (n) { return ["destination", "days", "when"].indexOf(n.field) >= 0; }).length) + "/3 mục bắt buộc";
    }

    /** "Hà Nội" -> "HAN", "Đà Lạt" -> "DAL", "Huế" -> "HUE": mã kiểu vé máy bay, chỉ để nhận diện nhanh. */
    function code(name) {
        var a = String(name).normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/gi, "d").toUpperCase().replace(/[^A-Z ]/g, "").trim().split(/\s+/);
        var s = a.length > 1 ? a[0].slice(0, 2) + a[1].slice(0, 1) : a[0].slice(0, 3);
        return s.padEnd(3, "X");
    }

    /* ---------------- điền form ---------------- */
    function fill(r) {
        var S = window.TpSuggest, p = r.prefill || {};
        if (!S) return;
        if (p.destinationId) S.selectDest(p.destinationId);
        if (p.startDate) S.setStart(p.startDate); else if (p.month) S.setMonth(p.month);
        if (p.days) S.setDays(p.days);
        if (p.adults) S.setPeople(p.adults, p.children);
        if (p.budget != null) S.setBudget(p.budget);
        if (p.pace) S.setRadio("pace", p.pace);
        if (p.maxDifficulty) S.setRadio("maxDifficulty", p.maxDifficulty);
        if (p.interests) S.setChecks("interests", p.interests);
        if (p.dislikes) S.setChecks("dislikes", p.dislikes);
        var n = document.getElementById("sgNotes");
        if (n) n.value = p.notes || "";
        flags(r);
    }

    /** Ở từng bước của form, ghi rõ ô nào do AI đoán để người dùng kiểm tra lại. */
    function flags(r) {
        document.querySelectorAll(".ai-flag").forEach(function (x) { x.remove(); });
        var by = {};
        Object.keys(r.fields).forEach(function (k) {
            if (r.fields[k].state === "guess" && STEP[k]) (by[STEP[k]] = by[STEP[k]] || []).push(k);
        });
        var NAME = { destination: "điểm đến", when: "thời gian", days: "số ngày", people: "số người", budget: "ngân sách", pace: "nhịp đi", maxDifficulty: "độ khó", interests: "sở thích", dislikes: "điều không thích" };
        Object.keys(by).forEach(function (id) {
            var h = document.getElementById(id);
            if (!h) return;
            var p = el("p", "ai-flag", "La bàn đoán " + by[id].map(function (k) { return NAME[k]; }).join(", ") + ". Kiểm tra lại giúp mình nhé.");
            h.insertAdjacentElement("afterend", p);
        });
    }

    /* ---------------- liên kết câu ↔ vé ---------------- */
    function hot(k, on) {
        box.querySelectorAll('.ai-hl[data-k="' + k + '"], .ai-field[data-k="' + k + '"]').forEach(function (x) { x.classList.toggle("is-hot", on); });
    }
    ["mouseover", "focusin"].forEach(function (ev) {
        box.addEventListener(ev, function (e) { var t = e.target.closest(".ai-hl, .ai-field"); if (t) hot(t.dataset.k, true); });
    });
    ["mouseout", "focusout"].forEach(function (ev) {
        box.addEventListener(ev, function (e) { var t = e.target.closest(".ai-hl, .ai-field"); if (t) hot(t.dataset.k, false); });
    });

    // đến từ ô tìm kiếm với ?q= : đọc luôn
    if (txt.value.trim() && /[?&]q=/.test(location.search)) {
        setTimeout(function () { form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit")); }, 50);
    }
})();
