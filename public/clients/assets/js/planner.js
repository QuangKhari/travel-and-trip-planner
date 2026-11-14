/* Trình soạn chuyến đi (/len-ke-hoach/{id}). JS thuần, dữ liệu từ <script type="application/json" id="tpData">.
 *
 * Bố cục "Timeline + Bản đồ" (tham khảo Wanderlog và Roadtrippers):
 * - Dải ngày phía trên: mỗi ngày có thanh "độ dày" (phút hoạt động + di chuyển so với quỹ thời gian trong ngày).
 * - Mỗi ngày là một timeline theo buổi (sáng/trưa/chiều/tối): giờ bắt đầu - kết thúc được XẾP TỰ ĐỘNG từ thứ tự,
 *   thời lượng ở lại và quãng đường giữa các điểm; người dùng có thể ghim giờ cố định hoặc đổi thời lượng cho từng mục.
 * - Bản đồ liên kết hai chiều: rê/bấm một mục -> ghim sáng lên; bấm ghim -> cuộn tới mục. Bản đồ tự theo ngày đang xem.
 * - Cảnh báo nằm ngay trong ngày bị ảnh hưởng (quá dày, đi lại nhiều, trùng giờ) kèm nút xử lý; "Tối ưu tuyến" hiện
 *   trước/sau để người dùng quyết định áp dụng hay không.
 *
 * Trạng thái nằm trong mảng `items` + các ô thông tin; mọi chữ động gán bằng textContent (không innerHTML).
 * - Hoàn tác / làm lại: mỗi thay đổi đã "chốt" (commit) so với ảnh chụp trước đó và đẩy vào ngăn xếp.
 * - Tự lưu sau vài giây không thao tác; lưu xong không tải lại trang. Trạng thái lưu hiện ở đầu trang.
 * - Quãng đường theo ngày tính ngay trên trình duyệt bằng planner-geo.js (cùng công thức với máy chủ).
 * - Kéo-thả bằng HTML5 (tay cầm); trên cảm ứng dùng nút ↑ ↓ và ô chọn ngày trong phần "Sửa".
 */
(function () {
    "use strict";
    var node = document.getElementById("tpData");
    if (!node) return;
    var cfg = JSON.parse(node.textContent);
    // Quy tắc nghiệp vụ đến từ máy chủ (PlannerRules::client()); TpGeo / TpSchedule chỉ đọc từ đây, không tự đặt hằng số.
    var RULES = cfg.rules || {};
    var G = window.TpGeo.configure(RULES.route);
    var S = window.TpSchedule.create(RULES, G);
    var API = window.TpApi.create(cfg);                     // mọi yêu cầu tới máy chủ (planner-api.js)
    var fetchJson = API.getJson, post = API.post;

    var TYPE = { destination: "Điểm đến", place: "Địa điểm", food: "Món ăn", event: "Lễ hội", experience: "Trải nghiệm", tour: "Tour", note: "Mục tự thêm", transport: "Di chuyển" };
    var ICON = { destination: "fa-map-marker-alt", place: "fa-landmark", food: "fa-utensils", event: "fa-calendar-alt", experience: "fa-hiking", tour: "fa-suitcase-rolling", note: "fa-sticky-note", transport: "fa-bus", lodging: "fa-bed", meal: "fa-utensils" };
    var CUSTOM = { note: true, transport: true };
    var SLOT = {};
    Object.keys(RULES.slots || {}).forEach(function (k) { SLOT[k] = RULES.slots[k].label; });
    var SLOT_HEAD = { morning: ["fa-sun", "Buổi sáng"], noon: ["fa-utensils", "Buổi trưa"], afternoon: ["fa-cloud-sun", "Buổi chiều"], evening: ["fa-moon", "Buổi tối"] };
    var DAY_START = RULES.dayStart, LATE_END = RULES.dayEnd;   // bắt đầu tự xếp; kết thúc sau giờ này là muộn
    // Độ dày ngày (khớp App\Services\Planner\Health\DaySchedule): (hoạt động + di chuyển + bữa ăn chừa sẵn + đệm) / thời gian dùng được
    var DAY_AVAIL = RULES.availableMin;   // sức chứa một ngày (độ dày = phút đã dùng / con số này), cùng DaySchedule
    var DURS = [15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480];
    var SUGG_TITLE = { tray: "Từ khay chuyến đi", tour: "Tour sắp khởi hành", experience: "Trải nghiệm", food: "Món nên thử", event: "Lễ hội" };
    var COLORS = ["#167A85", "#c2410c", "#1d4ed8", "#a21caf", "#4d7c0f", "#be123c", "#0e7490", "#92400e", "#4338ca", "#15803d", "#b45309", "#6d28d9", "#0369a1", "#9f1239"];
    var LIMITS = cfg.limits || { maxDays: 14, maxItems: 80 };
    var DAY_ITEMS_WARN = RULES.itemsWarn || 7, DAY_TRAVEL_WARN_MIN = RULES.travelWarnMin || 180, AUTOSAVE_MS = 2500;
    var SRC = { link: "từ link", map: "chọn trên bản đồ", search: "từ tìm kiếm" };
    var SHORT_MSG = "Link rút gọn không chứa tọa độ. Hãy mở link trong trình duyệt rồi chép link đầy đủ ở thanh địa chỉ, hoặc bấm “Chọn trên bản đồ”.";
    var BAD_POS_MSG = "Chưa đọc được tọa độ. Dán link Google Maps đầy đủ hoặc nhập dạng 21.0285, 105.8542.";

    var $ = function (id) { return document.getElementById(id); };
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function btn(cls, text, label, fn) {
        var b = el("button", cls, text);
        b.type = "button";
        if (label) b.setAttribute("aria-label", label);
        b.addEventListener("click", fn);
        return b;
    }
    function icon(name) {
        var i = el("i", "fas " + name);
        i.setAttribute("aria-hidden", "true");
        return i;
    }
    /** Nút có biểu tượng + chữ (chữ đặt bằng textContent). */
    function ibtn(cls, ic, text, label, fn) {
        var b = btn(cls, null, label, fn);
        b.appendChild(icon(ic));
        if (text) b.appendChild(el("span", null, text));
        return b;
    }
    function safeUrl(u) { return /^(\/|https?:\/\/)/.test(u || "") ? u : null; }
    function money(n) { return Math.round(n || 0).toLocaleString("vi-VN") + "đ"; }
    function shortMoney(n) {
        n = Math.round(n || 0);
        if (n >= 1e6) return (Math.round(n / 1e5) / 10).toLocaleString("vi-VN") + " tr";
        if (n >= 1e3) return Math.round(n / 1e3).toLocaleString("vi-VN") + "k";
        return n + "đ";
    }
    function dur(min) { return Math.floor(min / 60) + "h" + String(min % 60).padStart(2, "0"); }
    function durText(min) {
        min = Math.round(min);
        if (min < 60) return min + " phút";
        return Math.floor(min / 60) + " giờ" + (min % 60 ? " " + (min % 60) + " phút" : "");
    }
    function pad2(n) { return String(n).padStart(2, "0"); }
    function hhmm(m) { m = ((Math.round(m) % 1440) + 1440) % 1440; return pad2(Math.floor(m / 60)) + ":" + pad2(m % 60); }
    function parseHHMM(v) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(String(v || ""));
        if (!m) return null;
        var h = +m[1], mi = +m[2];
        return h > 23 || mi > 59 ? null : h * 60 + mi;
    }
    function dayColor(d) { return d == null ? "#6b7280" : COLORS[(d - 1) % COLORS.length]; }

    /* ---------------- trạng thái ---------------- */
    var seq = 0;
    var items = cfg.items.map(function (x) {
        x.uid = ++seq;
        x.day = x.day == null ? null : +x.day;
        x.cost = x.cost == null || x.cost === "" ? null : +x.cost;
        x.note = x.note || "";
        x.slot = x.slot || null;
        x.reasons = x.reasons || [];
        x.startMin = x.startMin == null ? null : +x.startMin;
        x.durationMin = x.durationMin ? +x.durationMin : null;
        x.defDur = x.defDur ? +x.defDur : null;
        x.locked = !!x.locked;
        x.legMode = x.legMode || null;
        x.visitedAt = x.visitedAt || null;
        x.skippedAt = x.skippedAt || null;
        return x;
    });
    var sugg = cfg.suggestions || {};
    var ROLE = cfg.role || "owner", RO = ROLE === "view", rev = cfg.rev || 0;
    /* Phương án khi mưa: { "<ngày>": [{type, refId, name, sub, url}] }, tách khỏi lịch chính và chi phí chính */
    var rain = (function (p) {
        var o = {};
        if (p && !Array.isArray(p)) Object.keys(p).forEach(function (k) { if (Array.isArray(p[k]) && p[k].length) o[k] = p[k]; });
        return o;
    })(cfg.rain && cfg.rain.plan);
    var RAIN_MAX = cfg.rainMax || 4;
    var undo = [], redo = [], last = "", savedSnap = "";
    var dirty = false, saving = false, pendingSave = false, autoFail = false, timer = null;
    var ui = { tab: "plan", target: 1, mapDay: "follow", curDay: null, view: "full", collapsed: {}, opt: null, healthOpen: null, dtab: "find",
        around: { day: null, km: 1, group: "all", data: null, seq: 0, loading: false, pts: [] },
        sel: {}, open: {}, tier: null, results: null, searchSeq: 0, res: null, br: null, drag: null, pick: null, draft: null };
    try { var tv = localStorage.getItem("tpView"); if (tv === "compact" || tv === "list") ui.view = tv; } catch (e) { /* bỏ qua */ }

    var box = $("tpDaysBox"), msg = $("tpMsg");

    function daysCount() {
        var n = parseInt($("tpDays").value, 10);
        return isNaN(n) ? 1 : Math.max(1, Math.min(LIMITS.maxDays, n));
    }
    function meta() {
        var b = $("tpBudget").value;
        return {
            title: $("tpTitle").value.trim(),
            start: $("tpStart").value || null,
            days: daysCount(),
            group: Math.max(1, Math.min(50, parseInt($("tpGroup").value, 10) || 1)),
            budget: b === "" ? null : +b,
            dest: $("tpDest").value ? +$("tpDest").value : null,
        };
    }
    function snap() {
        return JSON.stringify({ m: meta(), i: items.map(function (x) { return x; }), r: rain });
    }
    function restore(s) {
        var o = JSON.parse(s), m = o.m;
        $("tpTitle").value = m.title;
        $("tpStart").value = m.start || "";
        $("tpDays").value = m.days;
        $("tpGroup").value = m.group;
        $("tpBudget").value = m.budget == null ? "" : m.budget;
        $("tpDest").value = m.dest == null ? "" : String(m.dest);
        items = o.i;
        rain = o.r || {};
        ui.sel = {}; ui.open = {}; ui.opt = null; ui.bal = null;
        renderAll();
    }
    /* Khôi phục bản nháp sau reload/mất mạng. Chỉ lưu ở trình duyệt hiện tại.
       Dữ liệu lịch có thể nhạy cảm: không đồng bộ qua máy chủ và xóa sau 7 ngày. */
    var DRAFT_KEY = "travela:planner:draft:v1:" + String(cfg.me || "guest") + ":" + String(cfg.trip.id);
    var DRAFT_TTL = 7 * 24 * 60 * 60 * 1000;
    function backupDraft() {
        if (RO || !dirty) return;
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify({
                version: 1, savedAt: Date.now(), baseRev: rev, snapshot: snap()
            }));
        } catch (e) { /* storage đầy/chặn: cơ chế autosave chính vẫn hoạt động */ }
    }
    function clearDraft() {
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
    }
    function restoreDraftIfNeeded() {
        if (RO) return;
        var d = null;
        try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || "null"); } catch (e) { clearDraft(); return; }
        if (!d || d.version !== 1 || typeof d.snapshot !== "string" ||
            !Number.isInteger(d.baseRev) || !Number.isFinite(d.savedAt) ||
            Date.now() - d.savedAt > DRAFT_TTL || d.savedAt > Date.now() + 60000) {
            if (d) clearDraft();
            return;
        }
        if (d.snapshot === savedSnap) { clearDraft(); return; }
        // Khi server đã thay đổi, không tự phục hồi: phải tránh ghi đè của người cộng tác.
        if (d.baseRev !== rev) {
            say("Có bản nháp trên máy nhưng chuyến trên máy chủ đã đổi. Chưa khôi phục để tránh ghi đè.", true,
                [["Bỏ bản nháp", clearDraft]]);
            return;
        }
        if (!window.confirm("Travela tìm thấy lịch chưa lưu từ phiên trước. Khôi phục bản nháp trên thiết bị này?")) return;
        try {
            var parsed = JSON.parse(d.snapshot);
            if (!parsed || !parsed.m || !Array.isArray(parsed.i) || !parsed.r ||
                parsed.i.length > (LIMITS.maxItems || 80)) throw new Error("invalid draft");
            restore(d.snapshot);
            last = snap();
            markDirty();
            say("Đã khôi phục bản nháp. Travela sẽ thử lưu lại.");
        } catch (e) {
            clearDraft();
            say("Bản nháp không hợp lệ, đã giữ nguyên dữ liệu từ máy chủ.", true);
        }
    }

    /** Bớt số ngày: mục của các ngày bị bớt chuyển vào "Đã lưu" (không dồn vào ngày cuối làm ngày đó quá tải, không mất mục). */
    function clampDays() {
        var n = daysCount(), moved = 0;
        items.forEach(function (it) { if (it.day != null && it.day > n) { it.day = null; it.startMin = null; moved++; } });
        $("tpDays").value = n;
        if (moved) say(moved + " mục của các ngày bị bớt đã chuyển vào “Đã lưu”. Kéo lại vào ngày khác khi cần.");
    }
    function key(it) { return it.type + ":" + it.refId; }
    function haveMap() {
        var h = {};
        items.forEach(function (x) { if (!CUSTOM[x.type]) h[key(x)] = true; });
        return h;
    }
    function dayLabel(d) {
        var s = $("tpStart").value;
        if (!s) return "Ngày " + d;
        var dt = new Date(s + "T00:00:00");
        if (isNaN(dt.getTime())) return "Ngày " + d;
        dt.setDate(dt.getDate() + d - 1);
        return "Ngày " + d + " · " + dt.toLocaleDateString("vi-VN", { weekday: "short", day: "2-digit", month: "2-digit" });
    }
    function targetLabel(d) { return d == null ? "Chưa xếp ngày" : dayLabel(d); }

    /* ---------------- chốt thay đổi / hoàn tác ---------------- */
    /** Thông báo ngắn cho thao tác vừa làm (hiện ở góc dưới, tự ẩn). */
    function say(t, bad, acts) {
        var tt = $("tpToast");
        if (!tt) {
            tt = el("div", "tpx-toast");
            tt.id = "tpToast";
            tt.setAttribute("role", "status");
            tt.setAttribute("aria-live", "polite");
            document.body.appendChild(tt);
        }
        tt.textContent = "";
        tt.appendChild(el("span", null, t || ""));
        (acts || []).forEach(function (a) {
            tt.appendChild(btn("tpx-toast__b", a[0], null, function () { tt.classList.remove("is-on"); a[1](); }));
        });
        tt.classList.toggle("has-acts", !!(acts && acts.length));
        tt.classList.toggle("is-bad", !!bad);
        tt.classList.toggle("is-on", !!t);
        clearTimeout(say.t);
        if (t) say.t = setTimeout(function () { tt.classList.remove("is-on"); }, acts && acts.length ? 9000 : bad ? 6500 : 3200);
    }
    /** Trạng thái lưu ở đầu trang (tự lưu là chính, nút "Lưu ngay" chỉ hiện khi còn thay đổi chưa lưu). */
    function saveState(state, text) {
        if (!msg) return;
        msg.textContent = "";
        msg.className = "tpx-save tpx-save--" + state;
        var ic = { saved: "fa-check-circle", dirty: "fa-pen", saving: "fa-sync-alt fa-spin", error: "fa-exclamation-triangle", ro: "fa-eye" }[state];
        if (ic) msg.appendChild(icon(ic));
        msg.appendChild(el("span", null, text));
        var sv = $("tpSave");
        if (sv && !RO) {
            sv.hidden = !(state === "dirty" || state === "error");
            sv.textContent = state === "error" ? "Thử lại" : "Lưu ngay";   // lưu lỗi: nói rõ và cho thử lại ngay
            sv.classList.toggle("is-retry", state === "error");
        }
    }
    function markDirty() {
        if (RO) return;
        dirty = true;
        autoFail = false;
        backupDraft();
        saveState("dirty", "Chưa lưu");
        emit("tp:dirty");
        clearTimeout(timer);
        timer = setTimeout(function () { save(false); }, AUTOSAVE_MS);
    }
    /** keepList = true: không dựng lại danh sách (đang gõ trong một ô, dựng lại sẽ mất con trỏ). */
    function commit(keepList) {
        if (RO) return;
        var now = snap();
        if (now === last) return;
        undo.push(last);
        if (undo.length > 60) undo.shift();
        redo = [];
        last = now;
        markDirty();
        renderAll(keepList);
    }
    function undoOnce() {
        if (!undo.length) return;
        redo.push(last);
        last = undo.pop();
        restore(last);
        markDirty();
    }
    function redoOnce() {
        if (!redo.length) return;
        undo.push(last);
        last = redo.pop();
        restore(last);
        markDirty();
    }
    function syncButtons() {
        $("tpUndo").disabled = !undo.length;
        $("tpRedo").disabled = !redo.length;
    }

    /* ---------------- thống kê ---------------- */
    function dayItems(d) { return items.filter(function (x) { return x.day === d; }); }
    function totalCost() { return items.reduce(function (s, x) { return s + (x.cost || 0); }, 0); }

    /* ---------------- giá tham khảo (ước tính khi chưa nhập) ---------------- */
    var REF = cfg.costRef || null;
    var TIER_LABEL = { low: "Tiết kiệm", mid: "Vừa phải", high: "Thoải mái" };
    function tier() {
        if (ui.tier) return ui.tier;
        var m = meta();
        if (REF && m.budget > 0) {
            var per = m.budget / (m.group * m.days), th = REF.tierBudgetPerPersonDay || {};
            return per < (th.low || 0) ? "low" : per < (th.mid || 0) ? "mid" : "high";
        }
        return "mid";
    }
    var kindOf = S.kindOf;   // lodging | meal | experience | transport | null (planner-schedule.js, mẫu nhận diện từ máy chủ)
    function roomsNeeded() { return Math.max(1, Math.ceil(meta().group / ((REF && REF.peoplePerRoom) || 2))); }
    function nightsCount() { return Math.max(1, daysCount() - 1); }
    /** Giá ước tính của MỘT mục chưa nhập chi phí; null nếu không ước tính được (địa điểm, lễ hội...). */
    function estItem(it) {
        if (!REF || it.cost != null) return null;
        var k = kindOf(it), t = tier(), m = meta();
        if (k === "meal" || k === "experience") return REF[k][t] * m.group;
        if (k === "transport") return REF.transport[t];
        if (k === "lodging") return REF.lodging[t] * roomsNeeded() * nightsCount();
        return null;
    }
    /** Dự toán tham khảo: ước tính theo mục + các khoản chưa có mục nào (lưu trú, ăn uống, đi lại). */
    function estimates() {
        var out = { items: [], itemsSum: 0, gaps: [], gapsSum: 0, total: 0 };
        if (!REF) return out;
        // chỉ ước tính cho mục đã xếp vào ngày: mục trong "Đã lưu" chưa thuộc kế hoạch nên chưa cộng
        items.forEach(function (it) {
            if (it.day == null) return;
            var e = estItem(it);
            if (e) { out.items.push({ it: it, est: e }); out.itemsSum += e; }
        });
        var n = daysCount(), t = tier(), g = meta().group, L = 0, M = 0, T = 0;
        items.forEach(function (it) {
            if (it.day == null) return;
            var k = kindOf(it);
            if (k === "lodging") L++; else if (k === "meal") M++; else if (k === "transport") T++;
        });
        if (n > 1 && !L) out.gaps.push({ label: "Lưu trú " + nightsCount() + " đêm × " + roomsNeeded() + " phòng", amount: REF.lodging[t] * roomsNeeded() * nightsCount() });
        var meals = Math.max(0, n * (REF.mealsPerDay || 3) - M);
        if (meals) out.gaps.push({ label: "Ăn uống chưa lên kế hoạch (" + meals + " bữa × " + g + " người)", amount: meals * REF.meal[t] * g });
        if (!T) out.gaps.push({ label: "Đi lại tại chỗ (" + n + " ngày)", amount: n * REF.transport[t] });
        out.gaps.forEach(function (x) { out.gapsSum += x.amount; });
        out.total = out.itemsSum + out.gapsSum;
        return out;
    }
    function dayStats(list) {
        var g = G.legs(list);
        return { km: g.km, minutes: g.minutes, legs: g.legs, cost: list.reduce(function (s, x) { return s + (x.cost || 0); }, 0) };
    }
    /* ---------------- timeline: xếp giờ trong ngày ---------------- */
    var itemDur = S.itemDur;
    /** Nhãn loại trên thẻ: mục tự thêm có tên khách sạn / quán ăn thì gọi đúng là Lưu trú / Ăn uống. */
    function typeLabel(it) {
        if (it.type === "note" && it.source === "search") {   // lấy từ bản đồ (tìm kiếm / đề xuất mở rộng)
            var k0 = kindOf(it);
            return k0 === "lodging" ? "Lưu trú · từ bản đồ" : k0 === "meal" ? "Ăn uống · từ bản đồ" : "Từ bản đồ";
        }
        if (CUSTOM[it.type]) {
            var k = kindOf(it);
            if (k === "lodging") return "Lưu trú";
            if (k === "meal") return "Ăn uống";
        }
        return TYPE[it.type] || "";
    }
    function iconOf(it) {
        var k = kindOf(it);
        return ICON[k === "lodging" ? "lodging" : CUSTOM[it.type] && k === "meal" ? "meal" : it.type] || "fa-map-marker-alt";
    }
    var slotOfMin = S.slotOf;
    /**
     * Lịch một ngày: lõi xếp giờ ở planner-schedule.js (cùng hợp đồng với DaySchedule phía máy chủ),
     * ở đây chỉ gắn thêm số liệu của trang (chi phí, ước tính, số mục chưa có vị trí, có tối ưu được không).
     */
    function schedule(d, listOverride) {
        var list = listOverride || dayItems(d), r = S.day(list, { dow: dayDow(d), ctx: dayCtx(d) }), cost = 0, est = 0;
        list.forEach(function (x) { cost += x.cost || 0; est += estItem(x) || 0; });
        r.day = d;
        r.cost = cost;
        r.est = est;
        r.noPos = list.filter(function (x) { return !G.hasPos(x); }).length;
        r.canOpt = list.filter(isFree).length >= 3;
        return r;
    }
    /** Mục "tự do" (FLEXIBLE): có vị trí, chưa ghim giờ (FIXED), chưa khóa vị trí (LOCKED), chưa chọn buổi. Chỉ mục tự do bị tối ưu / cân bằng đổi chỗ. */
    function isFree(x) { return G.hasPos(x) && x.startMin == null && !x.locked && !x.slot; }
    function allSched() {
        var out = [];
        for (var d = 1; d <= daysCount(); d++) out.push(schedule(d));
        return out;
    }
    function loadLevel(s) { return S.level(s.load); }

    /**
     * Vấn đề của một ngày, kèm nút xử lý: quá dày, đi lại nhiều, kết thúc muộn, trùng giờ.
     * @return [{lvl:'warn'|'bad', text, acts:[[label, fn]]}]
     */
    function dayIssues(s) {
        var out = [], d = s.day;
        if (!s.count) return out;
        if (s.load >= 1) {
            out.push({ lvl: "warn", text: "Lịch quá dày: " + s.count + " hoạt động, dùng " + dur(s.occ) + " trên " + dur(DAY_AVAIL) + " (" + Math.round(s.load * 100) + "%).",
                acts: [["Tự cân bằng", function () { balanceDay(d); }], s.canOpt ? ["Tối ưu tuyến", function () { optimizeDay(d); }] : null].filter(Boolean) });
        } else if (s.travel > DAY_TRAVEL_WARN_MIN) {
            out.push({ lvl: "warn", text: "Đi lại khoảng " + durText(s.travel) + " (" + s.km + " km), nên gom các điểm gần nhau.",
                acts: s.canOpt ? [["Tối ưu tuyến", function () { optimizeDay(d); }]] : [] });
        }
        if (s.count > DAY_ITEMS_WARN && s.load < 1) out.push({ lvl: "warn", text: s.count + " mục trong một ngày, hơi nhiều.", acts: [] });
        if (s.end > LATE_END) out.push({ lvl: "warn", text: "Kết thúc khoảng " + hhmm(s.end) + ", khá muộn.", acts: [] });
        // giờ mở cửa (cùng quy tắc OpeningHours phía máy chủ)
        s.rows.forEach(function (r) {
            if (!r.hours) return;
            var it = r.it, open = S.hoursText(it.hours, s.dow), acts = [];
            if (!RO) {
                var od = openDayFor(it, d);
                if (r.hours !== "closed" && r.fixed) {
                    var fit = S.hoursFit(it.hours, s.dow, r.start, r.dur);
                    if (fit != null) acts.push(["Dời sang " + hhmm(fit), function () { it.startMin = fit; commit(); }]);
                }
                if (r.fixed) acts.push(["Bỏ giờ cố định", function () { it.startMin = null; commit(); }]);
                if (od) acts.push(["Chuyển sang ngày " + od, function () { moveItem(it, od, null); commit(); say("Đã chuyển “" + it.name + "” sang ngày " + od + ", hôm đó mở cửa."); }]);
            }
            out.push({ lvl: "bad", acts: acts,   // giờ mở cửa là ràng buộc cứng (TripHealth::HARD)
                text: r.hours === "closed" ? "“" + it.name + "” đóng cửa hôm đó."
                    : r.hours === "early" ? "Tới “" + it.name + "” lúc " + hhmm(r.start) + " nhưng giờ mở cửa " + open + "."
                        : "“" + it.name + "” (" + hhmm(r.start) + "–" + hhmm(r.end) + ") quá giờ mở cửa " + open + "." });
        });
        s.conflicts.forEach(function (c) {
            if (c.type === "arrival" || c.type === "departure") {
                var it0 = c.b, other = RO ? null : midDay(d), acts0 = [];
                if (!RO) {
                    if (c.type === "arrival" && it0.startMin != null) acts0.push(["Bỏ giờ cố định", function () { it0.startMin = null; commit(); }]);
                    if (other) acts0.push(["Chuyển sang ngày " + other, function () { it0.startMin = null; moveItem(it0, other, null); commit(); say("Đã chuyển “" + it0.name + "” sang ngày " + other + "."); }]);
                    acts0.push(["Đưa vào “Đã lưu”", function () { it0.startMin = null; moveItem(it0, null, null); commit(); }]);
                }
                out.push({ lvl: "bad", acts: acts0, text: c.type === "arrival"
                    ? "“" + it0.name + "” lúc " + hhmm(it0.startMin) + " nhưng khoảng " + hhmm(c.at) + " bạn mới tới nơi" + (c.text ? " (" + c.text + ")" : "") + "."
                    : "“" + it0.name + "” chưa xong thì đã tới lúc phải rời đi " + hhmm(c.at) + (c.text ? " (" + c.text + ")" : "") + "." });
                return;
            }
            var txt = c.type === "travel"
                ? "Không kịp di chuyển: từ “" + c.a.it.name + "” (xong " + hhmm(c.a.end) + ") cần ~" + c.need + " phút để tới “" + c.b.name + "” lúc " + hhmm(c.b.startMin) + "."
                : "Trùng giờ: “" + c.a.it.name + "” đến " + hhmm(c.a.end) + " nhưng “" + c.b.name + "” đặt cố định lúc " + hhmm(c.b.startMin) + ".";
            out.push({ lvl: "bad", text: txt,   // trùng giờ / không kịp đi: ràng buộc cứng
                acts: RO ? [] : [["Dời “" + c.b.name + "” sang " + hhmm(Math.ceil((c.a.end + (c.need || 0)) / 5) * 5), function () { c.b.startMin = Math.ceil((c.a.end + (c.need || 0)) / 5) * 5; commit(); }],
                    ["Bỏ giờ cố định", function () { c.b.startMin = null; commit(); }]] });
        });
        return out;
    }
    /** Ngày giữa chuyến (không phải ngày tới nơi / ngày về) nhẹ nhất, khác ngày d; null nếu không có. */
    function midDay(d) {
        var best = null, bl = Infinity;
        allSched().forEach(function (s) {
            var cx = s.ctx || {};
            if (s.day !== d && cx.arrive == null && cx.leave == null && s.load < 0.85 && s.load < bl) { bl = s.load; best = s.day; }
        });
        return best;
    }
    function dayLabelShort(d) { return "Ngày " + d; }
    /** Ngày nhẹ nhất (khác ngày d) còn chỗ; null nếu không có. */
    function lightestDay(d) {
        var best = null, bl = Infinity;
        allSched().forEach(function (s) { if (s.day !== d && s.load < 0.75 && s.load < bl) { bl = s.load; best = s.day; } });
        return best;
    }
    function moveLast(from, to) {
        var list = dayItems(from);
        if (!list.length) return;
        var it = list[list.length - 1];
        moveItem(it, to, null);
        commit();
        say("Đã chuyển “" + it.name + "” sang ngày " + to + ".");
    }
    /** "Tự chọn ngày hợp": ngày có điểm gần nhất với mục mới (nếu có vị trí) và chưa quá dày, không thì ngày nhẹ nhất. */
    function autoDay(o) {
        var ss = allSched(), best = null, bestScore = Infinity;
        var hasP = o.lat != null && o.lng != null;
        ss.forEach(function (s) {
            var near = Infinity;
            if (hasP) dayItems(s.day).filter(G.hasPos).forEach(function (x) { near = Math.min(near, G.hav(x, o)); });
            var score = (hasP && near < Infinity ? near : 50) + (s.load >= 1 ? 1000 : s.load * 10);
            if (score < bestScore) { bestScore = score; best = s.day; }
        });
        return best || 1;
    }
    /** Sức khỏe chuyến đi: các kiểm tra tổng thể (gom từ từng ngày + toàn chuyến). */
    function health() {
        var bad = [], ok = [], ss = allSched(), m = meta();
        var heavy = 0, conflict = 0;
        ss.forEach(function (s) {
            dayIssues(s).forEach(function (x) { bad.push({ lvl: x.lvl, text: "Ngày " + s.day + ": " + x.text, day: s.day }); });
            if (s.load >= 1) heavy++;
            conflict += s.conflicts.length;
        });
        if (!heavy) ok.push("Không có ngày nào quá dày");
        if (!conflict) ok.push("Không trùng giờ");
        var un = dayItems(null).length;
        if (un) bad.push({ lvl: "info", text: "Còn " + un + " mục trong “Đã lưu”, chưa xếp vào ngày nào.", day: "x" });
        var noPos = items.filter(function (x) { return x.day != null && !G.hasPos(x); }).length;
        if (noPos) bad.push({ lvl: "info", text: noPos + " mục chưa có vị trí nên chưa tính quãng đường." });
        var es = estimates(), all = totalCost() + es.total;
        if (m.budget != null && m.budget > 0) {
            if (all > m.budget) bad.push({ lvl: "warn", text: "Chi phí dự kiến " + money(all) + " vượt ngân sách " + money(m.budget) + ".", tab: "cost" });
            else ok.push("Trong ngân sách (dự kiến dùng " + Math.round(all / m.budget * 100) + "%)");
        }
        (cfg.contentWarnings || []).forEach(function (w) { bad.push({ lvl: "warn", text: w }); });
        return { bad: bad, ok: ok };
    }

    /* ---------------- vẽ: Lịch trình ---------------- */
    function slotSelect(it) {
        var s = el("select", "no-nice");
        s.setAttribute("aria-label", "Buổi của " + it.name);
        var o0 = el("option", null, "Buổi?");
        o0.value = "";
        s.appendChild(o0);
        Object.keys(SLOT).forEach(function (k) {
            var o = el("option", null, SLOT[k]);
            o.value = k;
            if (it.slot === k) o.selected = true;
            s.appendChild(o);
        });
        s.addEventListener("change", function () { it.slot = s.value || null; commit(true); });
        return s;
    }
    function daySelect(it) {
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Ngày của " + it.name);
        var o0 = el("option", null, it.day == null ? "Thêm vào ngày…" : "Đã lưu (bỏ khỏi ngày)");
        o0.value = "";
        sel.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d + (dayDate(d) ? " · " + dayDate(d) : ""));
            o.value = String(d);
            if (it.day === d) o.selected = true;
            sel.appendChild(o);
        }
        sel.addEventListener("change", function () {
            moveItem(it, sel.value === "" ? null : +sel.value, null);
            commit();
            checkPlaced(it);
        });
        return sel;
    }
    /** Đặt (hoặc bỏ nếu pos = null) vị trí cho một mục tự thêm. */
    function setPos(it, pos) {
        if (pos) { it.lat = pos.lat; it.lng = pos.lng; it.source = pos.source || "map"; it.url = G.mapsUrl(pos.lat, pos.lng); }
        else { it.lat = null; it.lng = null; it.source = null; it.url = null; }
        commit();
    }
    function posRow(it) {
        var row = el("div", "tp-pos");
        var has = G.hasPos(it);
        row.appendChild(el("span", "tp-pos__txt", has
            ? "Vị trí: " + it.lat + ", " + it.lng + " (" + (SRC[it.source] || "đã đặt") + ")"
            : "Chưa có vị trí nên chưa hiện trên bản đồ và chưa tính quãng đường."));
        var inp = el("input");
        inp.type = "text";
        inp.placeholder = "Dán link Google Maps hoặc tọa độ";
        inp.setAttribute("aria-label", "Đặt vị trí cho " + it.name);
        inp.addEventListener("change", function () {
            var v = inp.value.trim();
            if (!v) return;
            var p = G.parseMapsInput(v);
            if (!p) { say(G.isShortMapsLink(v) ? SHORT_MSG : BAD_POS_MSG, true); return; }
            p.source = "link";
            setPos(it, p);
            say("Đã đặt vị trí cho “" + it.name + "”. Chưa lưu.");
        });
        row.appendChild(inp);
        row.appendChild(btn("tp-mini", "Chọn trên bản đồ", "Chọn vị trí trên bản đồ cho " + it.name, function () { startPick(it.uid); }));
        if (has) row.appendChild(btn("tp-mini tp-mini--danger", "Bỏ vị trí", "Bỏ vị trí của " + it.name, function () { setPos(it, null); }));
        return row;
    }
    /** Ô giờ cố định (trống = tự xếp). */
    function timeInput(it, row) {
        var wrap = el("label", "tpx-f");
        wrap.appendChild(el("span", null, "Giờ bắt đầu"));
        var t = el("input");
        t.type = "time";
        t.step = "300";
        t.value = it.startMin != null ? hhmm(it.startMin) : "";
        if (it.startMin == null && row && row.start != null) t.placeholder = hhmm(row.start);
        t.setAttribute("aria-label", "Giờ bắt đầu cố định của " + it.name + " (để trống để tự xếp)");
        t.addEventListener("change", function () { it.startMin = t.value ? parseHHMM(t.value) : null; commit(); });
        wrap.appendChild(t);
        if (it.startMin != null) wrap.appendChild(btn("tp-link tp-small", "Tự xếp", "Bỏ giờ cố định của " + it.name, function () { it.startMin = null; commit(); }));
        return wrap;
    }
    function durInput(it) {
        var wrap = el("label", "tpx-f");
        wrap.appendChild(el("span", null, "Ở lại"));
        var s = el("select", "no-nice");
        s.setAttribute("aria-label", "Thời lượng ở " + it.name);
        var o0 = el("option", null, "Mặc định (" + durText(it.defDur || itemDur({ type: it.type, name: it.name, sub: it.sub })) + ")");
        o0.value = "";
        s.appendChild(o0);
        var list = DURS.slice();
        if (it.durationMin && list.indexOf(it.durationMin) < 0) list.push(it.durationMin);
        list.sort(function (a, b) { return a - b; }).forEach(function (m) {
            var o = el("option", null, durText(m));
            o.value = String(m);
            if (it.durationMin === m) o.selected = true;
            s.appendChild(o);
        });
        s.addEventListener("change", function () { it.durationMin = s.value ? +s.value : null; commit(); });
        wrap.appendChild(s);
        return wrap;
    }
    function field(label, node) {
        var w = el("label", "tpx-f");
        w.appendChild(el("span", null, label));
        w.appendChild(node);
        return w;
    }
    /** Nhãn chi phí trên thẻ: giá đã nhập, giá ước tính, hoặc không hiện gì. */
    function costBadge(it) {
        if (it.cost != null) {
            var est = it.costType === "estimate", act = it.costType === "actual";
            var k = el("span", "tpx-cost " + (est ? "is-est" : act ? "is-actual" : "is-known"), (est ? "≈ " : "") + (est ? shortMoney(it.cost) : money(it.cost)));
            k.appendChild(el("small", "tpx-cost__t", est ? "ước tính" : act ? "đã chi" : "dự kiến"));
            k.title = est ? "Giá điền từ bảng giá tham khảo" : act ? "Số tiền đã chi thật" : "Giá bạn đã nhập (dự kiến)";
            return k;
        }
        var e = estItem(it);
        if (e) {
            var b = el("span", "tpx-cost is-est", "≈ " + shortMoney(e));
            b.appendChild(el("small", "tpx-cost__t", "ước tính"));
            b.title = "Ước tính theo giá tham khảo, chưa phải giá thật";
            return b;
        }
        return null;
    }
    /**
     * Số liệu đi lại THẬT (OSRM) chỉ khi máy chủ xác nhận đã dùng OSRM cho bản đã lưu và trình duyệt không còn thay đổi chưa lưu;
     * mọi trường hợp khác là ƯỚC TÍNH (đường chim bay × hệ số) và được ghi rõ như vậy.
     */
    function realRoute() { return !!(ui.srv && !dirty && ui.srv.route && ui.srv.route.actual === "osrm"); }
    function srvRow(day, n) {
        var d = ui.srv && (ui.srv.days || []).filter(function (x) { return x.day === day; })[0];
        return d && d.rows ? d.rows[n - 1] : null;
    }
    /* Trạng thái số liệu của một chặng do máy chủ xác minh (RouteService): mỗi chặng một huy hiệu, không chặng nào bị coi là "0 phút" vì thiếu dữ liệu. */
    var LEG_ST = {
        verified: { cls: " is-real", src: "đường thực tế", tip: "Quãng đường và thời gian theo bản đồ đường bộ (OSRM)" },
        estimated: { cls: " is-est", src: "ước tính", tip: null },
        unreachable: { cls: " is-blocked", src: "không có đường", icon: "fa-ban", tip: "Bản đồ đường bộ không tìm thấy đường đi giữa hai điểm này (qua sông, đảo, đường cấm...). Số phút chỉ là ước tính đường chim bay, hãy kiểm tra lại hoặc đổi phương tiện." },
        unknown: { cls: " is-unknown", src: "chưa tính được", icon: "fa-question-circle", tip: "Tọa độ của một trong hai điểm không hợp lệ nên chưa tính được đường đi. Hãy sửa vị trí của mục." },
    };
    /** Đoạn nối giữa hai hoạt động: hoạt động -> di chuyển -> hoạt động. */
    function legRow(leg, row) {
        var li = el("li", "tpx-leg");
        // số liệu máy chủ chỉ dùng khi bản đang xem trùng bản đã lưu (không còn thay đổi chưa lưu)
        var sr = ui.srv && !dirty && row ? srvRow(row.it.day, row.n) : null;
        var st = sr && LEG_ST[sr.routeStatus] ? sr.routeStatus : null;
        var real = st ? st === "verified" : realRoute() && sr;
        var useSrv = !!(sr && (st || real));
        var min = useSrv ? sr.travelMin : leg.min, km = useSrv && sr.travelKm != null ? sr.travelKm : leg.kmExact != null ? Math.round(leg.kmExact * 10) / 10 : leg.km;
        var meta = st ? LEG_ST[st] : real ? LEG_ST.verified : LEG_ST.estimated;
        var bad = st === "unreachable" || st === "unknown";
        var walk = km <= 1 && !bad;
        li.appendChild(el("span", "tpx-leg__line"));
        var chip = el("span", "tpx-leg__chip" + meta.cls);
        var LMx = RULES.legModes || {}, lm = row && row.it.legMode && LMx[row.it.legMode] ? row.it.legMode : null;
        chip.appendChild(icon(meta.icon || (lm ? LMx[lm].icon : walk ? "fa-walking" : "fa-car-side")));
        if (st === "unknown") chip.appendChild(el("b", null, "Chưa rõ đường đi"));
        else {
            chip.appendChild(el("b", null, (real ? "" : "≈ ") + durText(Math.max(1, min))));
            chip.appendChild(el("span", null, " · " + String(km).replace(".", ",") + " km"));
        }
        chip.appendChild(el("small", "tpx-leg__src", meta.src));
        li.appendChild(chip);
        // T-05…T-09: chọn phương tiện cho chặng này, so sánh các phương tiện, mở chỉ đường
        if (row && Object.keys(LMx).length && leg.from && leg.to) {
            var open = ui.legOpen === row.it.uid;
            if (lm) chip.appendChild(el("small", "tpx-leg__mode", LMx[lm].label));
            li.appendChild(btn("tp-link tp-small tpx-leg__pick", open ? "Đóng" : "Phương tiện", "So sánh và chọn phương tiện cho chặng " + (leg.from.name || "") + " → " + (leg.to.name || ""), function () {
                ui.legOpen = open ? null : row.it.uid; renderDays();
            }));
            if (open) li.appendChild(legModes(leg, row));
        }
        if (bad) li.classList.add("is-bad");
        li.title = meta.tip || ("Ước tính: đường chim bay × " + RULES.route.factor + ", tốc độ " + RULES.route.speedKmh + " km/h");
        chip.setAttribute("aria-label", "Di chuyển: " + chip.textContent + ". " + li.title);
        li._leg = leg;
        li._row = row;
        return li;
    }
    /** Dấu chân carbon (gam CO2) của một chặng theo phương tiện: theo xe (ô tô 4 người/xe, xe máy 2 người/xe) hoặc theo người (xe buýt). */
    function legCo2(km, mode, g) {
        var m = (RULES.legModes || {})[mode];
        if (!m || !m.co2Km) return 0;
        var units = m.co2Per === "person" ? g : Math.ceil(g / (mode === "motorbike" ? 2 : 4));
        return Math.round(m.co2Km * km * units);
    }
    function co2Text(gram) { return gram >= 1000 ? (Math.round(gram / 100) / 10).toString().replace(".", ",") + " kg" : gram + " g"; }
    /** Tổng CO2 đi lại của cả chuyến với phương tiện đang chọn cho từng chặng (mặc định: phương tiện của chuyến). */
    function tripCo2() {
        var g = Math.max(1, +($("tpGroup") && $("tpGroup").value) || 1), def = (RULES.route && RULES.route.mode) || "driving", sum = 0;
        allSched().forEach(function (s) { (s.legs || []).forEach(function (l) { sum += legCo2(l.kmExact != null ? l.kmExact : l.km, (l.from && l.from.legMode) || def, g); }); });
        return sum;
    }
    /** Bảng so sánh phương tiện cho một chặng (ước tính theo km của chặng × tốc độ / giá tham khảo trong config planner.route.leg_modes). */
    function legModes(leg, row) {
        var LMx = RULES.legModes || {}, def = (RULES.route && RULES.route.mode) || "driving", km = leg.kmExact != null ? leg.kmExact : leg.km;
        var g = Math.max(1, +($("tpGroup") && $("tpGroup").value) || 1), cur = row.it.legMode || def;
        var box = el("div", "tpx-legmodes");
        var ul = el("ul", "tpx-legmodes__list");
        Object.keys(LMx).forEach(function (k) {
            var m = LMx[k], min = Math.round(km / Math.max(0.1, m.speedKmh) * 60) + (m.waitMin || 0);
            var vehicles = k === "driving" ? Math.ceil(g / 4) : k === "motorbike" ? Math.ceil(g / 2) : 0;
            var cost = Math.round(m.costKm * km * Math.max(1, vehicles)) + m.costFixed * g;
            var co2 = legCo2(km, k, g);
            var li = el("li", k === cur ? "is-on" : null);
            var b = btn("tpx-legmodes__b", null, "Dùng " + m.label + " cho chặng này", function () {
                row.it.legMode = k === def ? null : k; ui.legOpen = null; commit();
                say("Chặng tới “" + (leg.to.name || "") + "” đi bằng " + m.label.toLowerCase() + " (ước tính " + durText(Math.max(1, min)) + ").", false, [["Hoàn tác", undoOnce]]);
            });
            b.appendChild(icon(m.icon || "fa-car-side"));
            b.appendChild(el("b", null, m.label));
            b.appendChild(el("span", null, "≈ " + durText(Math.max(1, min))));
            b.appendChild(el("small", null, (cost ? "≈ " + shortMoney(cost) : "miễn phí") + " · " + (co2 ? "≈ " + co2Text(co2) + " CO₂" : "0 CO₂")));
            if (RO) b.disabled = true;
            li.appendChild(b);
            var a = el("a", "tp-link tp-small", "Chỉ đường");
            a.href = "https://www.google.com/maps/dir/?api=1&origin=" + leg.from.lat + "," + leg.from.lng + "&destination=" + leg.to.lat + "," + leg.to.lng + "&travelmode=" + (m.gmaps || "driving");
            a.target = "_blank"; a.rel = "noopener";
            li.appendChild(a);
            ul.appendChild(li);
        });
        box.appendChild(ul);
        box.appendChild(el("p", "tp-muted tp-small", "Ước tính cho " + String(Math.round(km * 10) / 10).replace(".", ",") + " km và " + g + " người; giá là giá tham khảo (taxi theo số xe 4 chỗ, xe máy 2 người/xe, xe buýt theo vé/người). Giờ trong lịch tính theo phương tiện bạn chọn."));
        return box;
    }
    /** Số liệu máy chủ về SAU khi timeline đã vẽ: chỉ thay các đoạn di chuyển tại chỗ (không vẽ lại cả lịch, không làm mất vị trí cuộn). */
    function refreshLegs() {
        var box = $("tpDaysBox");
        if (!box) return;
        Array.prototype.forEach.call(box.querySelectorAll("li.tpx-leg"), function (li) {
            if (li._leg && li.parentNode) li.parentNode.replaceChild(legRow(li._leg, li._row), li);
        });
    }
    /** Một điểm dừng trên timeline. row = kết quả schedule() (null với danh sách chờ). */
    function itemRow(it, row) {
        var color = dayColor(it.day);
        var li = el("li", "tpx-stop" + (CUSTOM[it.type] ? " is-custom" : "") + (row && (row.conflict || row.hours === "closed") ? " is-conflict" : "") + (ui.sel[it.uid] ? " is-sel" : "") + (it.visitedAt ? " is-visited" : "") + (it.skippedAt ? " is-skipped" : ""));
        li.setAttribute("data-uid", String(it.uid));
        li.style.setProperty("--c", color);

        // cột giờ
        var tm = el("div", "tpx-stop__time");
        if (row) {
            var b = el("b", null, hhmm(row.start));
            tm.appendChild(b);
            tm.appendChild(el("small", null, hhmm(row.end)));
            if (row.fixed) { var pin = icon("fa-thumbtack"); pin.title = "Giờ cố định"; tm.appendChild(pin); }
        }
        li.appendChild(tm);
        var hoursNow = row && it.day != null ? S.hoursText(it.hours, dayDow(it.day)) : null;

        // chấm số trên đường ray
        var nb = numberOf(it);
        var dot = btn("tpx-dot" + (G.hasPos(it) ? "" : " is-nopos"), nb === "" ? "" : String(nb), (G.hasPos(it) ? "Xem trên bản đồ: " : "Chưa có vị trí: ") + it.name, function () { showOnMap(it.uid); });
        if (nb === "") dot.appendChild(icon(iconOf(it)));
        li.appendChild(dot);

        var card = el("div", "tpx-stop__card");
        var top = el("div", "tpx-stop__top");
        if (!RO) {
            var h = el("span", "tp-handle tpx-handle");
            h.appendChild(icon("fa-grip-vertical"));
            h.setAttribute("title", "Kéo để đổi thứ tự hoặc chuyển ngày");
            h.setAttribute("aria-hidden", "true");
            h.draggable = true;
            h.addEventListener("dragstart", function (e) {
                ui.drag = it.uid;
                li.classList.add("is-drag");
                e.dataTransfer.effectAllowed = "move";
                try { e.dataTransfer.setData("text/plain", String(it.uid)); e.dataTransfer.setDragImage(li, 16, 16); } catch (x) { /* một số trình duyệt không hỗ trợ */ }
            });
            h.addEventListener("dragend", clearDrag);
            top.appendChild(h);
        }
        if (it.image) {
            var ph = el("img", "tpx-thumb");
            ph.src = it.image; ph.alt = ""; ph.loading = "lazy";
            top.appendChild(ph);
        }
        var main = el("div", "tpx-stop__main");
        var kind = el("span", "tpx-kind");
        kind.appendChild(icon(iconOf(it)));
        kind.appendChild(el("span", null, typeLabel(it)));
        if (it.locked) { var lk = icon("fa-lock tpx-lock"); lk.title = "Đã khóa vị trí: tối ưu tuyến và tự cân bằng không đổi mục này"; kind.appendChild(lk); }
        if (row) {
            var est = !it.durationMin;   // chưa nhập thời lượng: dùng mặc định theo loại -> ghi rõ là ước tính
            var du = el("span", "tpx-kind__dur" + (est ? " is-est" : ""), " · " + (est ? "≈ " : "") + durText(row.dur));
            du.title = est ? "Thời lượng ước tính (mặc định theo loại). Bấm “Sửa” để đặt thời lượng của bạn." : "Thời lượng bạn đã đặt";
            kind.appendChild(du);
        }
        main.appendChild(kind);
        var href = safeUrl(it.url);
        if (href && !CUSTOM[it.type]) {
            var a = el("a", "tpx-name", it.name);
            a.href = href; a.target = "_blank"; a.rel = "noopener";
            main.appendChild(a);
        } else {
            main.appendChild(el("span", "tpx-name", it.name));
        }
        var sub = [it.sub, it.when ? "diễn ra " + it.when : ""].filter(Boolean).join(" · ");
        if (sub) main.appendChild(el("small", "tpx-sub", sub));
        if (CUSTOM[it.type] && !G.hasPos(it)) main.appendChild(el("span", "tp-badge", "Chưa có vị trí"));
        if (hoursNow) {
            var hb = el("span", "tpx-hours" + (row.hours ? " is-" + row.hours : ""));
            hb.appendChild(icon(row.hours === "closed" ? "fa-door-closed" : "fa-clock"));
            hb.appendChild(el("span", null, row.hours === "closed" ? "Đóng cửa hôm nay" : row.hours === "early" ? "Chưa mở cửa · mở " + hoursNow
                : row.hours === "late" ? "Quá giờ đóng cửa · " + hoursNow : "Mở " + hoursNow));
            main.appendChild(hb);
        }
        if (it.outdoor === true && it.day != null && cfg.rainSuggestUrl && !RO) {
            var rc = btn("tpx-rainchip", null, "Phương án khi mưa cho " + it.name, function () {
                var h2 = card.querySelector(".tpx-rainbox");
                if (h2) { h2.remove(); return; }
                h2 = el("div", "tpx-rainbox");
                card.appendChild(h2);
                rainInline(it, h2);
            });
            rc.appendChild(icon("fa-umbrella"));
            rc.appendChild(el("span", null, "Ngoài trời · phương án mưa"));
            main.appendChild(rc);
        }
        top.appendChild(main);

        var right = el("div", "tpx-stop__right");
        var cb0 = costBadge(it);
        if (cb0) right.appendChild(cb0);
        if (!RO) {
            var cb = el("input", "tpx-sel");
            cb.type = "checkbox";
            cb.checked = !!ui.sel[it.uid];
            cb.setAttribute("aria-label", "Chọn " + it.name);
            cb.addEventListener("change", function () {
                if (cb.checked) ui.sel[it.uid] = true; else delete ui.sel[it.uid];
                li.classList.toggle("is-sel", cb.checked);
                renderBulk();
            });
            right.appendChild(cb);
            if (it.day == null) { var qs = daySelect(it); qs.classList.add("tpx-quickday"); right.appendChild(qs); }
            var open = !!ui.open[it.uid];
            var tg = btn("tp-mini tpx-edit", open ? "Xong" : "Sửa", "Sửa chi tiết " + it.name, function () {
                ui.open[it.uid] = !ui.open[it.uid];
                renderDays();
            });
            tg.setAttribute("aria-expanded", open ? "true" : "false");
            right.appendChild(tg);
        }
        top.appendChild(right);
        card.appendChild(top);

        if (row && row.conflict) card.appendChild(el("p", "tpx-conflict", row.conflict.type === "arrival" ? "Trước giờ tới nơi (khoảng " + hhmm(row.conflict.at) + ")."
            : row.conflict.type === "departure" ? "Quá giờ phải rời đi (" + hhmm(row.conflict.at) + ")."
                : row.conflict.a ? "Trùng giờ với “" + row.conflict.a.it.name + "” (đến " + hhmm(row.conflict.a.end) + ")." : "Xung đột giờ."));
        var det = el("div", "tpx-stop__more");
        if (it.reasons && it.reasons.length) det.appendChild(el("p", "tp-why", "Vì sao chọn: " + it.reasons.join("; ")));
        if (it.fit && it.fit.length) {
            var fl = el("ul", "tp-fit");
            it.fit.forEach(function (f) { fl.appendChild(el("li", "tp-fit__i tp-fit--" + (f.k === "good" || f.k === "warn" ? f.k : "info"), f.x)); });
            det.appendChild(fl);
        }
        if (it.src) det.appendChild(el("small", "tp-src", it.src));
        else if (CUSTOM[it.type] && it.source === "search") det.appendChild(el("small", "tp-src", "Ngoài dữ liệu Travela, chỉ chi phí tham khảo chung"));
        if (it.note && !ui.open[it.uid]) {
            var np = el("p", "tpx-note");
            np.appendChild(icon("fa-sticky-note"));
            np.appendChild(el("span", null, it.note));
            det.appendChild(np);
        }
        if (det.children.length) card.appendChild(det);

        if (ui.open[it.uid] && !RO) {
            var panel = el("div", "tpx-panel-edit");
            var r1 = el("div", "tpx-fields");
            r1.appendChild(field("Ngày", daySelect(it)));
            if (it.day != null) {
                r1.appendChild(timeInput(it, row));
                r1.appendChild(durInput(it));
                r1.appendChild(field("Buổi", slotSelect(it)));
            }
            r1.appendChild(field("Chi phí (đ)", costInput(it)));
            var lkl = el("label", "tp-check tp-small tpx-lockbox");
            var lkc = el("input");
            lkc.type = "checkbox";
            lkc.checked = !!it.locked;
            lkc.addEventListener("change", function () { it.locked = lkc.checked; commit(); });
            lkl.appendChild(lkc);
            lkl.appendChild(document.createTextNode(" Khóa vị trí (tối ưu tuyến và tự cân bằng không đổi mục này)"));
            r1.appendChild(lkl);
            r1.appendChild(visitedBox(it));
            panel.appendChild(r1);
            var note = el("input");
            note.type = "text";
            note.maxLength = 255;
            note.placeholder = "Ghi chú (giờ hẹn, mã đặt chỗ...)";
            note.value = it.note;
            note.setAttribute("aria-label", "Ghi chú cho " + it.name);
            note.addEventListener("input", function () { it.note = note.value; });
            note.addEventListener("change", function () { commit(true); });
            panel.appendChild(field("Ghi chú", note));
            if (CUSTOM[it.type]) panel.appendChild(posRow(it));
            var ctl = el("div", "tpx-panel-edit__act");
            ctl.appendChild(ibtn("tp-mini", "fa-arrow-up", "Lên", "Lên: " + it.name, function () { shift(it, -1); }));
            ctl.appendChild(ibtn("tp-mini", "fa-arrow-down", "Xuống", "Xuống: " + it.name, function () { shift(it, 1); }));
            ctl.appendChild(ibtn("tp-mini tp-mini--danger", "fa-trash-alt", "Bỏ khỏi chuyến", "Bỏ " + it.name + " khỏi chuyến đi", function () {
                items.splice(items.indexOf(it), 1);
                delete ui.sel[it.uid];
                delete ui.open[it.uid];
                commit();
                say("Đã bỏ “" + it.name + "”. Bấm Hoàn tác (Ctrl+Z) nếu nhầm.");
            }));
            panel.appendChild(ctl);
            card.appendChild(panel);
        }
        li.appendChild(card);

        // liên kết với bản đồ: rê chuột -> sáng ghim; bấm vào thẻ (ngoài nút/ô nhập) -> mở thẻ chi tiết trên bản đồ
        li.addEventListener("mouseenter", function () { if (map) map.highlight(it.uid); });
        li.addEventListener("mouseleave", function () { if (map && !(ui.card && ui.card.uid === it.uid)) map.highlight(ui.card ? ui.card.uid : null); });
        card.addEventListener("click", function (e) {
            if (e.target.closest("a,button,input,select,textarea,label")) return;
            if (G.hasPos(it)) showOnMap(it.uid);
        });

        // thả lên một mục: chèn trước/sau tùy nửa trên/dưới
        li.addEventListener("dragover", function (e) {
            if (ui.drag == null || ui.drag === it.uid) return;
            e.preventDefault();
            var r = li.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2;
            li.classList.toggle("drop-before", !after);
            li.classList.toggle("drop-after", after);
        });
        li.addEventListener("dragleave", function () { li.classList.remove("drop-before", "drop-after"); });
        li.addEventListener("drop", function (e) {
            if (ui.drag == null) return;
            e.preventDefault();
            e.stopPropagation();
            var r = li.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2;
            var dragged = byUid(ui.drag);
            clearDrag();
            if (dragged) dropRelative(dragged, it, after);
        });
        return li;
    }
    function costInput(it) {
        if (RO) return el("span", "tp-cost-ro", it.cost == null ? "" : money(it.cost));
        var cost = el("input");
        cost.type = "number";
        cost.min = "0";
        cost.step = "1000";
        var est = estItem(it);
        cost.placeholder = est ? "≈ " + Math.round(est).toLocaleString("vi-VN") + " (ước tính)" : "Chi phí (đ)";
        cost.value = it.cost == null ? "" : String(it.cost);
        cost.setAttribute("aria-label", "Chi phí của " + it.name);
        cost.addEventListener("input", function () {
            it.cost = cost.value === "" ? null : Math.max(0, +cost.value);
            if (it.cost == null || it.costType === "estimate") it.costType = null;   // tự gõ giá: không còn là ước tính
            softUpdate();
        });
        cost.addEventListener("change", function () { commit(true); });
        return cost;
    }
    function byUid(u) {
        for (var i = 0; i < items.length; i++) if (items[i].uid === u) return items[i];
        return null;
    }
    function clearDrag() {
        ui.drag = null;
        document.querySelectorAll(".is-drag,.drop-before,.drop-after,.drop-zone").forEach(function (e) { e.classList.remove("is-drag", "drop-before", "drop-after", "drop-zone"); });
    }
    /** Đưa mục sang ngày `day`, đứng trước mục `before` (null = cuối ngày). Thứ tự trong mảng = thứ tự trong ngày. */
    function moveItem(it, day, before) {
        if (it === before) return;
        items.splice(items.indexOf(it), 1);
        it.day = day;
        if (before) items.splice(items.indexOf(before), 0, it); else items.push(it);
    }
    function dropRelative(it, target, after) {
        if (it === target) return;
        var grp = items.filter(function (x) { return x.day === target.day && x !== it; });
        var i = grp.indexOf(target);
        var from = it.day;
        moveItem(it, target.day, after ? (grp[i + 1] || null) : target);
        commit();
        if (from !== it.day) checkPlaced(it);
    }
    function shift(it, dir) {
        var group = items.filter(function (x) { return x.day === it.day; });
        var i = group.indexOf(it), j = i + dir;
        if (j < 0 || j >= group.length) return;
        var a = items.indexOf(it), b = items.indexOf(group[j]);
        items[a] = group[j];
        items[b] = it;
        commit();
    }


    /* ---------------- Phương án khi mưa ---------------- */
    function rainKey(r) { return r.type + ":" + r.refId; }
    function rainPanel(d) {
        var det = el("details", "tp-rain");
        var list = rain[String(d)] || [];
        var sum = el("summary", null, "☂ Dự phòng khi mưa" + (list.length ? " (" + list.length + " nơi)" : ""));
        det.appendChild(sum);
        if (ui.rainOpen && ui.rainOpen[d]) det.open = true;
        det.addEventListener("toggle", function () { ui.rainOpen = ui.rainOpen || {}; ui.rainOpen[d] = det.open; });
        det.appendChild(el("p", "tp-muted tp-small", "Lịch không đổi. Đây là danh sách nơi trong nhà để bạn chuyển sang nếu trời mưa; bạn quyết định khi nào dùng. Không tính vào chi phí chính."));
        var ul = el("ul", "tp-rain__list");
        list.forEach(function (r) {
            var li = el("li", "tp-rain__item");
            var href = safeUrl(r.url);
            if (href) { var a = el("a", null, r.name); a.href = href; a.target = "_blank"; a.rel = "noopener"; li.appendChild(a); } else li.appendChild(el("span", null, r.name));
            if (r.sub) li.appendChild(el("small", "tp-muted", " · " + r.sub));
            li.appendChild(btn("tp-mini tp-mini--danger", "Bỏ", "Bỏ " + r.name + " khỏi phương án mưa ngày " + d, function () {
                rain[String(d)] = (rain[String(d)] || []).filter(function (x) { return rainKey(x) !== rainKey(r); });
                if (!rain[String(d)].length) delete rain[String(d)];
                commit();
            }));
            ul.appendChild(li);
        });
        det.appendChild(ul);
        var box = el("div", "tp-rain__sugg");
        var b = btn("tp-mini", "Gợi ý mục trong nhà", "Gợi ý mục trong nhà cho ngày " + d, function () { loadRain(d, box, b); });
        det.appendChild(b);
        // phương án mưa cả ngày theo kiểu Resolver: dự phòng hoặc đổi hẳn, xem trước ảnh hưởng rồi mới áp dụng
        if (cfg.resolveUrl && !RO && ui.srv && (ui.srv.checks || []).some(function (c) { return c.code === "context.rain" && c.day === d; })) {
            det.appendChild(btn("tp-mini tpx-rec__b", "Xem phương án mưa cho cả ngày", "Xem trước phương án mưa ngày " + d, function () {
                if (dirty) { say("Đợi lưu xong rồi xem phương án mưa.", true); return; }
                ui.healthOpen = true;
                loadResolve({ code: "context.rain", day: d, index: null });
                setTimeout(function () { var x = document.querySelector("#tpWarn .tpx-resolve"); if (x) x.scrollIntoView({ block: "center", behavior: "smooth" }); }, 500);
            }));
        }
        det.appendChild(box);
        return det;
    }
    function loadRain(d, box, b) {
        if (!cfg.rainSuggestUrl) return;
        if ((rain[String(d)] || []).length >= RAIN_MAX) { box.textContent = "Đã đủ " + RAIN_MAX + " mục cho ngày này."; return; }
        b.disabled = true;
        box.textContent = "Đang tìm…";
        var have = (rain[String(d)] || []).map(rainKey);
        var q = "?day=" + d + have.map(function (k) { return "&have[]=" + encodeURIComponent(k); }).join("");
        API.raw(cfg.rainSuggestUrl + q)
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (j) {
                box.textContent = "";
                if (!j.items || !j.items.length) { box.appendChild(el("p", "tp-muted tp-small", "Chưa có mục trong nhà đã duyệt cho nơi này. Bạn có thể thêm mục riêng ở ô tìm kiếm.")); return; }
                var ul = el("ul", "tp-rain__list");
                j.items.forEach(function (x) {
                    var li = el("li", "tp-rain__item");
                    li.appendChild(el("strong", null, x.name));
                    if (x.sub) li.appendChild(el("small", "tp-muted", " · " + x.sub));
                    li.appendChild(el("small", "tp-rain__why", (x.reasons || []).join("; ")));
                    li.appendChild(btn("tp-mini", "Chọn", "Chọn " + x.name + " làm phương án mưa ngày " + d, function () {
                        var cur = rain[String(d)] = rain[String(d)] || [];
                        if (cur.length >= RAIN_MAX || cur.some(function (r) { return rainKey(r) === x.type + ":" + x.refId; })) return;
                        cur.push({ type: x.type, refId: x.refId, name: x.name, sub: x.sub || "", url: x.url || null, lat: x.lat == null ? null : x.lat, lng: x.lng == null ? null : x.lng });
                        commit();
                    }));
                    ul.appendChild(li);
                });
                box.appendChild(ul);
            })
            .catch(function () { box.textContent = "Chưa tải được gợi ý, thử lại sau."; })
            .then(function () { b.disabled = false; });
    }
    /* ---------------- dải ngày + các ngày ---------------- */
    /** Thứ ISO (1 = Thứ Hai ... 7 = Chủ nhật) của ngày d, null khi chưa chọn ngày đi (khi đó không kiểm tra giờ mở cửa). */
    /** Đổi ngày đi: mục nhận phòng / chuyến bay / tàu đã đặt về đúng ngày thật của đặt chỗ (cùng BookingPlan::realignRows). */
    function realignAnchors() {
        var A = (cfg.logistics || {}).anchors || [], s = $("tpStart").value, n = daysCount(), moved = 0;
        if (!A.length || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return 0;
        var t0 = Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
        A.forEach(function (a) {
            var t = Date.UTC(+a.date.slice(0, 4), +a.date.slice(5, 7) - 1, +a.date.slice(8, 10)), d = Math.round((t - t0) / 864e5) + 1;
            var want = d >= 1 && d <= n ? d : null;
            items.forEach(function (it) {
                if (CUSTOM[it.type] && (it.name || "").toLowerCase() === a.label.toLowerCase() && it.day !== want) {
                    moveItem(it, want, null);
                    if (want === null) it.startMin = null;
                    moved++;
                }
            });
        });
        return moved;
    }
    /** Chỗ ở / giờ tới nơi / giờ phải rời đi của ngày d (theo ngày đi đang hiển thị, kể cả chưa lưu). */
    function dayCtx(d) { return S.dayCtx(cfg.logistics || null, $("tpStart").value, d, daysCount()); }
    function dayDow(d) {
        var s = $("tpStart").value;
        if (!s) return null;
        var dt = new Date(s + "T00:00:00");
        if (isNaN(dt.getTime())) return null;
        dt.setDate(dt.getDate() + d - 1);
        return dt.getDay() || 7;
    }
    /** Ngày (khác d) trong chuyến mà mục mở cửa và còn đủ giờ; null nếu không có. */
    function openDayFor(it, notDay) {
        for (var d = 1; d <= daysCount(); d++) {
            if (d === notDay) continue;
            var w = dayDow(d);
            if (w != null && S.hoursFit(it.hours, w, RULES.dayStart, itemDur(it)) != null) return d;
        }
        return null;
    }
    function dayDate(d) {
        var s = $("tpStart").value;
        if (!s) return "";
        var dt = new Date(s + "T00:00:00");
        if (isNaN(dt.getTime())) return "";
        dt.setDate(dt.getDate() + d - 1);
        return dt.toLocaleDateString("vi-VN", { weekday: "short", day: "2-digit", month: "2-digit" });
    }
    function loadBar(s, cls) {
        var lv = loadLevel(s), pct = Math.round(s.load * 100);
        var bar = el("span", "tpx-load tpx-load--" + lv + (cls ? " " + cls : ""));
        var f = el("span", "tpx-load__fill");
        f.style.width = Math.min(100, pct) + "%";
        bar.appendChild(f);
        bar.setAttribute("role", "img");
        bar.setAttribute("aria-label", "Độ dày của ngày: " + pct + "%");
        bar.title = durText(s.act) + " hoạt động + " + durText(s.travel) + " di chuyển (" + pct + "% quỹ thời gian trong ngày)";
        return bar;
    }
    function gotoDay(d) {
        switchTab("plan");
        var sec = document.getElementById(d === "x" ? "tpDayX" : "tpDay" + d);
        if (ui.collapsed[d]) { delete ui.collapsed[d]; renderDays(); sec = document.getElementById(d === "x" ? "tpDayX" : "tpDay" + d); }
        ui.lockDay = Date.now() + 900;
        if (sec) sec.scrollIntoView({ block: "start", behavior: "smooth" });
        setCurDay(d, true);
    }
    /** Dải ngày phía trên timeline: bấm để nhảy tới ngày; thanh màu cho biết ngày nào đã kín. */
    function renderDayNav() {
        var nav = $("tpDayNav");
        if (!nav) return;
        nav.textContent = "";
        var un = dayItems(null).length;
        if (un) {
            var lx = el("li");
            var bx = btn("tpx-dchip tpx-dchip--wait" + (ui.curDay === "x" ? " is-on" : ""), null, "Đã lưu, " + un + " mục chưa xếp ngày", function () { gotoDay("x"); });
            bx.setAttribute("data-day", "x");
            bx.appendChild(el("b", null, "Đã lưu"));
            bx.appendChild(el("small", null, un + " mục"));
            lx.appendChild(bx);
            nav.appendChild(lx);
        }
        allSched().forEach(function (s) {
            var li = el("li");
            var b = btn("tpx-dchip" + (ui.curDay === s.day ? " is-on" : ""), null, null, function () { gotoDay(s.day); });
            b.style.setProperty("--c", dayColor(s.day));
            b.setAttribute("data-day", String(s.day));
            b.setAttribute("aria-label", "Ngày " + s.day + (dayDate(s.day) ? ", " + dayDate(s.day) : "") + ", " + s.count + " mục, độ dày " + Math.round(s.load * 100) + "%");
            if (ui.curDay === s.day) b.setAttribute("aria-current", "true");
            var t = el("span", "tpx-dchip__t");
            t.appendChild(el("b", null, "Ngày " + s.day));
            if (dayIssues(s).some(function (x) { return x.lvl === "bad"; })) { var w = icon("fa-exclamation-circle tpx-dchip__warn"); t.appendChild(w); }
            b.appendChild(t);
            b.appendChild(el("small", null, (dayDate(s.day) || (s.count + " mục"))));
            b.appendChild(loadBar(s, "tpx-load--mini"));
            li.appendChild(b);
            nav.appendChild(li);
        });
    }
    function dropZone(dayNo, zone) {
        zone.addEventListener("dragover", function (e) {
            if (ui.drag == null) return;
            e.preventDefault();
            zone.classList.add("drop-zone");
        });
        zone.addEventListener("dragleave", function (e) { if (e.target === zone) zone.classList.remove("drop-zone"); });
        zone.addEventListener("drop", function (e) {
            if (ui.drag == null) return;
            e.preventDefault();
            var dragged = byUid(ui.drag);
            clearDrag();
            if (dragged) { var from = dragged.day; moveItem(dragged, dayNo, null); commit(); if (from !== dayNo) checkPlaced(dragged); }
        });
    }
    /* ---------------- Đã ghé (visitedAt) + danh sách phẳng ---------------- */
    function visitedBox(it) {
        var l = el("label", "tp-check tp-small tpx-visitbox");
        var c = el("input");
        c.type = "checkbox";
        c.checked = !!it.visitedAt;
        c.disabled = RO;
        c.addEventListener("change", function () { it.visitedAt = c.checked ? new Date().toISOString() : null; commit(); });
        l.appendChild(c);
        l.appendChild(document.createTextNode(" Đã ghé" + (it.visitedAt ? " (" + new Date(it.visitedAt).toLocaleString("vi-VN", { hour: "2-digit", minute: "2-digit", day: "2-digit", month: "2-digit" }) + ")" : "")));
        return l;
    }
    /** Chế độ "Danh sách": mọi mục của chuyến thành một danh sách phẳng theo ngày, kèm ô "đã ghé" (dùng khi đang đi). */
    function flatList() {
        var wrap = el("section", "tpx-flat");
        var done = items.filter(function (x) { return x.day != null && x.visitedAt; }).length, total = items.filter(function (x) { return x.day != null; }).length;
        wrap.appendChild(el("p", "tpx-flat__sum", "Đã ghé " + done + "/" + total + " điểm"));
        var ol = el("ol", "tpx-flat__list");
        allSched().forEach(function (s) {
            if (!s.rows.length) return;
            ol.appendChild(el("li", "tpx-flat__day", "Ngày " + s.day + (dayDate(s.day) ? " · " + dayDate(s.day) : "")));
            s.rows.forEach(function (r) {
                var li = el("li", "tpx-flat__it" + (r.it.visitedAt ? " is-visited" : ""));
                li.appendChild(visitedBox(r.it));
                var t = el("span", "tpx-flat__name");
                t.appendChild(el("b", null, r.it.name));
                t.appendChild(el("small", "tp-muted", hhmm(r.start) + "–" + hhmm(r.end) + (r.it.sub ? " · " + r.it.sub : "")));
                li.appendChild(t);
                if (r.it.lat != null && r.it.lng != null) {
                    var a = el("a", "tp-link tp-small", "Chỉ đường");
                    a.href = "https://www.google.com/maps/dir/?api=1&destination=" + r.it.lat + "," + r.it.lng; a.target = "_blank"; a.rel = "noopener";
                    li.appendChild(a);
                }
                ol.appendChild(li);
            });
        });
        wrap.appendChild(ol);
        return wrap;
    }
    /* ---------------- Chế độ đang đi: checklist hôm nay ----------------
     * Dựa trên ngày đi + giờ máy người dùng. "Xong" = visitedAt, "Bỏ qua" = skippedAt (hai trạng thái loại trừ nhau),
     * mục trễ giờ có nút "Để ngày mai". Mọi thay đổi đi qua commit() -> tự lưu, hoàn tác được, có kiểm tra rev như sửa thường. */
    function tripPhase() {
        var s = $("tpStart").value;
        if (!s) return { state: "nodate" };
        var st = new Date(s + "T00:00:00"), now = new Date(), t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var diff = Math.round((t0 - st) / 864e5), n = daysCount();
        if (diff < 0) return { state: "before", inDays: -diff };
        if (diff >= n) return { state: "after", ago: diff - n + 1 };
        return { state: "during", day: diff + 1, nowMin: now.getHours() * 60 + now.getMinutes() };
    }
    function setDone(it, on) { it.visitedAt = on ? new Date().toISOString() : null; if (on) it.skippedAt = null; commit(); }
    function setSkip(it, on) { it.skippedAt = on ? new Date().toISOString() : null; if (on) it.visitedAt = null; commit(); }
    function dirLink(it, cls) {
        var a = el("a", cls || "tgt__dir", "Chỉ đường");
        a.href = "https://www.google.com/maps/dir/?api=1&destination=" + it.lat + "," + it.lng; a.target = "_blank"; a.rel = "noopener";
        return a;
    }
    function renderToday() {
        var box = $("tpToday");
        if (!box) return;
        var ph = tripPhase();
        box.textContent = "";
        box.className = "tgt tgt--" + ph.state;
        if (ph.state === "nodate" || (ph.state === "before" && ph.inDays > 14) || (ph.state === "after" && (!box.dataset.doneUrl || ph.ago > 30))) { box.hidden = true; return; }
        box.hidden = false;
        var placed = items.filter(function (x) { return x.day != null; });
        if (ph.state === "before") {
            box.appendChild(el("p", "tgt__eyebrow", "Sắp đi"));
            box.appendChild(el("h3", "tgt__h", ph.inDays === 1 ? "Ngày mai khởi hành" : "Còn " + ph.inDays + " ngày nữa khởi hành"));
            var ul = el("ul", "tgt__todo");
            [["Kiểm tra đặt chỗ: chỗ ở, vé đi và về", "#tpBook"], ["Xem dự báo và cảnh báo của La bàn", "#la-ban"], ["Chuẩn bị đồ mang theo", "#tpPack"]].forEach(function (x) {
                var li = el("li"); var a = el("a", null, x[0]); a.href = x[1];
                a.addEventListener("click", function (e) {
                    if (x[1] === "#la-ban") { e.preventDefault(); var o = $("aiCoOpen") || $("aiCoOpen2"); if (o) o.click(); return; }
                    e.preventDefault(); switchTab("info"); setTimeout(function () { var t = document.querySelector(x[1]); if (t) t.scrollIntoView({ behavior: "smooth", block: "start" }); }, 60);
                });
                li.appendChild(a); ul.appendChild(li);
            });
            box.appendChild(ul);
            box.appendChild(el("p", "tgt__hint", "Khi tới ngày đi, phần này thành danh sách việc của từng ngày để bạn đánh dấu “Xong”."));
            return;
        }
        if (ph.state === "after") {
            var done = placed.filter(function (x) { return x.visitedAt; }).length;
            box.appendChild(el("p", "tgt__eyebrow", "Chuyến đã kết thúc"));
            box.appendChild(el("h3", "tgt__h", "Bạn đã ghé " + done + "/" + placed.length + " điểm"));
            box.appendChild(el("p", "tgt__hint", "Đánh dấu hoàn thành để chuyến chuyển sang mục “Đã đi” (ghi chú, chi tiêu nhóm và lịch sử vẫn giữ nguyên)."));
            var f = el("form"); f.method = "POST"; f.action = box.dataset.doneUrl;
            var c1 = el("input"); c1.type = "hidden"; c1.name = "_token"; c1.value = cfg.csrf; f.appendChild(c1);
            var c2 = el("input"); c2.type = "hidden"; c2.name = "status"; c2.value = "done"; f.appendChild(c2);
            var b = el("button", "tp-btn", "Đánh dấu chuyến đã hoàn thành"); b.type = "submit"; f.appendChild(b);
            box.appendChild(f);
            return;
        }
        var s = schedule(ph.day), rows = s.rows, now = ph.nowMin;
        var nDone = rows.filter(function (r) { return r.it.visitedAt; }).length, nSkip = rows.filter(function (r) { return r.it.skippedAt; }).length;
        var head = el("div", "tgt__head");
        var ht = el("div");
        ht.appendChild(el("p", "tgt__eyebrow", "Hôm nay · " + dayDate(ph.day)));
        ht.appendChild(el("h3", "tgt__h", "Ngày " + ph.day + "/" + daysCount() + (rows.length ? " · xong " + nDone + "/" + (rows.length - nSkip) : "")));
        head.appendChild(ht);
        var bar = el("span", "tgt__bar"); var fill = el("span"); fill.style.width = (rows.length - nSkip ? Math.round(nDone * 100 / (rows.length - nSkip)) : 0) + "%"; bar.appendChild(fill);
        bar.setAttribute("role", "progressbar"); bar.setAttribute("aria-valuemin", "0"); bar.setAttribute("aria-valuemax", String(rows.length - nSkip)); bar.setAttribute("aria-valuenow", String(nDone));
        bar.setAttribute("aria-label", "Đã xong " + nDone + " trên " + (rows.length - nSkip) + " việc hôm nay");
        head.appendChild(bar);
        box.appendChild(head);
        if (!rows.length) { box.appendChild(el("p", "tgt__hint", "Hôm nay chưa có điểm nào trong lịch. Thêm điểm hoặc hỏi La bàn gợi ý.")); return; }
        var next = rows.filter(function (r) { return !r.it.visitedAt && !r.it.skippedAt; })[0];
        if (next) {
            var nb = el("div", "tgt__next" + (next.end < now ? " is-late" : ""));
            var lab = next.start > now ? "Tiếp theo · " + hhmm(next.start) + " (còn " + fmtMin(next.start - now) + ")"
                : next.end >= now ? "Đang diễn ra · " + hhmm(next.start) + "–" + hhmm(next.end) : "Đã trễ " + fmtMin(now - next.end) + " so với lịch";
            nb.appendChild(el("small", null, lab));
            nb.appendChild(el("b", null, next.it.name));
            var na = el("div", "tgt__nacts");
            if (!RO) { var db = el("button", "tp-btn tgt__donebtn", "Xong"); db.type = "button"; db.addEventListener("click", function () { setDone(next.it, true); }); na.appendChild(db); }
            if (G.hasPos(next.it)) na.appendChild(dirLink(next.it, "tp-btn tp-btn--ghost"));
            nb.appendChild(na);
            box.appendChild(nb);
        } else {
            var nxt = ph.day < daysCount() ? schedule(ph.day + 1) : null;
            box.appendChild(el("p", "tgt__alldone", "Đã xong lịch hôm nay." + (nxt && nxt.rows.length ? " Ngày mai " + nxt.rows.length + " điểm, bắt đầu " + hhmm(nxt.rows[0].start) + "." : "")));
        }
        var ol = el("ol", "tgt__list");
        rows.forEach(function (r) {
            var it = r.it, late = !it.visitedAt && !it.skippedAt && r.end < now;
            var li = el("li", "tgt__it" + (it.visitedAt ? " is-done" : "") + (it.skippedAt ? " is-skip" : "") + (late ? " is-late" : ""));
            var lab = el("label", "tgt__chk");
            var c = el("input"); c.type = "checkbox"; c.checked = !!it.visitedAt; c.disabled = RO || !!it.skippedAt;
            c.setAttribute("aria-label", "Đã xong: " + it.name);
            c.addEventListener("change", function () { setDone(it, c.checked); });
            lab.appendChild(c);
            li.appendChild(lab);
            li.appendChild(el("span", "tgt__time", hhmm(r.start)));
            var m = el("span", "tgt__name"); m.appendChild(el("b", null, it.name));
            if (it.visitedAt) m.appendChild(el("small", null, "Xong lúc " + new Date(it.visitedAt).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })));
            else if (it.skippedAt) m.appendChild(el("small", null, "Đã bỏ qua"));
            else if (late) m.appendChild(el("small", "tgt__lateh", "Quá giờ dự kiến (" + hhmm(r.end) + ")"));
            li.appendChild(m);
            var acts = el("span", "tgt__acts");
            if (G.hasPos(it) && !it.visitedAt) acts.appendChild(dirLink(it));
            if (!RO && !it.visitedAt) {
                var sk = el("button", "tp-mini", it.skippedAt ? "Khôi phục" : "Bỏ qua"); sk.type = "button";
                sk.setAttribute("aria-label", (it.skippedAt ? "Khôi phục " : "Bỏ qua ") + it.name);
                sk.addEventListener("click", function () { setSkip(it, !it.skippedAt); });
                acts.appendChild(sk);
                if (late && ph.day < daysCount() && !it.locked) {
                    var mv = el("button", "tp-mini", "Để ngày mai"); mv.type = "button";
                    mv.setAttribute("aria-label", "Chuyển " + it.name + " sang ngày " + (ph.day + 1));
                    mv.addEventListener("click", function () { it.startMin = null; moveItem(it, ph.day + 1, null); commit(); checkPlaced(it); say("Đã chuyển " + it.name + " sang ngày " + (ph.day + 1) + ". Bấm Hoàn tác nếu nhầm."); });
                    acts.appendChild(mv);
                }
            }
            li.appendChild(acts);
            ol.appendChild(li);
        });
        box.appendChild(ol);
    }
    function fmtMin(m) { m = Math.max(0, Math.round(m)); return m >= 60 ? Math.floor(m / 60) + " giờ" + (m % 60 ? " " + (m % 60) + " phút" : "") : m + " phút"; }
    setInterval(function () { if ($("tpToday") && !$("tpToday").hidden && !document.hidden) renderToday(); }, 60000);

    /* ---------------- T-03: lấp khoảng trống ---------------- */
    function hasPos(it) { return it && it.lat != null && it.lng != null; }
    function toggleGap(key, d, a, b, r) {
        if (ui.gap && ui.gap.key === key) { ui.gap = null; renderDays(); return; }
        ui.gap = { key: key, loading: true, data: null };
        renderDays();
        var q = new URLSearchParams({ a: (+a.lat).toFixed(6) + "," + (+a.lng).toFixed(6), b: (+b.lat).toFixed(6) + "," + (+b.lng).toFixed(6),
            gap: String(Math.min(600, r.wait)), from: String(r.start - r.wait), day: String(d) });
        Object.keys(haveMap()).slice(0, 80).forEach(function (k) { q.append("have[]", k); });
        fetch(cfg.gapUrl + "?" + q.toString(), { credentials: "same-origin", headers: { Accept: "application/json" } })
            .then(function (x) { return x.json(); })
            .then(function (j) { if (ui.gap && ui.gap.key === key) { ui.gap = { key: key, loading: false, data: j.results || [] }; renderDays(); } })
            .catch(function () { if (ui.gap && ui.gap.key === key) { ui.gap = { key: key, loading: false, data: [], err: true }; renderDays(); } });
    }
    function gapList(d, r) {
        var li = el("li", "tpx-gapfill");
        if (ui.gap.loading) {   // PF-09: khung chờ thay vì chữ trống
            li.setAttribute("aria-busy", "true");
            li.appendChild(el("span", "sr-only", "Đang tìm nơi vừa khoảng trống…"));
            for (var k = 0; k < 3; k++) li.appendChild(el("span", "tp-skel"));
            return li;
        }
        var list = ui.gap.data || [];
        if (!list.length) { li.appendChild(el("p", "tp-muted tp-small", ui.gap.err ? "Chưa tìm được lúc này, thử lại sau." : "Không có nơi nào gần tuyến vừa khoảng trống này.")); return li; }
        var ul = el("ul", "tpx-gapfill__list");
        list.forEach(function (c) {
            var row = el("li");
            var t = el("div");
            t.appendChild(el("b", null, c.name));
            t.appendChild(el("small", "tp-muted", [c.kind, c.fitText].filter(Boolean).join(" · ")));
            row.appendChild(t);
            row.appendChild(btn("tp-btn tp-btn--sm", "Thêm vào đây", "Thêm " + c.name + " vào khoảng trống", function () {
                if (items.length >= LIMITS.maxItems) { say("Chuyến đi đã đạt tối đa " + LIMITS.maxItems + " mục.", true); return; }
                if (haveMap()[c.type + ":" + c.refId]) return;
                var it = newItem({ type: c.type, refId: c.refId, name: c.name, sub: c.kind, url: c.url, lat: c.lat, lng: c.lng, day: d, defDur: c.stayMin, image: c.image });
                it.durationMin = c.stayMin;
                items.splice(items.indexOf(r.it), 0, it);
                ui.gap = null;
                commit();
                say("Đã thêm “" + c.name + "” vào khoảng trống trước “" + r.it.name + "”.", false, [["Hoàn tác", undoOnce]]);
            }));
            ul.appendChild(row);
        });
        li.appendChild(ul);
        return li;
    }
    /* ---------------- Thời tiết theo dự báo (A-10a icon, T-15 theo giờ, A-10b WeatherFit) ---------------- */
    function wxDay(d) {
        var w = ui.srv && ui.srv.weather;
        if (!w || w.source !== "forecast") return null;
        return (w.days || []).filter(function (x) { return x.day === d; })[0] || null;
    }
    function wxChip(d) {
        var w = wxDay(d);
        if (!w) return null;
        var b = btn("tpx-wx" + (w.rain >= 60 ? " is-wet" : "") + (ui.wxOpen && ui.wxOpen[d] ? " is-open" : ""), null,
            "Dự báo ngày " + d + ": " + w.label + ", " + (w.tMin != null ? w.tMin + "–" : "") + (w.tMax != null ? w.tMax + "°C" : "") + ", mưa " + (w.rain || 0) + "%. Bấm để xem theo giờ.",
            function () { ui.wxOpen = ui.wxOpen || {}; if (ui.wxOpen[d]) delete ui.wxOpen[d]; else ui.wxOpen[d] = true; renderDays(); });
        b.appendChild(icon(w.icon || "fa-cloud"));
        b.appendChild(el("span", null, (w.tMax != null ? w.tMax + "°" : "") + (w.rain ? " · " + w.rain + "%" : "")));
        if (w.fitPct != null && w.fitPct < 100) b.appendChild(el("small", "tpx-wx__fit", "hợp " + w.fitPct + "%"));
        b.setAttribute("aria-expanded", ui.wxOpen && ui.wxOpen[d] ? "true" : "false");
        return b;
    }
    function wxHours(d) {
        var w = wxDay(d);
        if (!w || !(w.hours || []).length) return null;
        var box = el("div", "tpx-wxh");
        var ul = el("ol", "tpx-wxh__list");
        w.hours.forEach(function (h) {
            var li = el("li", h.bad ? "is-bad" : null);
            li.appendChild(el("b", null, (h.h < 10 ? "0" : "") + h.h + "h"));
            li.appendChild(icon(h.icon || "fa-cloud"));
            li.appendChild(el("span", null, (h.t != null ? h.t + "°" : "–")));
            li.appendChild(el("small", null, (h.p != null ? h.p : 0) + "%"));
            ul.appendChild(li);
        });
        box.appendChild(ul);
        var srv = ui.srv.weather;
        box.appendChild(el("p", "tp-muted tp-small", "Dự báo theo giờ của Open-Meteo" + (srv.fetchedAt ? ", cập nhật " + new Date(srv.fetchedAt).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "")
            + (srv.stale ? " (bản cũ)" : "") + ". Ô được tô đậm: giờ có mưa ≥ 60%, dông, gió mạnh hoặc nắng nóng giữa trưa." + (w.fitPct != null ? " Hợp thời tiết: " + w.fitPct + "% thời gian ngoài trời." : "")));
        return box;
    }
    function daySection(s) {
        var d = s.day, color = dayColor(d), collapsed = !!ui.collapsed[d];
        var sec = el("section", "tpx-day" + (collapsed ? " is-collapsed" : ""));
        sec.id = "tpDay" + d;
        sec.setAttribute("data-day", String(d));
        sec.style.setProperty("--c", color);

        var head = el("header", "tpx-dayhead");
        var tg = btn("tpx-dayhead__t", null, null, function () { if (ui.collapsed[d]) delete ui.collapsed[d]; else ui.collapsed[d] = true; renderDays(); });
        tg.setAttribute("aria-expanded", collapsed ? "false" : "true");
        tg.appendChild(icon(collapsed ? "fa-chevron-right" : "fa-chevron-down"));
        var h3 = el("h3", null, "Ngày " + d);
        tg.appendChild(h3);
        if (dayDate(d)) tg.appendChild(el("span", "tpx-dayhead__date", dayDate(d)));
        head.appendChild(tg);
        var wx = wxChip(d);   // A-10a: icon thời tiết theo dự báo của ngày (máy chủ, Open-Meteo)
        if (wx) head.appendChild(wx);

        var sum = el("p", "tpx-daysum");
        if (s.count) {
            // tóm tắt ngắn: số điểm · hoạt động · di chuyển (ghi rõ ước tính) · km · chi phí · mức độ
            var chips = [["fa-clock", hhmm(s.start) + "–" + hhmm(s.end)], ["fa-map-pin", s.count + " điểm"], ["fa-hourglass-half", durText(s.act) + " hoạt động"]];
            if (s.travel) chips.push(["fa-car-side", (realRoute() ? "" : "≈ ") + durText(s.travel) + " di chuyển" + (realRoute() ? "" : " (ước tính)")]);
            if (s.km) chips.push(["fa-road", s.km + " km"]);
            var c = s.cost + s.est;
            if (c) chips.push(["fa-wallet", (s.est ? "≈ " : "") + shortMoney(c) + (s.est ? " (có ước tính)" : "")]);
            chips.forEach(function (x) {
                var ch = el("span", "tpx-dchipx");
                ch.appendChild(icon(x[0]));
                ch.appendChild(el("span", null, x[1]));
                sum.appendChild(ch);
            });
        } else {
            sum.appendChild(el("span", "tpx-daysum__empty", "Chưa có điểm nào. Thêm từ ô tìm kiếm, kéo từ “Đã lưu”, hoặc tìm quanh tuyến."));
        }
        head.appendChild(sum);
        if (s.count) {
            head.appendChild(loadBar(s));
            sum.appendChild(el("span", "tpx-loadlbl tpx-loadlbl--" + loadLevel(s), S.loadLabel(s.load) + " · " + Math.round(s.load * 100) + "%"));
        }

        var act = el("div", "tpx-dayhead__act");
        if (!RO) {
            act.appendChild(ibtn("tp-mini", "fa-plus", "Thêm", "Thêm vào ngày " + d, function () { openAdd(d); }));
            if (s.canOpt) act.appendChild(ibtn("tp-mini", "fa-route", "Tối ưu tuyến", "Tối ưu tuyến ngày " + d, function () { optimizeDay(d); }));
            act.appendChild(dayMenu(d));
        }
        head.appendChild(act);
        sec.appendChild(head);
        if (collapsed) return sec;
        if (ui.wxOpen && ui.wxOpen[d]) { var wh = wxHours(d); if (wh) sec.appendChild(wh); }

        var iss = dayIssues(s);
        if (iss.length) {
            var ul0 = el("ul", "tpx-issues");
            iss.forEach(function (x) {
                var li = el("li", "tpx-issue tpx-issue--" + x.lvl);
                li.appendChild(icon(x.lvl === "bad" ? "fa-exclamation-triangle" : "fa-info-circle"));
                li.appendChild(el("span", null, x.text));
                if (!RO) (x.acts || []).forEach(function (a) { li.appendChild(btn("tp-mini", a[0], null, a[1])); });
                ul0.appendChild(li);
            });
            sec.appendChild(ul0);
        }
        if (ui.opt && ui.opt.day === d) sec.appendChild(optBox());
        if (ui.bal && ui.bal.day === d) sec.appendChild(balBox());

        var ol = el("ol", "tpx-stops");
        ol.setAttribute("data-day", String(d));
        var cx = s.ctx || {};
        if (cx.arrive != null) ol.appendChild(baseRow("fa-plane-arrival", "Tới nơi, bắt đầu chơi được từ khoảng " + hhmm(cx.arrive), cx.arriveText, "is-arrive"));
        if (cx.from && s.rows.length) ol.appendChild(baseRow("fa-bed", "Xuất phát từ " + cx.from.name, s.startLeg ? "≈ " + durText(s.startLeg.min) + " · " + String(Math.round(s.startLeg.km * 10) / 10).replace(".", ",") + " km tới điểm đầu tiên" : "Điểm đầu tiên ở ngay gần", "is-base"));
        if (!s.rows.length) {
            ol.appendChild(el("li", "tp-empty tpx-empty", RO ? "Chưa có mục nào." : "Chưa có mục nào. Bấm “Thêm” để tìm địa điểm, hoặc kéo một mục từ ngày khác vào đây."));
        } else {
            var lastSlot = null, prevRow = null;
            s.rows.forEach(function (r) {
                if (r.slot !== lastSlot) {
                    var sh = el("li", "tpx-slot");
                    sh.appendChild(icon(SLOT_HEAD[r.slot][0]));
                    sh.appendChild(el("span", null, SLOT_HEAD[r.slot][1]));
                    ol.appendChild(sh);
                    lastSlot = r.slot;
                }
                if (r.wait >= 15) {
                    var fr = el("li", "tpx-free");
                    fr.appendChild(icon("fa-mug-hot"));
                    fr.appendChild(el("span", null, "Thời gian trống " + durText(r.wait) + " (" + hhmm(r.start - r.wait) + "–" + hhmm(r.start) + ")"));
                    if (!RO && cfg.aroundUrl && routePts(d).length) fr.appendChild(btn("tp-link tp-small", "Tìm gì gần đây", "Tìm điểm dừng quanh tuyến ngày " + d, function () { openAdd(d, "around"); }));
                    // T-03: gợi ý nơi VỪA khoảng trống giữa mục trước và mục này (thời gian ở + đi vòng ≤ khoảng trống, đang mở cửa)
                    var pa = prevRow && hasPos(prevRow.it) ? prevRow.it : null, pb = hasPos(r.it) ? r.it : null;
                    if (!RO && cfg.gapUrl && r.wait >= 30 && pa && pb) {
                        var gk = d + ":" + r.it.uid;
                        fr.appendChild(btn("tp-link tp-small", ui.gap && ui.gap.key === gk ? "Đóng gợi ý" : "Gợi ý lấp chỗ trống", "Gợi ý nơi vừa khoảng trống " + durText(r.wait), function () { toggleGap(gk, d, pa, pb, r); }));
                    }
                    ol.appendChild(fr);
                    if (ui.gap && ui.gap.key === d + ":" + r.it.uid) ol.appendChild(gapList(d, r));
                }
                ol.appendChild(itemRow(r.it, r));
                if (r.leg) ol.appendChild(legRow(r.leg, r));
                prevRow = r;
            });
        }
        if (cx.to && s.rows.length) ol.appendChild(baseRow("fa-bed", "Về " + cx.to.name, s.endLeg ? "≈ " + durText(s.endLeg.min) + " · " + String(Math.round(s.endLeg.km * 10) / 10).replace(".", ",") + " km từ điểm cuối" : "Điểm cuối ở ngay gần", "is-base"));
        if (cx.leave != null) ol.appendChild(baseRow("fa-plane-departure", "Rời đi trước " + hhmm(cx.leave), cx.leaveText, "is-leave"));
        if (d === 1 && !cx.from && !cx.to && s.rows.length && (cfg.logistics || {}).stays && cfg.logistics.stays.some(function (x) { return x.lat == null; }) && !RO) {
            var hint = baseRow("fa-map-marker-alt", "Chỗ ở chưa có vị trí", "Gắn vị trí ở mục Đặt chỗ (tab Tổng quan) để tính đường đi từ khách sạn mỗi ngày.", "is-hint");
            ol.appendChild(hint);
        }
        dropZone(d, ol);
        sec.appendChild(ol);
        if (cfg.rainSuggestUrl && !RO) sec.appendChild(rainPanel(d));
        return sec;
    }
    /** Dòng mốc trong lịch ngày: xuất phát / về chỗ ở, giờ tới nơi, giờ phải rời đi (không kéo thả được, không phải mục). */
    function baseRow(ic, title, sub, cls) {
        var li = el("li", "tpx-base " + (cls || ""));
        li.appendChild(icon(ic));
        var m = el("div", "tpx-base__m");
        m.appendChild(el("b", null, title));
        if (sub) m.appendChild(el("small", null, sub));
        li.appendChild(m);
        return li;
    }
    /** "Đã lưu": mục chưa xếp ngày (hộp chờ, giống "Places to visit" của Wanderlog). Khám phá -> Lưu -> kéo vào ngày. */
    function waitSection(list) {
        var sec = el("section", "tpx-day tpx-day--wait" + (list.length ? "" : " is-empty"));
        sec.id = "tpDayX";
        sec.style.setProperty("--c", "#6b7280");
        var head = el("header", "tpx-dayhead");
        var t = el("div", "tpx-dayhead__t");
        t.appendChild(icon("fa-inbox"));
        t.appendChild(el("h3", null, "Đã lưu"));
        head.appendChild(t);
        head.appendChild(el("p", "tpx-daysum", list.length
            ? list.length + " mục chưa xếp ngày. Kéo vào một ngày, hoặc chọn ngày ở ô cạnh mục."
            : "Nơi cất những chỗ bạn thích nhưng chưa biết đi ngày nào: bấm 🔖 Lưu khi khám phá quanh tuyến, chọn “Đã lưu” khi thêm, hoặc kéo một mục từ ngày vào đây."));
        var act = el("div", "tpx-dayhead__act");
        if (!RO && list.length) act.appendChild(btn("tp-mini", "Chọn hết", "Chọn tất cả mục chưa xếp", function () {
            list.forEach(function (x) { ui.sel[x.uid] = true; });
            renderDays();
            renderBulk();
        }));
        head.appendChild(act);
        sec.appendChild(head);
        var ol = el("ol", "tpx-stops tpx-stops--wait");
        ol.setAttribute("data-day", "");
        list.forEach(function (it) { ol.appendChild(itemRow(it, null)); });
        dropZone(null, ol);
        sec.appendChild(ol);
        // nhiều mục chờ mà đã có lịch: thu gọn để các ngày không bị đẩy xuống quá xa (vẫn kéo thả vào được)
        var many = list.length > 3 && items.some(function (x) { return x.day != null; });
        if (many) {
            var open = !!ui.waitOpen;
            ol.classList.toggle("is-folded", !open);
            var more = btn("tp-mini tpx-waitmore", open ? "Thu gọn" : "Hiện tất cả " + list.length + " mục", open ? "Thu gọn khu Đã lưu" : "Hiện tất cả mục trong Đã lưu", function () {
                ui.waitOpen = !ui.waitOpen;
                renderDays();
            });
            more.setAttribute("aria-expanded", open ? "true" : "false");
            sec.appendChild(more);
        }
        return sec;
    }
    function renderDays() {
        box.textContent = "";
        box.classList.toggle("is-compact", ui.view === "compact");
        var wait = dayItems(null);
        if (!items.length) {
            var em = el("div", "tpx-blank");
            em.appendChild(icon("fa-map-signs"));
            em.appendChild(el("b", null, "Chuyến đi chưa có điểm nào"));
            em.appendChild(el("p", "tp-muted", "Tìm địa điểm, quán ăn, khách sạn hay trải nghiệm rồi xếp vào từng ngày. Giờ, quãng đường và chi phí sẽ tự tính."));
            if (!RO) em.appendChild(ibtn("tp-btn", "fa-plus", "Thêm điểm đầu tiên", null, function () { openAdd(1); }));
            box.appendChild(em);
        }
        if (ui.view === "list" && items.length) {
            box.appendChild(flatList());
            renderDayNav();
            renderSide();
            emit("tp:render");
            return;
        }
        if (wait.length || !RO) box.appendChild(waitSection(wait));   // "Đã lưu" luôn có chỗ (kể cả rỗng) để kéo mục ra khỏi ngày
        allSched().forEach(function (s) { box.appendChild(daySection(s)); });
        renderDayNav();
        renderSide();
        observeDays();
        emit("tp:render");   // La bàn vẽ lại dấu xem trước (ghost) lên timeline mới
    }
    function emit(name, detail) {
        try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) { /* trình duyệt quá cũ: bỏ qua */ }
    }
    function renderTotals() {
        var sum = totalCost(), es = estimates(), b = meta().budget, t = $("tpTotals");
        var s = "Đã nhập " + money(sum);
        if (es.total) s += " · ước tính thêm ≈ " + money(es.total);
        if (b != null) s += " · ngân sách " + money(b) + (sum + es.total > b ? " (vượt " + money(sum + es.total - b) + ")" : "");
        t.textContent = s;
        t.classList.toggle("tp-totals--over", b != null && sum + es.total > b);
    }

    /* ---------------- ngày đang xem (theo cuộn) ---------------- */
    function setCurDay(d, fromClick) {
        if (ui.curDay === d) return;
        ui.curDay = d;
        document.querySelectorAll("#tpDayNav .tpx-dchip").forEach(function (b) {
            var on = b.getAttribute("data-day") === String(d === null ? "x" : d);
            b.classList.toggle("is-on", on);
            if (on) {
                b.setAttribute("aria-current", "true");
                if (!fromClick) { try { b.scrollIntoView({ block: "nearest", inline: "nearest" }); } catch (e) { /* bỏ qua */ } }
            } else b.removeAttribute("aria-current");
        });
        document.querySelectorAll("#tpSideDays .tpx-side__day").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-day") === String(d)); });
        syncSideTop();
        if (ui.mapDay === "follow") renderMap();
    }
    /** Ngày đang xem = ngày có khung cắt qua một đường ngang ngay dưới dải ngày (dính). Gọi khi cuộn và sau khi vẽ lại. */
    var obsRaf = 0;
    function detectDay() {
        obsRaf = 0;
        if (ui.tab !== "plan" || Date.now() < (ui.lockDay || 0)) return;   // vừa bấm một ngày: chờ cuộn mượt xong
        var strip = document.querySelector(".tpx-days"), top = strip ? strip.getBoundingClientRect().bottom : 100;
        var y = top + Math.min(200, (window.innerHeight - top) * 0.3);
        var secs = box.querySelectorAll(".tpx-day"), pick = null;
        for (var i = 0; i < secs.length; i++) {
            var r = secs[i].getBoundingClientRect();
            if (r.top <= y) pick = secs[i]; else break;
        }
        if (!pick && secs.length) pick = secs[0];
        if (!pick) return;
        var dv = pick.getAttribute("data-day");
        setCurDay(dv ? +dv : "x", false);
    }
    function observeDays() {
        if (!obsRaf) obsRaf = requestAnimationFrame(detectDay);
    }
    window.addEventListener("scroll", observeDays, { passive: true });

    /* ---------------- chọn nhiều mục ---------------- */
    function selected() { return items.filter(function (x) { return ui.sel[x.uid]; }); }
    function renderBulk() {
        var b = $("tpBulk"), list = selected();
        b.textContent = "";
        b.hidden = !list.length;
        if (!list.length) return;
        b.appendChild(el("strong", null, "Đã chọn " + list.length + " mục"));
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Chuyển các mục đã chọn sang");
        var o0 = el("option", null, "Chưa xếp ngày");
        o0.value = "";
        sel.appendChild(o0);
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d);
            o.value = String(d);
            sel.appendChild(o);
        }
        b.appendChild(sel);
        b.appendChild(btn("tp-mini", "Chuyển", "Chuyển các mục đã chọn", function () {
            var target = sel.value === "" ? null : +sel.value;
            selected().forEach(function (x) { moveItem(x, target, null); });
            ui.sel = {};
            commit();
        }));
        b.appendChild(btn("tp-mini tp-mini--danger", "Xóa", "Xóa các mục đã chọn", function () {
            var rm = selected();
            if (!window.confirm("Xóa " + rm.length + " mục đã chọn khỏi chuyến đi?")) return;
            items = items.filter(function (x) { return !ui.sel[x.uid]; });
            ui.sel = {};
            commit();
        }));
        b.appendChild(btn("tp-mini", "Bỏ chọn", "Bỏ chọn tất cả", function () { ui.sel = {}; renderDays(); renderBulk(); }));
    }


    /* ---------------- thanh bên kiểu Wanderlog ---------------- */
    function renderSide() {
        var ol = $("tpSideDays");
        if (!ol) return;
        ol.textContent = "";
        allSched().forEach(function (s) {
            var li = el("li");
            var b = btn("tpx-side__day" + (ui.tab === "plan" && ui.curDay === s.day ? " is-on" : ""), null, "Ngày " + s.day + (dayDate(s.day) ? ", " + dayDate(s.day) : "") + ", " + s.count + " mục", function () { gotoDay(s.day); });
            b.setAttribute("data-day", String(s.day));
            b.style.setProperty("--c", dayColor(s.day));
            var t = el("span", "tpx-side__dt");
            t.appendChild(el("b", null, dayDate(s.day) || "Ngày " + s.day));
            t.appendChild(el("small", null, dayDate(s.day) ? "Ngày " + s.day + " · " + s.count + " điểm" : s.count + " điểm"));
            b.appendChild(t);
            if (dayIssues(s).some(function (x) { return x.lvl === "bad"; })) b.appendChild(icon("fa-exclamation-circle tpx-dchip__warn"));
            b.appendChild(loadBar(s, "tpx-load--mini"));
            li.appendChild(b);
            ol.appendChild(li);
        });
        var w = $("tpSideWait"), n = dayItems(null).length;
        if (w) w.textContent = n ? String(n) : "";
    }
    function syncSideTop() {
        document.querySelectorAll(".tpx-side__b[data-go]").forEach(function (b) {
            var g = b.getAttribute("data-go");
            var on = g === "cost" || g === "info" ? ui.tab === g : g === "x" ? ui.tab === "plan" && ui.curDay === "x" : g === "top" ? ui.tab === "plan" && ui.curDay == null : false;
            b.classList.toggle("is-on", on);
        });
        if (ui.tab !== "plan") document.querySelectorAll("#tpSideDays .tpx-side__day").forEach(function (b) { b.classList.remove("is-on"); });
    }
    function sideGo(k) {
        if (k === "top") { switchTab("plan"); window.scrollTo({ top: 0, behavior: "smooth" }); return; }
        if (k === "around") { openAdd(ui.curDay && ui.curDay !== "x" ? ui.curDay : 1, "around"); return; }
        if (k === "cost" || k === "info") { switchTab(k); try { $("tpBody").scrollIntoView({ block: "start", behavior: "smooth" }); } catch (e) { /* bỏ qua */ } return; }
        gotoDay(k);
    }

    /* ---------------- khám phá quanh tuyến (Roadtrippers) ---------------- */
    var AROUND_KM = [0.5, 1, 2, 3, 5];
    var AROUND_GROUPS = [["all", "fa-th-large", "Tất cả"], ["eat", "fa-utensils", "Ăn uống"], ["cafe", "fa-coffee", "Cà phê"], ["sight", "fa-landmark", "Tham quan"],
        ["nature", "fa-tree", "Thiên nhiên"], ["fun", "fa-ticket-alt", "Vui chơi"], ["experience", "fa-hiking", "Trải nghiệm"]];
    function routePts(d) { return dayItems(d).filter(G.hasPos); }
    function kmText(k) { return (Math.round(k * 10) / 10).toLocaleString("vi-VN") + " km"; }
    function aroundDay() {
        var a = ui.around;
        if (a.day && routePts(a.day).length) return a.day;
        for (var d = 1; d <= daysCount(); d++) if (routePts(d).length) return d;
        return null;
    }
    function loadAround() {
        var a = ui.around, d = aroundDay();
        a.day = d;
        if (!d || !cfg.aroundUrl) { a.data = null; renderAround(); return; }
        var pts = routePts(d), mine = ++a.seq;
        var have = items.filter(function (x) { return !CUSTOM[x.type]; }).map(key).slice(0, 80);
        var url = cfg.aroundUrl + "?pts=" + encodeURIComponent(pts.map(function (p) { return (+p.lat).toFixed(6) + "," + (+p.lng).toFixed(6); }).join(";")) +
            "&km=" + a.km + "&group=" + a.group + have.map(function (k) { return "&have[]=" + encodeURIComponent(k); }).join("");
        a.loading = true;
        renderAround();
        fetchJson(url)
            .then(function (j) { if (mine === a.seq) { a.loading = false; a.failed = false; a.data = j; a.pts = pts; renderAround(); renderMap(); } })
            .catch(function () { if (mine === a.seq) { a.loading = false; a.failed = true; renderAround(); } });
    }
    function aroundAfterItem(r) {
        var pts = ui.around.pts || [];
        return pts[r.after] || null;
    }
    /** Thêm một kết quả vào ngày đang xem, ngay sau điểm mà nó nằm cạnh trên tuyến. */
    function aroundAdd(r) {
        var after = aroundAfterItem(r), d = ui.around.day;
        if (items.length >= LIMITS.maxItems) { say("Chuyến đi đã đạt tối đa " + LIMITS.maxItems + " mục.", true); return; }
        if (haveMap()[r.type + ":" + r.refId]) return;
        var it = newItem({ type: r.type, refId: r.refId, name: r.name, sub: r.kind, url: r.url, lat: r.lat, lng: r.lng, day: d, defDur: r.durationMin, image: r.image });
        if (after && items.indexOf(after) >= 0) {
            var grp = dayItems(d), i = grp.indexOf(after), next = grp[i + 1] || null;
            if (next) items.splice(items.indexOf(next), 0, it); else items.push(it);
        } else items.push(it);
        commit();
        say("Đã thêm “" + r.name + "” vào ngày " + d + (after ? ", sau “" + after.name + "”" : "") + ".");
        ui.around.data.results = ui.around.data.results.filter(function (x) { return x !== r; });
        renderAround();
        renderMap();
    }
    /** Khám phá -> Lưu: thêm vào "Đã lưu" (chưa xếp ngày), sau đó kéo vào ngày nào cũng được. */
    function aroundSave(r) {
        if (items.length >= LIMITS.maxItems) { say("Chuyến đi đã đạt tối đa " + LIMITS.maxItems + " mục.", true); return; }
        if (haveMap()[r.type + ":" + r.refId]) return;
        items.push(newItem({ type: r.type, refId: r.refId, name: r.name, sub: r.kind, url: r.url, lat: r.lat, lng: r.lng, day: null, defDur: r.durationMin, image: r.image }));
        commit();
        say("Đã lưu “" + r.name + "” vào Đã lưu. Kéo vào một ngày khi bạn quyết định.", false, [["Hoàn tác", undoOnce]]);
        ui.around.data.results = ui.around.data.results.filter(function (x) { return x !== r; });
        renderAround();
        renderMap();
    }
    function aroundCardItem(r) {
        var li = el("li", "tpx-arc");
        var ph = el("div", "tpx-arc__ph");
        if (r.image) { var im = el("img"); im.src = r.image; im.alt = ""; im.loading = "lazy"; ph.appendChild(im); }
        else { ph.classList.add("is-icon"); ph.appendChild(icon((AROUND_GROUPS.filter(function (g) { return g[0] === r.group; })[0] || AROUND_GROUPS[3])[1])); }
        if (!RO) {
            ph.appendChild(btn("tpx-arc__add", "+", "Thêm " + r.name + " vào ngày " + ui.around.day, function () { aroundAdd(r); }));
            var sv = btn("tpx-arc__save", null, "Lưu " + r.name + " vào Đã lưu", function () { aroundSave(r); });
            sv.appendChild(icon("fa-bookmark"));
            ph.appendChild(sv);
        }
        li.appendChild(ph);
        var body = el("div", "tpx-arc__b");
        var href = safeUrl(r.url);
        if (href) { var a = el("a", "tpx-arc__n", r.name); a.href = href; a.target = "_blank"; a.rel = "noopener"; body.appendChild(a); }
        else body.appendChild(el("b", "tpx-arc__n", r.name));
        body.appendChild(el("small", "tpx-arc__k", r.kind || ""));
        var why = [r.detourKm < 0.5 ? "Gần tuyến ngày " + ui.around.day : "", r.mustTry ? "Phải thử ở đây" : ""].filter(Boolean).slice(0, 2);
        if (why.length) body.appendChild(el("small", "tpx-arc__why", "✓ " + why.join(" · ")));
        var after = aroundAfterItem(r);
        // người dùng quan tâm "mất thêm bao lâu": phút đi vòng (RouteService) + nhãn ngưỡng từ máy chủ (config planner.detour_levels)
        var lv = r.detourLevel || { key: "good", label: "" };
        var det = el("p", "tpx-arc__d tpx-arc__d--" + lv.key);
        det.appendChild(icon("fa-route"));
        det.appendChild(el("b", null, r.detourKm < 0.15 ? "Trên tuyến" : "+" + Math.max(1, r.detourMin) + " phút"));
        det.appendChild(el("span", null, [r.detourKm < 0.15 ? "" : "+" + kmText(r.detourKm), lv.label].filter(Boolean).join(" · ")));
        det.title = "Ước tính quãng đường và thời gian đi vòng (đường chim bay × hệ số), chưa phải đường bộ thật";
        body.appendChild(det);
        body.appendChild(el("small", "tpx-arc__k", "Cách tuyến " + kmText(r.offKm || 0) + " · ước tính đi vòng"));
        if (after) body.appendChild(el("small", "tpx-arc__k", "Ghé sau: " + after.name));
        var extra = [r.durationMin ? "ở ~" + durText(r.durationMin) : "", r.priceText || ""].filter(Boolean).join(" · ");
        if (extra) body.appendChild(el("small", "tpx-arc__k", extra));
        li.appendChild(body);
        li.addEventListener("mouseenter", function () { if (map) map.highlight("ar" + r.type + r.refId); });
        li.addEventListener("mouseleave", function () { if (map) map.highlight(null); });
        return li;
    }
    function renderAround() {
        var box3 = $("tpAround");
        if (!box3 || !ui.around) return;
        var a = ui.around;
        box3.textContent = "";
        box3.appendChild(el("p", "tp-muted tp-small", "Tìm điểm dừng nằm gần tuyến của một ngày, xếp theo quãng phải đi vòng thêm. Bấm + để chèn vào đúng chỗ trong ngày."));
        // chọn ngày
        var dRow = el("div", "tpx-around__row");
        dRow.appendChild(el("span", "tp-small tp-muted", "Tuyến của"));
        var any = false;
        for (var d = 1; d <= daysCount(); d++) {
            (function (day) {
                var n = routePts(day).length;
                var b = btn("tpx-tchip" + (a.day === day ? " is-on" : ""), null, null, function () { a.day = day; loadAround(); });
                b.style.setProperty("--c", dayColor(day));
                b.appendChild(el("span", null, "Ngày " + day));
                if (!n) { b.disabled = true; b.title = "Ngày này chưa có điểm nào có vị trí"; } else any = true;
                dRow.appendChild(b);
            })(d);
        }
        box3.appendChild(dRow);
        if (!any) {
            var em = el("div", "tpx-blank tpx-blank--sm");
            em.appendChild(icon("fa-route"));
            em.appendChild(el("b", null, "Chưa có tuyến để tìm quanh"));
            em.appendChild(el("p", "tp-muted", "Thêm ít nhất một điểm có vị trí vào một ngày, rồi quay lại đây."));
            box3.appendChild(em);
            return;
        }
        // bán kính
        var kRow = el("div", "tpx-around__row");
        kRow.appendChild(el("span", "tp-small tp-muted", "Cách tuyến tối đa"));
        var seg = el("div", "tpx-seg");
        AROUND_KM.forEach(function (k) {
            var b = btn("tpx-seg__b" + (a.km === k ? " is-on" : ""), kmText(k), "Trong " + kmText(k) + " quanh tuyến", function () { a.km = k; loadAround(); });
            b.setAttribute("aria-pressed", a.km === k ? "true" : "false");
            seg.appendChild(b);
        });
        kRow.appendChild(seg);
        box3.appendChild(kRow);
        // nhóm
        var gRow = el("div", "tpx-cats__row tpx-around__groups");
        AROUND_GROUPS.forEach(function (g) {
            var b = ibtn("tp-chip tpx-cat" + (a.group === g[0] ? " is-on" : ""), g[1], g[2], "Chỉ hiện " + g[2], function () { a.group = g[0]; loadAround(); });
            b.setAttribute("aria-pressed", a.group === g[0] ? "true" : "false");
            gRow.appendChild(b);
        });
        box3.appendChild(gRow);
        // kết quả
        var head = el("p", "tpx-around__head");
        if (a.loading) head.appendChild(el("span", null, "Đang tìm quanh tuyến ngày " + a.day + "…"));
        else if (a.failed) head.appendChild(el("span", null, "Chưa tải được kết quả, thử lại sau."));
        else if (a.data) head.appendChild(el("b", null, a.data.total + " nơi trong " + kmText(a.data.km) + " quanh tuyến ngày " + a.day));
        box3.appendChild(head);
        if (a.data && !a.loading) {
            var list = a.data.results || [];
            if (!list.length) {
                box3.appendChild(el("p", "tp-muted", "Không có nơi nào khớp. Thử nới khoảng cách hoặc chọn “Tất cả”."));
            } else {
                var ul = el("ul", "tpx-arcs");
                list.forEach(function (r) { ul.appendChild(aroundCardItem(r)); });
                box3.appendChild(ul);
            }
            box3.appendChild(el("p", "tp-muted tp-small", "Chỉ gồm địa điểm và trải nghiệm đã được biên tập duyệt trên Travela. “Đi thêm” = quãng đường tăng thêm khi ghé vào giữa hai điểm (đường chim bay × hệ số đường bộ), ước tính."));
        }
    }
    /** Thẻ nhỏ trên bản đồ cho một kết quả "quanh tuyến" (Roadtrippers: popup có nút + Thêm). */
    function openAroundCard(r) {
        ui.card = null;
        var c = $("tpPlaceCard");
        if (!c) return;
        c.textContent = "";
        c.hidden = false;
        var head = el("div", "tp-pcard__head");
        head.appendChild(window.TpMap.pinEl("#e9a23b", "+", true));
        var tt = el("div", "tp-pcard__title");
        tt.appendChild(el("strong", null, r.name));
        tt.appendChild(el("small", null, [r.kind, r.destName].filter(Boolean).join(" · ")));
        head.appendChild(tt);
        head.appendChild(btn("tp-mini tp-pcard__x", "✕", "Đóng thẻ", closeCard));
        c.appendChild(head);
        var body = el("div", "tp-pcard__body");
        if (r.image) { var im = el("img", "tp-pcard__img tpx-pcard__wide"); im.src = r.image; im.alt = r.name; body.appendChild(im); }
        var facts = el("ul", "tp-pcard__facts");
        var after = aroundAfterItem(r);
        [["Đi thêm", r.detourKm < 0.15 ? "nằm ngay trên tuyến" : "+" + Math.max(1, r.detourMin) + " phút (~" + kmText(r.detourKm) + ")" + (r.detourLevel ? " · " + r.detourLevel.label : "")], ["Ghé sau", after ? after.name : ""],
            ["Thời gian ở", r.durationMin ? "≈ " + durText(r.durationMin) : ""], ["Giá", r.priceText || ""], ["Địa chỉ", r.address || ""]].forEach(function (f) {
            if (!f[1]) return;
            var li = el("li");
            li.appendChild(el("b", null, f[0] + ": "));
            li.appendChild(el("span", null, f[1]));
            facts.appendChild(li);
        });
        body.appendChild(facts);
        c.appendChild(body);
        var act = el("div", "tp-pcard__act");
        if (!RO) {
            act.appendChild(ibtn("tp-btn", "fa-plus", "Thêm vào ngày " + ui.around.day, null, function () { aroundAdd(r); closeCard(); }));
            act.appendChild(ibtn("tp-btn tp-btn--ghost", "fa-bookmark", "Lưu", "Lưu vào Đã lưu", function () { aroundSave(r); closeCard(); }));
        }
        var href = safeUrl(r.url);
        if (href) { var a3 = el("a", "tp-btn tp-btn--ghost", "Xem trên Travela"); a3.href = href; a3.target = "_blank"; a3.rel = "noopener"; act.appendChild(a3); }
        c.appendChild(act);
        if (map) { map.highlight("ar" + r.type + r.refId); map.view(r.lat, r.lng, Math.max(map.zoom(), 15)); }
    }
    function aroundOnMap() {
        var dr = $("tpAddDrawer");
        return ((ui.dtab === "around" && dr && !dr.hidden) || ui.mapDay === "explore") && ui.around && ui.around.data && ui.around.day;
    }
    function setDtab(k) {
        ui.dtab = k;
        document.querySelectorAll(".tpx-dtab").forEach(function (b) {
            var on = b.getAttribute("data-dtab") === k;
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-selected", on ? "true" : "false");
        });
        $("tpAdd").hidden = k !== "find";
        $("tpAround").hidden = k !== "around";
        if (k === "around") { if (!ui.around.data && !ui.around.loading) loadAround(); else renderAround(); }
        renderMap();
    }

    /* ---------------- thao tác cả ngày: chèn, đổi chỗ, xóa, nhân bản ---------------- */
    function shiftRain(fn) {
        var o = {};
        Object.keys(rain).forEach(function (k) { var nk = fn(+k); if (nk) o[String(nk)] = rain[k]; });
        rain = o;
    }
    function setDays(n) { $("tpDays").value = String(n); }
    function insertDayAfter(d) {
        if (daysCount() >= LIMITS.maxDays) { say("Chuyến đi tối đa " + LIMITS.maxDays + " ngày.", true); return false; }
        items.forEach(function (x) { if (x.day != null && x.day > d) x.day++; });
        shiftRain(function (k) { return k > d ? k + 1 : k; });
        setDays(daysCount() + 1);
        return true;
    }
    function swapDays(a, b) {
        if (b < 1 || b > daysCount()) return;
        var A = items.filter(function (x) { return x.day === a; }), B = items.filter(function (x) { return x.day === b; });
        A.forEach(function (x) { x.day = b; });
        B.forEach(function (x) { x.day = a; });
        shiftRain(function (k) { return k === a ? b : k === b ? a : k; });
        commit();
        say("Đã đổi chỗ ngày " + a + " và ngày " + b + ".");
    }
    function deleteDay(d) {
        var n = dayItems(d).length;
        if (daysCount() <= 1) { say("Chuyến đi cần ít nhất một ngày.", true); return; }
        if (n && !window.confirm("Xóa ngày " + d + "? " + n + " mục của ngày này sẽ chuyển vào “Đã lưu”.")) return;
        items.forEach(function (x) { if (x.day === d) x.day = null; else if (x.day != null && x.day > d) x.day--; });
        shiftRain(function (k) { return k === d ? null : k > d ? k - 1 : k; });
        setDays(daysCount() - 1);
        commit();
        say("Đã xóa ngày " + d + ". Bấm Hoàn tác nếu nhầm.");
    }
    /** Nhân bản ngày: chèn ngày mới ngay sau, chép các mục tự thêm (khách sạn, xe, ghi chú...). Nội dung Travela chỉ có một lần trong chuyến. */
    function duplicateDay(d) {
        var src = dayItems(d);
        if (!insertDayAfter(d)) return;
        var copied = 0, skipped = [];
        src.forEach(function (x) {
            if (!CUSTOM[x.type]) { skipped.push(x.name); return; }
            if (items.length >= LIMITS.maxItems) return;
            var c = JSON.parse(JSON.stringify(x));
            c.uid = ++seq;
            c.day = d + 1;
            items.push(c);
            copied++;
        });
        commit();
        say("Đã thêm ngày " + (d + 1) + " với " + copied + " mục tự thêm được chép" + (skipped.length ? "; " + skipped.length + " mục từ Travela không chép vì mỗi nơi chỉ có một lần trong chuyến" : "") + ".");
    }
    function dayMenu(d) {
        var det = el("details", "tpx-menu");
        var sum = el("summary", "tp-mini tpx-menu__s");
        sum.setAttribute("aria-label", "Thao tác khác cho ngày " + d);
        sum.appendChild(icon("fa-ellipsis-h"));
        det.appendChild(sum);
        var box4 = el("div", "tpx-menu__box");
        function mi(ic, text, fn, dis) {
            var b = ibtn("tpx-menu__i", ic, text, null, function () { det.open = false; fn(); });
            if (dis) b.disabled = true;
            box4.appendChild(b);
        }
        mi("fa-check-square", "Chọn tất cả mục (để chuyển hoặc xóa)", function () {
            dayItems(d).forEach(function (x) { ui.sel[x.uid] = true; });
            renderDays();
            renderBulk();
        }, !dayItems(d).length);
        mi("fa-plus-square", "Chèn một ngày phía sau", function () { if (insertDayAfter(d)) { commit(); say("Đã chèn ngày " + (d + 1) + "."); } }, daysCount() >= LIMITS.maxDays);
        mi("fa-clone", "Nhân bản ngày này", function () { duplicateDay(d); }, daysCount() >= LIMITS.maxDays);
        mi("fa-arrow-up", "Đổi chỗ với ngày trước", function () { swapDays(d, d - 1); }, d <= 1);
        mi("fa-arrow-down", "Đổi chỗ với ngày sau", function () { swapDays(d, d + 1); }, d >= daysCount());
        mi("fa-route", "Tìm điểm dừng quanh tuyến", function () { openAdd(d, "around"); }, !routePts(d).length);
        mi("fa-trash-alt", "Xóa ngày (mục chuyển vào Đã lưu)", function () { deleteDay(d); }, daysCount() <= 1);
        det.appendChild(box4);
        return det;
    }


    /* ---------------- tự cân bằng ngày quá dày (xem trước rồi áp dụng) ---------------- */
    var BAL_TARGET = 0.9;
    /** Lịch giả định sau khi chuyển: danh sách mục của ngày `d` nếu bỏ `out` và thêm `inn` vào cuối. */
    function simLoad(d, out, inn) {
        var list = dayItems(d).filter(function (x) { return out.indexOf(x) < 0; }).concat(inn || []);
        return schedule(d, list).load;
    }
    /**
     * Đề xuất cân bằng cho ngày d: lần lượt lấy mục "tự do" ít quan trọng nhất (không phải lưu trú / ăn uống / mục đầu ngày, ưu tiên mục dài và nằm cuối),
     * chuyển sang ngày còn chỗ có điểm gần nhất; không ngày nào nhận được thì đưa về "Đã lưu". Dừng khi ngày d xuống dưới 90%.
     */
    function planBalance(d) {
        var moves = [], out = [], gain = {};
        var cands = dayItems(d).filter(function (x, i) { var k = kindOf(x); return i > 0 && x.startMin == null && !x.locked && k !== "lodging" && k !== "meal" && k !== "transport"; });
        cands.sort(function (a, b) { return itemDur(b) - itemDur(a) || dayItems(d).indexOf(b) - dayItems(d).indexOf(a); });
        for (var c = 0; c < cands.length && simLoad(d, out) >= BAL_TARGET; c++) {
            var it = cands[c], best = null, bestScore = Infinity;
            for (var t = 1; t <= daysCount(); t++) {
                if (t === d) continue;
                var add = (gain[t] || []).concat([it]);
                if (simLoad(t, [], add) > BAL_TARGET) continue;
                var near = 30;
                if (G.hasPos(it)) dayItems(t).concat(gain[t] || []).filter(G.hasPos).forEach(function (x) { near = Math.min(near, G.hav(x, it)); });
                var score = near + simLoad(t, [], add) * 5;
                if (score < bestScore) { bestScore = score; best = t; }
            }
            out.push(it);
            if (best) (gain[best] = gain[best] || []).push(it);
            moves.push({ it: it, to: best });
        }
        return { day: d, moves: moves, before: schedule(d).load, after: simLoad(d, out) };
    }
    function balanceDay(d) {
        var p = planBalance(d);
        if (!p.moves.length) { say("Ngày " + d + " không còn mục nào chuyển được (mục đã khóa, có giờ cố định, lưu trú hoặc ăn uống được giữ lại).", true); return; }
        ui.bal = p;
        renderDays();
        var sec = $("tpDay" + d);
        if (sec) { try { sec.querySelector(".tpx-bal").scrollIntoView({ block: "nearest", behavior: "smooth" }); } catch (e) { /* bỏ qua */ } }
    }
    function balBox() {
        var p = ui.bal;
        var box5 = el("div", "tpx-opt tpx-bal");
        var h = el("p", "tpx-opt__h");
        h.appendChild(icon("fa-balance-scale"));
        h.appendChild(el("b", null, "Đề xuất cân bằng ngày " + p.day));
        box5.appendChild(h);
        var g = el("div", "tpx-opt__nums");
        [["Hiện tại", Math.round(p.before * 100) + "%"], ["Sau khi chuyển", Math.round(p.after * 100) + "%"], ["Chuyển", p.moves.length + " mục"]].forEach(function (c, i) {
            var cell = el("div", "tpx-opt__n" + (i === 1 && p.after < 1 ? " is-good" : ""));
            cell.appendChild(el("span", null, c[0]));
            cell.appendChild(el("b", null, c[1]));
            g.appendChild(cell);
        });
        box5.appendChild(g);
        var ul = el("ul", "tpx-bal__list");
        p.moves.forEach(function (m) {
            var li = el("li");
            li.appendChild(el("b", null, m.it.name));
            li.appendChild(icon("fa-long-arrow-alt-right"));
            li.appendChild(el("span", null, m.to ? "Ngày " + m.to + (dayDate(m.to) ? " (" + dayDate(m.to) + ")" : "") : "Đã lưu (chưa xếp ngày)"));
            ul.appendChild(li);
        });
        box5.appendChild(ul);
        var row = el("div", "tpx-opt__act");
        row.appendChild(el("span", "tp-small tp-muted", "Mục khóa vị trí, có giờ cố định, lưu trú và ăn uống được giữ nguyên."));
        row.appendChild(btn("tp-btn", "Áp dụng", "Áp dụng đề xuất cân bằng ngày " + p.day, function () {
            p.moves.forEach(function (m) { moveItem(m.it, m.to, null); });
            ui.bal = null;
            commit();
            say("Đã cân bằng ngày " + p.day + ".", false, [["Hoàn tác", undoOnce]]);
        }));
        row.appendChild(btn("tp-mini", "Giữ như cũ", "Hủy đề xuất cân bằng", function () { ui.bal = null; renderDays(); }));
        box5.appendChild(row);
        return box5;
    }

    /** Sau khi kéo / chuyển một mục vào ngày: kiểm tra ngày đó (độ dày, trùng giờ) và gợi ý cách xử lý ngay trong thông báo. */
    function checkPlaced(it) {
        if (RO || it.day == null) return;
        var s = schedule(it.day), row = s.rows.filter(function (r) { return r.it === it; })[0];
        var acts = [["Hoàn tác", undoOnce]];
        if (row && row.conflict) {
            say("“" + it.name + "” " + (row.conflict.type === "travel" ? "không kịp di chuyển tới lúc " : "trùng giờ lúc ") + hhmm(row.start) + " ở ngày " + it.day + ".", true,
                [["Bỏ giờ cố định", function () { it.startMin = null; commit(); }]].concat(acts));
            return;
        }
        if (s.load >= 1) {
            var alt = null, bl = Infinity;
            allSched().forEach(function (o) { if (o.day !== it.day && simLoad(o.day, [], [it]) < 1 && o.load < bl) { bl = o.load; alt = o.day; } });
            say("Ngày " + it.day + " đã kín (" + Math.round(s.load * 100) + "%, " + hhmm(s.start) + "–" + hhmm(s.end) + "). “" + it.name + "” vào " + hhmm(row ? row.start : s.end) + ".", true,
                (alt ? [["Chuyển sang ngày " + alt, function () { moveItem(it, alt, null); commit(); say("Đã chuyển “" + it.name + "” sang ngày " + alt + "."); }]] : [])
                    .concat([["Tự cân bằng", function () { balanceDay(it.day); }]]).concat(acts));
            return;
        }
        if (row) say("Đã xếp “" + it.name + "” vào ngày " + it.day + " lúc " + hhmm(row.start) + (row.leg ? ", đi tiếp " + row.leg.min + " phút" : "") + ".", false, acts);
    }

    /* ---------------- phương án mưa ngay tại hoạt động ngoài trời ---------------- */
    function rainInline(it, holder) {
        if (!cfg.rainSuggestUrl || it.day == null) return;
        holder.textContent = "Đang tìm mục trong nhà gần đó…";
        var have = items.filter(function (x) { return !CUSTOM[x.type]; }).map(key);
        var q = "?day=" + it.day + have.slice(0, 20).map(function (k) { return "&have[]=" + encodeURIComponent(k); }).join("");
        fetchJson(cfg.rainSuggestUrl + q).then(function (j) {
            holder.textContent = "";
            var list = (j.items || []).slice(0, 3);
            if (!list.length) { holder.appendChild(el("p", "tp-muted tp-small", "Chưa có mục trong nhà đã duyệt ở gần. Bạn có thể tự thêm một quán cà phê hoặc bảo tàng.")); return; }
            var ul = el("ul", "tpx-rainalt");
            list.forEach(function (x) {
                var li = el("li");
                var t = el("div");
                t.appendChild(el("b", null, x.name));
                t.appendChild(el("small", "tp-muted", [x.sub].concat(x.reasons || []).filter(Boolean).slice(0, 2).join(" · ")));
                li.appendChild(t);
                li.appendChild(btn("tp-mini", "Thay thế", "Thay " + it.name + " bằng " + x.name + " khi mưa", function () { rainSwap(it, x); }));
                li.appendChild(btn("tp-mini", "Giữ dự phòng", "Lưu " + x.name + " làm phương án mưa ngày " + it.day, function () {
                    var cur = rain[String(it.day)] = rain[String(it.day)] || [];
                    if (cur.length < RAIN_MAX && !cur.some(function (r) { return rainKey(r) === x.type + ":" + x.refId; })) {
                        cur.push({ type: x.type, refId: x.refId, name: x.name, sub: x.sub || "", url: x.url || null, lat: x.lat == null ? null : x.lat, lng: x.lng == null ? null : x.lng });
                        commit();
                        say("Đã lưu “" + x.name + "” làm phương án mưa ngày " + it.day + ".");
                    }
                }));
                ul.appendChild(li);
            });
            holder.appendChild(ul);
        }).catch(function () { holder.textContent = "Chưa tải được gợi ý, thử lại sau."; });
    }
    /** Thay hoạt động ngoài trời bằng mục trong nhà: mục mới nhận đúng chỗ và giờ, mục cũ về "Đã lưu" (hoàn tác được). */
    function rainSwap(it, x) {
        if (haveMap()[x.type + ":" + x.refId]) { say("“" + x.name + "” đã có trong chuyến.", true); return; }
        var n = newItem({ type: x.type, refId: x.refId, name: x.name, sub: x.sub, url: x.url, lat: x.lat, lng: x.lng, day: it.day });
        n.startMin = it.startMin;
        n.slot = it.slot;
        items.splice(items.indexOf(it), 0, n);
        it.day = null;
        it.startMin = null;
        commit();
        say("Đã thay “" + it.name + "” bằng “" + x.name + "”. Mục cũ nằm trong Đã lưu.", false, [["Hoàn tác", undoOnce]]);
    }

    /* ---------------- tối ưu tuyến: xem trước rồi mới áp dụng ---------------- */
    function routeNote(text, canUndo) {
        var n = $("tpRouteNote");
        n.textContent = "";
        n.hidden = !text;
        if (!text) return;
        n.appendChild(el("span", null, text));
        if (canUndo) n.appendChild(btn("tp-mini", "Hoàn tác", "Hoàn tác tối ưu tuyến", function () { undoOnce(); routeNote("Đã hoàn tác.", false); }));
        n.appendChild(btn("tp-mini", "Đóng", "Đóng thông báo", function () { routeNote("", false); }));
    }
    /** Tính thứ tự mới cho ngày d (láng giềng gần nhất + 2-opt, trong planner-geo.js). */
    function planRoute(d, keepFirst) {
        var group = dayItems(d);
        // chỉ sắp lại mục "tự do": có vị trí, chưa ghim giờ, chưa chọn buổi; các mục khác giữ nguyên chỗ
        var free = isFree;
        var mov = group.filter(free);
        if (mov.length < 3) return null;
        var np = G.optimize(mov, keepFirst).map(function (i) { return mov[i]; });
        var k = 0, order = group.map(function (x) { return free(x) ? np[k++] : x; });
        return { day: d, keepFirst: keepFirst, before: G.roadKmExact(group.filter(G.hasPos)), after: G.roadKmExact(order.filter(G.hasPos)), order: order };
    }
    /** Tối ưu tuyến qua máy chủ (RouteService: giữ mục cố định giờ / khóa / có buổi), lỗi mạng thì tính ngay trên trình duyệt. */
    function optimizeDay(d, keepFirst) {
        var kf = keepFirst != null ? keepFirst : (ui.opt && ui.opt.day === d ? ui.opt.keepFirst : true);
        var group = dayItems(d);
        if (!cfg.optimizeUrl || group.filter(isFree).length < 3) { optimizeLocal(d, kf); return; }
        var body = { keepFirst: kf, stops: group.map(function (x) { return { key: String(x.uid), lat: G.hasPos(x) ? x.lat : null, lng: G.hasPos(x) ? x.lng : null, anchor: !isFree(x) }; }) };
        // có chỗ ở: tuyến tối ưu là vòng từ nơi ngủ đêm trước tới nơi ngủ đêm nay (hai điểm neo ảo, không phải mục)
        var cx = dayCtx(d);
        if (cx.from && cx.arrive == null) { body.stops.unshift({ key: "__from", lat: cx.from.lat, lng: cx.from.lng, anchor: true }); body.keepFirst = false; }
        if (cx.to) body.stops.push({ key: "__to", lat: cx.to.lat, lng: cx.to.lng, anchor: true });
        post(cfg.optimizeUrl, body)
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (j) {
                var byKey = {};
                group.forEach(function (x) { byKey[String(x.uid)] = x; });
                var order = j.order.filter(function (k) { return k !== "__from" && k !== "__to"; }).map(function (k) { return byKey[k]; });
                if (order.some(function (x) { return !x; }) || order.length !== group.length) { optimizeLocal(d, kf); return; }
                if (!j.changed) { ui.opt = null; renderDays(); say("Ngày " + d + ": thứ tự hiện tại đã gần tối ưu" + (kf ? " (giữ điểm đầu ngày)." : ".")); return; }
                showOpt({ day: d, keepFirst: kf, before: j.beforeKm, after: j.afterKm, savedMin: j.savedMin, order: order, provider: j.provider });
            })
            .catch(function () { optimizeLocal(d, kf); });
    }
    function showOpt(p) {
        ui.opt = p;
        renderDays();
        var sec = $("tpDay" + p.day);
        if (sec) { try { sec.querySelector(".tpx-opt").scrollIntoView({ block: "nearest", behavior: "smooth" }); } catch (e) { /* bỏ qua */ } }
        renderMap();
    }
    function optimizeLocal(d, kf) {
        var p = planRoute(d, kf);
        if (!p) { say("Cần ít nhất 3 mục có vị trí, chưa chọn buổi và chưa đặt giờ để tối ưu.", true); return; }
        if (p.before - p.after < 0.2) {
            ui.opt = null;
            renderDays();
            say("Ngày " + d + ": thứ tự hiện tại đã gần tối ưu" + (p.keepFirst ? " (giữ điểm đầu ngày)." : "."));
            return;
        }
        showOpt(p);
    }
    /** Hộp quyết định: quãng đường hiện tại / sau tối ưu / tiết kiệm, thứ tự mới, Áp dụng hoặc Hủy. */
    function optBox() {
        var p = ui.opt, saved = p.before - p.after;
        var box2 = el("div", "tpx-opt");
        box2.setAttribute("role", "group");
        box2.setAttribute("aria-label", "Đề xuất tối ưu tuyến ngày " + p.day);
        var h = el("p", "tpx-opt__h");
        h.appendChild(icon("fa-route"));
        h.appendChild(el("b", null, "Đề xuất sắp lại tuyến ngày " + p.day));
        box2.appendChild(h);
        var g = el("div", "tpx-opt__nums");
        [["Hiện tại", "≈ " + (Math.round(p.before * 10) / 10).toLocaleString("vi-VN") + " km"], ["Sau tối ưu", "≈ " + (Math.round(p.after * 10) / 10).toLocaleString("vi-VN") + " km"],
            ["Tiết kiệm", "≈ " + (Math.round(saved * 10) / 10).toLocaleString("vi-VN") + " km · " + (p.savedMin != null ? p.savedMin : Math.round(saved / G.SPEED_KMH * 60)) + " phút"]].forEach(function (c, i) {
            var cell = el("div", "tpx-opt__n" + (i === 2 ? " is-good" : ""));
            cell.appendChild(el("span", null, c[0]));
            cell.appendChild(el("b", null, c[1]));
            g.appendChild(cell);
        });
        box2.appendChild(g);
        // hiện tại -> đề xuất, để người dùng thấy thay đổi gì trước khi áp dụng
        var cmp = el("div", "tpx-opt__cmp");
        [["Hiện tại", dayItems(p.day).filter(G.hasPos)], ["Đề xuất", p.order]].forEach(function (pair, k) {
            var col = el("div", "tpx-opt__col" + (k ? " is-new" : ""));
            col.appendChild(el("small", null, pair[0]));
            var ol = el("ol", "tpx-opt__order");
            pair[1].forEach(function (x) { ol.appendChild(el("li", null, x.name)); });
            col.appendChild(ol);
            cmp.appendChild(col);
        });
        box2.appendChild(cmp);
        var row = el("div", "tpx-opt__act");
        var kf = el("label", "tp-check tp-small");
        var cb = el("input");
        cb.type = "checkbox";
        cb.checked = !!p.keepFirst;
        cb.addEventListener("change", function () { optimizeDay(p.day, cb.checked); });
        kf.appendChild(cb);
        kf.appendChild(document.createTextNode(" Giữ điểm đầu ngày (ví dụ khách sạn)"));
        row.appendChild(kf);
        row.appendChild(btn("tp-btn", "Áp dụng", "Áp dụng thứ tự mới cho ngày " + p.day, function () {
            var slots = [];
            items.forEach(function (x, i) { if (x.day === p.day) slots.push(i); });
            if (slots.length !== p.order.length) { ui.opt = null; renderDays(); return; }
            slots.forEach(function (pos, j) { items[pos] = p.order[j]; });
            ui.opt = null;
            commit();
            routeNote("Ngày " + p.day + ": đã sắp lại tuyến, tiết kiệm khoảng " + Math.round(saved * 10) / 10 + " km (ước tính).", true);
        }));
        row.appendChild(btn("tp-mini", "Giữ như cũ", "Hủy đề xuất tối ưu", function () { ui.opt = null; renderDays(); renderMap(); }));
        box2.appendChild(row);
        box2.appendChild(el("p", "tp-muted tp-small", "Mục có giờ cố định, đã khóa vị trí, đã chọn buổi hoặc chưa có vị trí được giữ nguyên chỗ. Quãng đường " + (p.provider === "osrm" ? "theo đường bộ (OSRM)." : "ước tính theo đường chim bay × hệ số đường bộ.")));
        return box2;
    }

    /* ---------------- đầu trang, tổng quan, sức khỏe chuyến đi ---------------- */
    function fmtDate(d) { return pad2(d.getDate()) + "/" + pad2(d.getMonth() + 1); }
    function destName() {
        var d = meta().dest, x = d ? (cfg.dests || []).filter(function (z) { return z.id === d; })[0] : null;
        return x ? x.name : "";
    }
    function renderBrief() {
        var b = $("tpBrief");
        if (!b) return;
        b.textContent = "";
        var m = meta(), bits = [];
        if (destName()) bits.push(["fa-map-marker-alt", destName()]);
        if (m.start) {
            var s0 = new Date(m.start + "T00:00:00");
            if (!isNaN(s0.getTime())) {
                var e0 = new Date(s0.getTime());
                e0.setDate(e0.getDate() + m.days - 1);
                bits.push(["fa-calendar-alt", fmtDate(s0) + (m.days > 1 ? " – " + fmtDate(e0) : "") + " · " + m.days + " ngày"]);
            }
        } else bits.push(["fa-calendar-alt", m.days + " ngày, chưa chọn ngày đi"]);
        bits.push(["fa-user-friends", m.group + " người"]);
        if (m.budget != null) bits.push(["fa-wallet", "Ngân sách " + shortMoney(m.budget)]);
        bits.forEach(function (x) {
            var s = el("span", "tpx-meta__i");
            s.appendChild(icon(x[0]));
            s.appendChild(el("span", null, x[1]));
            b.appendChild(s);
        });
        if (!RO) b.appendChild(btn("tp-link tpx-meta__edit", "Sửa", "Sửa thông tin chuyến đi", function () { switchTab("info"); }));
    }
    function tripTotals() {
        var ss = allSched(), o = { km: 0, travel: 0, act: 0, placed: 0, heavy: 0 };
        ss.forEach(function (s) { o.km += s.km; o.travel += s.travel; o.act += s.act; o.placed += s.count; if (s.load >= 1 || s.conflicts.length) o.heavy++; });
        o.cost = totalCost();
        o.est = estimates().total;
        return o;
    }
    function renderKpis() {
        var k = $("tpKpis");
        if (!k) return;
        k.textContent = "";
        var o = tripTotals(), m = meta(), all = o.cost + o.est;
        var list = [
            ["fa-map-pin", String(o.placed), "điểm đã xếp"],
            ["fa-road", o.km + " km", "quãng đường"],
            ["fa-clock", dur(o.travel), "di chuyển"],
            ["fa-wallet", (o.est ? "≈ " : "") + shortMoney(all), m.budget ? "/ " + shortMoney(m.budget) + " ngân sách" : "dự kiến"],
        ];
        list.forEach(function (x, i) {
            var li = el("li", "tpx-kpi" + (i === 3 && m.budget && all > m.budget ? " is-over" : ""));
            li.appendChild(icon(x[0]));
            li.appendChild(el("b", null, x[1]));
            li.appendChild(el("span", null, x[2]));
            k.appendChild(li);
        });
    }
    function renderStats() {
        var s = $("tpStats");
        s.textContent = "";
        var m = meta(), o = tripTotals();
        [["Số ngày", String(m.days)], ["Điểm đã xếp", String(o.placed)], ["Đã lưu (chưa xếp)", String(dayItems(null).length)], ["Quãng đường", o.km + " km"],
            ["Di chuyển", durText(o.travel)], ["Hoạt động", durText(o.act)], ["Ngày cần chú ý", String(o.heavy)], ["Chi phí dự kiến", money(o.cost + o.est)]].forEach(function (c) {
            var card = el("div", "tp-stat");
            card.appendChild(el("b", null, c[1]));
            card.appendChild(el("span", null, c[0]));
            s.appendChild(card);
        });
    }
    /** Sức khỏe chuyến đi: một dòng tóm tắt; mở ra để xem từng kiểm tra và nhảy tới ngày có vấn đề. */
    /* Điểm sức khỏe từ Planner Engine phía máy chủ (TripHealth): chỉ phản ánh bản ĐÃ LƯU, nên tải lại sau mỗi lần lưu. */
    var srvSeq = 0;
    function loadServerHealth() {
        if (!cfg.healthUrl) return;
        var my = ++srvSeq;
        ui.srvLoading = true;
        API.health().then(function (j) {
            if (my !== srvSeq || !j || typeof j.score !== "number") return;
            ui.srv = j;
            ui.srvLoading = false;
            ui.rsv = null;   // đề xuất cũ tính trên bản đã lưu trước đó
            renderWarn();
            refreshLegs();   // huy hiệu từng chặng: đường thực tế / ước tính / không có đường / chưa rõ
            paintWeather();
        }).catch(function () { ui.srvLoading = false; ui.srvErr = true; renderWarn(); /* không chặn trình soạn: vẫn dùng kiểm tra ở trình duyệt */ });
    }
    /** Gắn chip thời tiết vào đầu từng ngày đã vẽ (dữ liệu máy chủ về sau khi lịch đã vẽ xong). */
    function paintWeather() {
        allSched().forEach(function (s) {
            var sec = document.getElementById("tpDay" + s.day);
            if (!sec) return;
            var head = sec.querySelector(".tpx-dayhead"), old = head && head.querySelector(".tpx-wx");
            var chip = wxChip(s.day);
            if (old) old.remove();
            if (head && chip) head.insertBefore(chip, head.querySelector(".tpx-daysum"));
        });
    }
    /* ---------------- Resolution Engine: đề xuất -> xem trước -> áp dụng (máy chủ đề xuất, trình duyệt áp dụng rồi tự lưu) ---------------- */
    var RESOLVABLE = { "capacity.overload": 1, "capacity.tight": 1, "time.late": 1, "time.overlap": 1, "time.travel": 1, "hours.closed": 1, "hours.early": 1, "hours.late": 1, "route.long_day": 1, "context.rain": 1, "weather.rain": 1, "weather.storm": 1 };
    function resKey(c) { return c.code + "|" + (c.day || "") + "|" + (c.index == null ? "" : c.index); }
    function loadResolve(c) {
        var k = resKey(c);
        ui.rsv = { key: k, loading: true, data: null };
        renderWarn();
        API.resolve(c).then(function (j) {
            if (!ui.rsv || ui.rsv.key !== k) return;
            ui.rsv = { key: k, loading: false, data: j };
            renderWarn();
        }).catch(function () { if (ui.rsv && ui.rsv.key === k) { ui.rsv = { key: k, loading: false, data: { options: [] }, err: true }; renderWarn(); } });
    }
    /** Áp dụng một phương án: thao tác chỉ tới mục bằng (ngày, vị trí) trên bản đã lưu, nên chỉ chạy khi không còn thay đổi chưa lưu. */
    function applyResolution(o) {
        if (dirty) { say("Đang có thay đổi chưa lưu, đợi lưu xong rồi áp dụng.", true); return; }
        var r = window.TpResolve.apply(items, rain, o.changes, newItem, RAIN_MAX);   // planner-resolve.js: cùng ngữ nghĩa TripResolver::apply
        if (!r.ok) { say("Lịch đã thay đổi so với đề xuất, đang tải lại.", true); ui.rsv = null; loadServerHealth(); return; }
        ui.rsv = null;
        commit();
        say("Đã áp dụng: " + o.label + ".", false, [["Hoàn tác", undoOnce]]);
    }
    function resolveSection(box) {
        if (!cfg.resolveUrl || RO || !ui.srv || dirty || !items.length) return;
        var probs = (ui.srv.checks || []).filter(function (c) { return RESOLVABLE[c.code] && (c.level === "bad" || c.level === "warn" || c.code === "context.rain"); }).slice(0, 6);
        if (!probs.length) return;
        var sec = el("div", "tpx-resolve");
        var h = el("p", "tpx-resolve__h");
        h.appendChild(icon("fa-magic"));
        h.appendChild(el("b", null, "Cách xử lý đề xuất"));
        h.appendChild(el("span", "tp-muted tp-small", " · xem trước rồi mới áp dụng, hoàn tác được"));
        sec.appendChild(h);
        probs.forEach(function (c) {
            var row = el("div", "tpx-resolve__p tpx-check--" + c.level);
            var top = el("div", "tpx-resolve__t");
            top.appendChild(el("span", null, c.text));
            var open = ui.rsv && ui.rsv.key === resKey(c);
            top.appendChild(btn("tp-mini", open ? "Đóng" : "Xem cách xử lý", "Xem cách xử lý: " + c.text, function () { if (open) { ui.rsv = null; renderWarn(); } else loadResolve(c); }));
            row.appendChild(top);
            if (open) {
                if (ui.rsv.loading) row.appendChild(el("p", "tp-muted tp-small", "Đang tính phương án…"));
                else {
                    var opts = (ui.rsv.data && ui.rsv.data.options) || [];
                    if (!opts.length) row.appendChild(el("p", "tp-muted tp-small", ui.rsv.err ? "Chưa tải được đề xuất, thử lại sau." : "Không có phương án tự động cho vấn đề này; bạn có thể sửa tay."));
                    var ul = el("ul", "tpx-resolve__opts");
                    opts.forEach(function (o) {
                        var li = el("li");
                        var t = el("div", "tpx-resolve__o");
                        t.appendChild(el("b", null, o.label));
                        t.appendChild(el("span", "tpx-resolve__ok" + (o.resolves ? "" : " is-partial"), o.resolves ? "giải quyết được" : "chỉ đỡ hơn, chưa hết"));
                        var rs = el("ul", "tpx-resolve__why");
                        (o.reasons && o.reasons.length ? o.reasons : [o.reason]).forEach(function (x) { rs.appendChild(el("li", null, x)); });
                        t.appendChild(rs);
                        (o.newIssues || []).slice(0, 2).forEach(function (n) {
                            t.appendChild(el("small", "tpx-resolve__new" + (n.severity === "hard" ? " is-hard" : ""), "Sau khi áp dụng: " + n.text));
                        });
                        if (o.after) t.appendChild(el("small", "tp-muted", "Cả chuyến sau khi áp dụng: " + o.after.summary + " (" + o.after.score + "/100)"));
                        var fx = el("div", "tpx-resolve__fx");
                        (o.effects || []).forEach(function (e) {
                            var chip = el("span", "tpx-resolve__chip" + (e.afterPct >= 100 ? " is-bad" : e.afterPct < e.beforePct ? " is-good" : ""));
                            var dm = e.afterMin - e.beforeMin, dk = Math.round((e.afterKm - e.beforeKm) * 10) / 10;
                            chip.textContent = "Ngày " + e.day + ": " + e.beforePct + "% → " + e.afterPct + "%"
                                + (dm ? " (" + (dm > 0 ? "+" : "−") + durText(Math.abs(dm)) + ")" : "") + (dk ? " · " + (dk > 0 ? "+" : "−") + Math.abs(dk) + " km" : "");
                            fx.appendChild(chip);
                        });
                        t.appendChild(fx);
                        li.appendChild(t);
                        li.appendChild(btn("tp-btn tp-btn--sm", "Áp dụng", "Áp dụng: " + o.label, function () { applyResolution(o); }));
                        ul.appendChild(li);
                    });
                    row.appendChild(ul);
                }
            }
            sec.appendChild(row);
        });
        box.appendChild(sec);
    }
    var FEAS = {
        feasible_verified: ["is-ok", "fa-check-circle", "Khả thi, đã xác minh tuyến"],
        feasible_with_uncertainty: ["is-mid", "fa-info-circle", "Khả thi, còn số liệu ước tính"],
        needs_review: ["is-err", "fa-exclamation-triangle", "Cần xem lại"],
    };
    /** Một dòng tóm tắt: x/y chặng đã xác minh, bao nhiêu ước tính / không có đường / chưa rõ, kèm kết luận khả thi. */
    function routeQualityLine(q, feas) {
        var L = q.legs || {}, total = 0;
        Object.keys(L).forEach(function (k) { total += +L[k] || 0; });
        var p = el("div", "tpx-rq");
        var f = FEAS[feas];
        if (f) {
            var b = el("span", "tpx-rq__feas " + f[0]);
            b.appendChild(icon(f[1]));
            b.appendChild(el("span", null, f[2]));
            p.appendChild(b);
        }
        if (!total) { p.appendChild(el("span", "tp-muted tp-small", "Chưa có chặng di chuyển nào cần tính.")); return p; }
        var t = el("span", "tpx-rq__t");
        t.appendChild(el("b", null, (L.verified || 0) + "/" + total));
        t.appendChild(document.createTextNode(" chặng đã xác minh trên bản đồ đường bộ"));
        p.appendChild(t);
        [["estimated", "ước tính", "is-est"], ["unreachable", "không có đường", "is-blocked"], ["unknown", "chưa rõ", "is-unknown"]].forEach(function (k) {
            if (L[k[0]]) p.appendChild(el("span", "tpx-rq__pill " + k[2], L[k[0]] + " " + k[1]));
        });
        return p;
    }
    function renderWarn() {
        var hw = health(), box2 = $("tpWarn"), hh = $("tpWarnH"), sec = $("tpHealth");
        var RANK = { bad: 0, warn: 1, info: 2 };
        hw.bad.sort(function (a, b) { return (RANK[a.lvl] || 0) - (RANK[b.lvl] || 0); });   // lỗi (ràng buộc cứng) lên đầu, rồi cảnh báo, thông tin
        var nErr = hw.bad.filter(function (x) { return x.lvl === "bad"; }).length, nWarn = hw.bad.filter(function (x) { return x.lvl === "warn"; }).length;
        var srvOk = ui.srv && items.length && !dirty;
        // lỗi tuyến chỉ máy chủ biết (không có đường / tọa độ hỏng) cũng tính vào màu và biểu tượng của khung
        if (srvOk) (ui.srv.checks || []).forEach(function (c) {
            if (c.code === "route.unreachable") nErr++;
            else if (c.code === "route.unknown") nWarn++;
        });
        var nBad = nErr + nWarn;
        // CHẨN ĐOÁN bằng lời là chính (máy chủ quyết khi đã lưu; đang sửa thì dùng bản xem trước ở trình duyệt); điểm chỉ là thông tin phụ
        var label = !items.length ? "chưa có gì để kiểm tra" : srvOk && ui.srv.summary ? ui.srv.summary.label
            : nErr ? "Cần sửa " + nErr + " lỗi lịch" + (nWarn ? ", " + nWarn + " điểm nên xem lại" : "") : nWarn ? "Chuyến đi khá ổn, " + nWarn + " điểm nên xem lại" : "Chuyến đi hợp lý";
        hh.textContent = "";
        var tb = btn("tpx-health__t", null, null, function () { ui.healthOpen = !ui.healthOpen; renderWarn(); });
        var open = ui.healthOpen == null ? hw.bad.some(function (x) { return x.lvl === "bad"; }) : ui.healthOpen;
        tb.setAttribute("aria-expanded", open ? "true" : "false");
        tb.appendChild(icon(nErr ? "fa-exclamation-triangle" : nBad ? "fa-heartbeat" : "fa-check-circle"));
        tb.appendChild(el("b", null, "Sức khỏe chuyến đi"));
        tb.appendChild(el("span", "tpx-health__sum" + (nErr ? " is-err" : ""), label));
        if (ui.srvLoading && items.length) tb.appendChild(el("span", "tpx-health__busy", "đang kiểm tra…"));
        if (srvOk) {
            var sc = el("span", "tpx-score tpx-score--" + (ui.srv.level || "ok"), ui.srv.score + "/100");
            sc.title = "Điểm sức khỏe theo bản đã lưu (thời gian, tuyến, sức chứa, ngân sách, bối cảnh)";
            tb.appendChild(sc);
        }
        tb.appendChild(icon(open ? "fa-chevron-up" : "fa-chevron-down"));
        hh.appendChild(tb);
        sec.classList.toggle("is-bad", nBad > 0);
        sec.classList.toggle("is-err", nErr > 0);
        box2.textContent = "";
        box2.hidden = !open;
        if (!open) return;
        // HÀNH ĐỘNG nên làm trước (máy chủ đề xuất, bấm để xem cách xử lý có xem trước)
        if (srvOk && cfg.resolveUrl && !RO && (ui.srv.recommended || []).length) {
            var rec = el("div", "tpx-rec");
            rec.appendChild(el("span", "tpx-rec__h", "Nên làm:"));
            ui.srv.recommended.forEach(function (r) {
                rec.appendChild(btn("tp-mini tpx-rec__b", r.label, "Xem cách xử lý: " + r.label, function () {
                    loadResolve(r);
                    setTimeout(function () { var x = box2.querySelector(".tpx-resolve"); if (x) x.scrollIntoView({ block: "nearest", behavior: "smooth" }); }, 400);
                }));
            });
            box2.appendChild(rec);
        }
        var ul = el("ul", "tpx-checks");
        hw.bad.forEach(function (x) {
            var li = el("li", "tpx-check tpx-check--" + x.lvl);
            li.appendChild(icon(x.lvl === "bad" ? "fa-exclamation-triangle" : x.lvl === "info" ? "fa-info-circle" : "fa-exclamation-circle"));
            li.appendChild(el("span", null, x.text));
            if (x.day) li.appendChild(btn("tp-link tp-small", "Xem", "Xem " + (x.day === "x" ? "danh sách chờ" : "ngày " + x.day), function () { gotoDay(x.day); }));
            if (x.tab) li.appendChild(btn("tp-link tp-small", "Xem chi phí", null, function () { switchTab(x.tab); }));
            ul.appendChild(li);
        });
        // kiểm tra bối cảnh chỉ máy chủ có (mùa, nội dung cần lưu ý…), chưa trùng với kiểm tra phía trình duyệt
        var SRV_ONLY = { "hours.unknown": "fa-clock", "capacity.estimated_duration": "fa-hourglass-half", "route.unreachable": "fa-ban", "route.unknown": "fa-question-circle",
            "weather.rain": "fa-cloud-rain", "weather.storm": "fa-bolt", "weather.heat": "fa-temperature-high", "weather.transport": "fa-car", "weather.source": "fa-satellite-dish" };   // chỉ máy chủ biết
        if (ui.srv && !dirty) (ui.srv.checks || []).filter(function (c) { return (c.group === "context" || c.group === "weather" || SRV_ONLY[c.code]) && c.level !== "ok"; }).slice(0, 8).forEach(function (c) {
            var li = el("li", "tpx-check tpx-check--" + (c.level === "bad" ? "bad" : c.level === "info" ? "info" : "warn"));
            li.appendChild(icon(SRV_ONLY[c.code] || "fa-cloud-sun"));
            li.appendChild(el("span", null, c.text));
            if (c.day) li.appendChild(btn("tp-link tp-small", "Xem", "Xem ngày " + c.day, function () { gotoDay(c.day); }));
            ul.appendChild(li);
        });
        if (items.length) hw.ok.forEach(function (t) {
            var li = el("li", "tpx-check tpx-check--ok");
            li.appendChild(icon("fa-check"));
            li.appendChild(el("span", null, t));
            ul.appendChild(li);
        });
        box2.appendChild(ul);
        resolveSection(box2);
        // chất lượng số liệu tuyến của bản đã lưu: bao nhiêu chặng đã xác minh trên bản đồ đường bộ
        if (srvOk && ui.srv.routeQuality) box2.appendChild(routeQualityLine(ui.srv.routeQuality, ui.srv.feasibility));
        // Dấu chân carbon của phần đi lại (ước tính theo phương tiện từng chặng)
        if (items.length && Object.keys(RULES.legModes || {}).length) {
            var co = tripCo2(), ce = el("p", "tpx-check tpx-check--info");
            ce.appendChild(icon("fa-leaf"));
            ce.appendChild(el("span", null, "Dấu chân carbon đi lại ≈ " + co2Text(co) + " CO₂ cho cả nhóm (ước tính theo phương tiện từng chặng; đổi phương tiện ở nút “Phương tiện” trên mỗi đoạn đường)."));
            box2.appendChild(ce);
        }
        // A-10b: độ hợp thời tiết = phần thời gian ngoài trời KHÔNG rơi vào giờ xấu theo dự báo
        if (srvOk && ui.srv.weather && ui.srv.weather.source === "forecast" && ui.srv.weather.fitPct != null) {
            var wf = el("p", "tpx-check tpx-check--" + (ui.srv.weather.fitPct >= 80 ? "ok" : "info"));
            wf.appendChild(icon("fa-cloud-sun"));
            wf.appendChild(el("span", null, "Hợp thời tiết: " + ui.srv.weather.fitPct + "% thời gian hoạt động ngoài trời không trùng giờ mưa, dông hay nắng nóng (dự báo Open-Meteo)."));
            box2.appendChild(wf);
        }
        // nguồn số liệu tuyến minh bạch: OSRM lỗi thì nói rõ đang là ước tính
        var rt = ui.srv && ui.srv.route;
        if (rt && rt.fallback) {
            var fb = el("p", "tpx-check tpx-check--info tpx-fallback");
            fb.appendChild(icon("fa-route"));
            fb.appendChild(el("span", null, "Chưa lấy được đường bộ thật (" + (rt.reason || "OSRM không phản hồi") + "), quãng đường và thời gian đang là ước tính."));
            box2.appendChild(fb);
        } else if (cfg.estimateNote) box2.appendChild(el("p", "tp-muted tp-small", cfg.estimateNote));
    }
    function renderLegend() {
        var lg = $("tpLegend");
        if (!lg) return;
        lg.textContent = "";
        var showAll = !aroundOnMap() && (ui.mapDay === "" || (ui.mapDay === "follow" && ui.curDay == null));
        lg.hidden = !showAll || daysCount() < 2;
        if (lg.hidden) return;
        for (var d = 1; d <= daysCount(); d++) {
            (function (day) {
                var li = el("li");
                var b = btn("tpx-legend__b", null, "Chỉ hiện ngày " + day + " trên bản đồ", function () { ui.mapDay = String(day); $("tpMapDay").value = ui.mapDay; renderMap(); });
                var sw = el("span", "tpx-legend__sw");
                sw.style.background = dayColor(day);
                b.appendChild(sw);
                b.appendChild(el("span", null, "N" + day));
                li.appendChild(b);
                lg.appendChild(li);
            })(d);
        }
    }

    /* ---------------- ngăn "Thêm vào chuyến đi" ---------------- */
    var addLastFocus = null;
    function openAdd(day, tab) {
        if (RO) return;
        if (day !== undefined) ui.target = day;
        if (typeof day === "number" && day !== ui.around.day) { ui.around.day = day; ui.around.data = null; }
        renderTarget();
        var dr = $("tpAddDrawer");
        addLastFocus = document.activeElement;
        dr.hidden = false;
        if ($("tpAddBack")) $("tpAddBack").hidden = false;
        document.documentElement.classList.add("tpx-drawer-open");
        if ($("tpAddOpen")) $("tpAddOpen").setAttribute("aria-expanded", "true");
        setDtab(tab || "find");
        if ((tab || "find") === "find") setTimeout(function () { $("tpQ").focus(); }, 30);
    }
    function closeAdd() {
        var dr = $("tpAddDrawer");
        if (!dr || dr.hidden) return;
        dr.hidden = true;
        if ($("tpAddBack")) $("tpAddBack").hidden = true;
        document.documentElement.classList.remove("tpx-drawer-open");
        if ($("tpAddOpen")) $("tpAddOpen").setAttribute("aria-expanded", "false");
        if (map) map.clearPreview();
        if (ui.dtab === "around") { closeCard(); renderMap(); }
        if (addLastFocus && addLastFocus.focus) addLastFocus.focus();
    }
    function focusAdd() { openAdd(ui.target); }

    function renderSummary() {
        var s = $("tpSummary");
        s.textContent = "";
        var adv = cfg.advice || [];
        if (!adv.length) return;
        s.appendChild(el("h3", null, "Thời điểm đi"));
        var ul = el("ul", "tp-list");
        adv.forEach(function (a) { ul.appendChild(el("li", null, a.dest + ", tháng " + a.month + ": " + a.label + (a.note ? " (" + a.note + ")" : ""))); });
        s.appendChild(ul);
    }
    function renderEstimate(b) {
        if (!REF) return;
        var es = estimates();
        b.appendChild(el("h3", null, "Dự toán tham khảo"));
        var row = el("div", "tp-tierrow");
        var lab = el("label", null, "Mức chi tiêu ");
        var sel = el("select", "no-nice");
        sel.setAttribute("aria-label", "Mức chi tiêu để ước tính");
        ["low", "mid", "high"].forEach(function (k) {
            var o = el("option", null, TIER_LABEL[k]);
            o.value = k;
            if (k === tier()) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener("change", function () { ui.tier = sel.value; renderDays(); renderCostTable(); softUpdate(); });
        lab.appendChild(sel);
        row.appendChild(lab);
        if (es.items.length) {
            row.appendChild(btn("tp-mini", "Điền giá ước tính vào " + es.items.length + " mục chưa có giá", "Điền giá ước tính vào các mục chưa có giá", function () {
                es.items.forEach(function (x) { x.it.cost = Math.round(x.est / 1000) * 1000; x.it.costType = "estimate"; });
                commit();
            }));
        }
        b.appendChild(row);
        if (!es.items.length && !es.gaps.length) { b.appendChild(el("p", "tp-muted tp-small", "Mọi khoản chính đã có giá.")); return; }
        var t = el("table", "tp-table");
        es.items.forEach(function (x) {
            var tr = el("tr");
            tr.appendChild(el("td", null, x.it.name));
            tr.appendChild(el("td", "tp-num", "≈ " + money(x.est)));
            t.appendChild(tr);
        });
        es.gaps.forEach(function (x) {
            var tr = el("tr", "tp-gap");
            tr.appendChild(el("td", null, x.label));
            tr.appendChild(el("td", "tp-num", "≈ " + money(x.amount)));
            t.appendChild(tr);
        });
        b.appendChild(t);
        b.appendChild(el("p", "tp-muted tp-small", "Giá tham khảo theo mức “" + TIER_LABEL[tier()] + "” do nhóm đặt sẵn (ăn uống, trải nghiệm tính theo số người; lưu trú theo số phòng và số đêm). Không phải giá thị trường chính thức."));
    }
    /* ---------------- ngân sách ---------------- */
    var CAT = [["lodging", "fa-bed", "Lưu trú"], ["meal", "fa-utensils", "Ăn uống"], ["transport", "fa-bus", "Di chuyển"], ["fun", "fa-hiking", "Trải nghiệm & tour"], ["sight", "fa-landmark", "Tham quan"], ["other", "fa-ellipsis-h", "Khác"]];
    function catOf(it) {
        var k = kindOf(it);
        if (k === "lodging" || k === "meal" || k === "transport") return k;
        if (it.type === "experience" || it.type === "tour") return "fun";
        if (it.type === "place" || it.type === "destination" || it.type === "event") return "sight";
        return "other";
    }
    /** Tổng hợp chi phí: dự kiến (giá đã nhập + ước tính cho mục chưa có giá + khoản còn thiếu), đã chi thật, theo nhóm, theo ngày. */
    function budget() {
        var es = estimates(), o = { planned: 0, estFilled: 0, actual: 0, est: es.total, gaps: es.gaps, byCat: {}, byDay: {}, noPrice: [] };
        CAT.forEach(function (c) { o.byCat[c[0]] = 0; });
        items.forEach(function (it) {
            var v = it.cost != null ? it.cost : (estItem(it) || 0);
            if (it.cost != null) { if (it.costType === "actual") o.actual += it.cost; else if (it.costType === "estimate") o.estFilled += it.cost; else o.planned += it.cost; }
            else if (!estItem(it) && (it.type === "experience" || it.type === "tour" || CUSTOM[it.type])) o.noPrice.push(it);
            o.byCat[catOf(it)] += v;
            var dk = it.day == null ? "x" : String(it.day);
            o.byDay[dk] = (o.byDay[dk] || 0) + v;
        });
        es.gaps.forEach(function (g) {
            var c = /Lưu trú/.test(g.label) ? "lodging" : /Ăn uống/.test(g.label) ? "meal" : /Đi lại/.test(g.label) ? "transport" : "other";
            o.byCat[c] += g.amount;
        });
        o.total = o.planned + o.estFilled + o.actual + es.total;
        return o;
    }
    function hbar(label, ic, value, max, cls, note) {
        var row = el("div", "tpx-hbar" + (cls ? " " + cls : ""));
        var lb = el("span", "tpx-hbar__l");
        if (ic) lb.appendChild(icon(ic));
        lb.appendChild(el("span", null, label));
        row.appendChild(lb);
        var tr = el("span", "tpx-hbar__t");
        var f = el("span", "tpx-hbar__f");
        f.style.width = (max > 0 ? Math.max(value > 0 ? 2 : 0, Math.min(100, value / max * 100)) : 0) + "%";
        tr.appendChild(f);
        row.appendChild(tr);
        row.appendChild(el("b", "tpx-hbar__v", value ? money(value) : "—"));
        row.title = label + ": " + money(value) + (note ? " · " + note : "");
        return row;
    }
    function renderCostSummary() {
        var c = $("tpCostSum");
        if (!c) return;
        c.textContent = "";
        var m = meta(), b = budget(), all = b.total;
        var grid = el("div", "tpx-btiles");
        function tile(label, val, sub, cls) {
            var d = el("div", "tpx-btile" + (cls ? " " + cls : ""));
            d.appendChild(el("span", null, label));
            d.appendChild(el("b", null, val));
            if (sub) d.appendChild(el("small", null, sub));
            grid.appendChild(d);
        }
        tile("Ngân sách", m.budget ? money(m.budget) : "Chưa đặt", m.budget ? money(m.budget / m.group) + "/người" : "Đặt ở “Thông tin & chia sẻ”");
        tile("Dự kiến", (b.est || b.estFilled ? "≈ " : "") + money(all), money(all / m.group) + "/người", "is-total");
        tile("Đã chi thật", money(b.actual), b.actual ? "từ mục đánh dấu “Đã chi”" : "Chưa ghi khoản đã chi");
        if (m.budget) tile(all > m.budget ? "Vượt" : "Còn lại", money(Math.abs(m.budget - all)), Math.round(all / m.budget * 100) + "% ngân sách", all > m.budget ? "is-over" : "is-ok");
        c.appendChild(grid);
        if (m.budget > 0) {
            var bar = el("div", "tp-bar-track tpx-bbar");
            var fill = el("div", "tp-bar-fill" + (all > m.budget ? " is-over" : ""));
            fill.style.width = Math.min(100, Math.round(all / m.budget * 100)) + "%";
            bar.appendChild(fill);
            bar.setAttribute("role", "img");
            bar.setAttribute("aria-label", "Dự kiến dùng " + Math.round(all / m.budget * 100) + "% ngân sách");
            c.appendChild(bar);
            if (all > m.budget) {
                var w = el("p", "tpx-issue tpx-issue--bad");
                w.appendChild(icon("fa-exclamation-triangle"));
                w.appendChild(el("span", null, "Dự kiến vượt ngân sách " + money(all - m.budget) + ". Xem nhóm chi lớn nhất bên dưới, hoặc đổi mức chi tiêu sang “Tiết kiệm”."));
                c.appendChild(w);
            }
        }
        var legend = el("p", "tp-muted tp-small tpx-blegend");
        [["is-known", money(b.planned), "tự nhập"], ["is-est", "≈ " + money(b.est + b.estFilled), "ước tính theo giá tham khảo"], ["is-actual", money(b.actual), "đã chi thật"]].forEach(function (x) {
            var s = el("span", "tpx-cost " + x[0], x[1]);
            legend.appendChild(s);
            legend.appendChild(document.createTextNode(" " + x[2] + "  "));
        });
        c.appendChild(legend);
    }
    function costTypeSelect(it) {
        var s = el("select", "no-nice tpx-ctype");
        s.setAttribute("aria-label", "Loại chi phí của " + it.name);
        [["planned", "Dự kiến"], ["actual", "Đã chi"], ["estimate", "Ước tính"]].forEach(function (o) {
            var op = el("option", null, o[1]);
            op.value = o[0];
            s.appendChild(op);
        });
        s.value = it.costType || "planned";
        s.disabled = it.cost == null;
        s.addEventListener("change", function () { it.costType = s.value === "planned" ? null : s.value; commit(true); renderCostTable(); });
        return s;
    }
    function renderCostTable() {
        var b0 = $("tpCostBox");
        b0.textContent = "";
        b0.appendChild(el("h2", "tpx-costh", "Ngân sách chuyến đi"));
        b0.appendChild(el("div", "tpx-costsum")).id = "tpCostSum";
        var bd = budget(), m = meta();

        // theo nhóm
        var sec1 = el("section", "tpx-bsec");
        sec1.appendChild(el("h3", null, "Theo nhóm chi"));
        var maxC = Math.max.apply(null, CAT.map(function (c) { return bd.byCat[c[0]]; }).concat([1]));
        CAT.forEach(function (c) { if (bd.byCat[c[0]] || c[0] !== "other") sec1.appendChild(hbar(c[2], c[1], bd.byCat[c[0]], maxC)); });
        b0.appendChild(sec1);

        // theo ngày, so với ngân sách chia đều
        var sec2 = el("section", "tpx-bsec");
        sec2.appendChild(el("h3", null, "Theo ngày"));
        var perDay = m.budget ? m.budget / m.days : null;
        if (perDay) sec2.appendChild(el("p", "tp-muted tp-small", "Vạch đứng: ngân sách chia đều " + money(perDay) + "/ngày. Chi phí chung (lưu trú, ăn uống chưa lên kế hoạch) chỉ tính ở nhóm chi, không chia vào ngày."));
        var vals = [];
        for (var d = 1; d <= daysCount(); d++) vals.push(bd.byDay[String(d)] || 0);
        var maxD = Math.max.apply(null, vals.concat([perDay || 0, 1]));
        var nz = vals.filter(function (v) { return v > 0; }), avg = nz.length ? nz.reduce(function (a, b) { return a + b; }, 0) / nz.length : 0;
        if (nz.length >= 2) sec2.appendChild(el("p", "tp-muted tp-small", "Trung bình " + money(avg) + "/ngày (các ngày đã có chi phí)."));
        vals.forEach(function (v, i) {
            var over = perDay && v > perDay, hi = nz.length >= 2 && avg > 0 && v > avg * 1.4;
            var row = hbar("Ngày " + (i + 1) + (dayDate(i + 1) ? " · " + dayDate(i + 1) : ""), null, v, maxD, over ? "is-over" : "", over ? "cao hơn mức chia đều" : "");
            if (perDay) {
                var mk = el("i", "tpx-hbar__mark");
                mk.style.left = Math.min(100, perDay / maxD * 100) + "%";
                row.querySelector(".tpx-hbar__t").appendChild(mk);
            }
            if (over) { var w = icon("fa-exclamation-circle tpx-hbar__warn"); w.title = "Cao hơn ngân sách chia đều"; row.appendChild(w); }
            if (hi) row.appendChild(el("small", "tpx-hbar__note", "cao hơn trung bình " + Math.round((v / avg - 1) * 100) + "%"));
            row.addEventListener("click", function () { gotoDay(i + 1); });
            row.classList.add("is-link");
            sec2.appendChild(row);
        });
        if (bd.byDay.x) sec2.appendChild(hbar("Đã lưu (chưa xếp ngày)", null, bd.byDay.x, maxD));
        b0.appendChild(sec2);

        // chưa có giá
        if (bd.noPrice.length && !RO) {
            var sec3 = el("section", "tpx-bsec");
            sec3.appendChild(el("h3", null, "Chưa có giá (" + bd.noPrice.length + ")"));
            sec3.appendChild(el("p", "tp-muted tp-small", "Các mục này không có giá tham khảo. Nhập giá để dự kiến chính xác hơn."));
            var ul = el("ul", "tpx-noprice");
            bd.noPrice.forEach(function (it) {
                var li = el("li");
                li.appendChild(el("span", null, (it.day ? "N" + it.day + " · " : "") + it.name));
                li.appendChild(costInput(it));
                ul.appendChild(li);
            });
            sec3.appendChild(ul);
            b0.appendChild(sec3);
        }

        // dự toán tham khảo
        renderEstimate(b0);

        // từng mục
        if (items.length) {
            b0.appendChild(el("h3", null, "Từng mục"));
            var t3 = el("table", "tp-table tp-table--items");
            var ordered = [];
            for (var dd = 1; dd <= daysCount(); dd++) ordered = ordered.concat(dayItems(dd));
            ordered = ordered.concat(dayItems(null));
            ordered.forEach(function (it) {
                var tr4 = el("tr");
                tr4.appendChild(el("td", null, it.day == null ? "—" : "N" + it.day));
                tr4.appendChild(el("td", null, it.name));
                var td0 = el("td");
                var cat = CAT.filter(function (c) { return c[0] === catOf(it); })[0];
                td0.appendChild(el("span", "tp-small tp-muted", cat ? cat[2] : ""));
                tr4.appendChild(td0);
                var td = el("td", "tp-num");
                td.appendChild(costInput(it));
                tr4.appendChild(td);
                var td2 = el("td");
                if (!RO) td2.appendChild(costTypeSelect(it));
                tr4.appendChild(td2);
                t3.appendChild(tr4);
            });
            b0.appendChild(t3);
            b0.appendChild(el("p", "tp-muted tp-small", "Giá tour đã nhân với số người. “Đã chi” dùng để ghi số tiền thật sau khi trả; “Ước tính” là giá điền từ bảng giá tham khảo."));
        }
        renderCostSummary();
    }

    /* ---------------- Thêm vào chuyến: một ô tìm duy nhất ---------------- */

    function newItem(o) {
        return {
            uid: ++seq, type: o.type, refId: o.refId == null ? null : o.refId, name: o.name, sub: o.sub || "",
            url: o.url || (CUSTOM[o.type] && o.lat != null && o.lng != null ? G.mapsUrl(o.lat, o.lng) : null),
            day: o.day == null ? null : o.day, note: o.note || "", cost: o.cost == null ? null : +o.cost, slot: null, reasons: [],
            lat: o.lat == null ? null : o.lat, lng: o.lng == null ? null : o.lng, when: null,
            source: CUSTOM[o.type] && o.lat != null ? (o.source || "map") : null,
            startMin: null, durationMin: null, defDur: o.defDur || null, image: o.image || null, locked: false, outdoor: o.outdoor == null ? null : o.outdoor,
        };
    }
    function addItem(o, label) {
        if (items.length >= LIMITS.maxItems) { say("Chuyến đi đã đạt tối đa " + LIMITS.maxItems + " mục.", true); return false; }
        if (!CUSTOM[o.type] && haveMap()[o.type + ":" + o.refId]) return false;
        var auto = o.day === "auto";
        if (auto) o.day = autoDay(o);
        items.push(newItem(o));
        commit();
        say("Đã thêm “" + (label || o.name) + "” vào " + targetLabel(o.day) + (auto ? " (ngày hợp nhất)" : "") + ".");
        return true;
    }
    /** "Thêm vào": chip chọn danh sách chờ / từng ngày / để Travela tự chọn ngày hợp (ô select ẩn giữ cùng giá trị). */
    function renderTarget() {
        var s = $("tpTarget"), chips = $("tpTargetChips");
        if (ui.target != null && ui.target !== "auto" && ui.target > daysCount()) ui.target = daysCount();
        s.textContent = "";
        var opts = [["", "Đã lưu"]];
        for (var d = 1; d <= daysCount(); d++) opts.push([String(d), "Ngày " + d]);
        opts.push(["auto", "Tự chọn ngày hợp"]);
        opts.forEach(function (x) { var o = el("option", null, x[1]); o.value = x[0]; s.appendChild(o); });
        var cur = ui.target == null ? "" : String(ui.target);
        s.value = cur;
        if (!chips) return;
        chips.textContent = "";
        opts.forEach(function (x) {
            var on = x[0] === cur;
            var b = btn("tpx-tchip" + (on ? " is-on" : "") + (x[0] === "auto" ? " tpx-tchip--auto" : ""), null, null, function () {
                ui.target = x[0] === "" ? null : x[0] === "auto" ? "auto" : +x[0];
                renderTarget();
            });
            b.setAttribute("role", "radio");
            b.setAttribute("aria-checked", on ? "true" : "false");
            if (x[0] === "auto") { b.appendChild(icon("fa-magic")); b.title = "Xếp vào ngày có điểm gần nhất và chưa quá dày"; }
            else if (x[0] !== "") b.style.setProperty("--c", dayColor(+x[0]));
            b.appendChild(el("span", null, x[1]));
            chips.appendChild(b);
        });
    }
    function resultList(list, emptyText) {
        var wrap = el("div");
        if (!list.length) { wrap.appendChild(el("p", "tp-muted", emptyText)); return wrap; }
        var have = haveMap();
        var ul = el("ul", "tp-list tp-sugg");
        list.forEach(function (s) {
            var li = el("li");
            var href = safeUrl(s.url);
            if (href) {
                var a = el("a", null, s.name);
                a.href = href;
                a.target = "_blank";
                a.rel = "noopener";
                li.appendChild(a);
            } else {
                li.appendChild(el("span", null, s.name));
            }
            li.appendChild(el("span", "tp-type", TYPE[s.type] || ""));
            var added = !!have[s.type + ":" + s.refId];
            var add = btn("tp-mini", added ? "Đã có" : "+ Thêm", (added ? "Đã có " : "Thêm ") + s.name + " vào chuyến đi", function () {
                if (addItem({ type: s.type, refId: s.refId, name: s.name, sub: s.sub, url: s.url, cost: s.cost, lat: s.lat, lng: s.lng, day: ui.target })) { renderResults(); renderSugg(); }
            });
            add.disabled = added;
            li.appendChild(add);
            var sub = [s.sub, s.destName].filter(Boolean).join(" · ");
            if (sub) li.appendChild(el("small", null, sub));
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }
    /** Các địa điểm đã thêm từ tìm kiếm trên bản đồ (để nút hiện "Đã thêm"). */
    function haveExtMap() {
        var h = {};
        items.forEach(function (x) { if (CUSTOM[x.type] && x.source === "search" && G.hasPos(x)) h[x.lat + "," + x.lng] = true; });
        return h;
    }
    /** Đặt ghim xem trước (vàng) trên bản đồ khi rê chuột vào một kết quả. */
    function previewPin(lat, lng, name) {
        if (!map) return;
        map.preview(lat, lng, name);
        map.view(lat, lng, Math.max(map.zoom(), 14));
    }
    function extList(list) {
        var have = haveExtMap();
        var ul = el("ul", "tp-list tp-sugg tp-ext");
        list.forEach(function (r) {
            var li = el("li");
            var main = el("span", "tp-ext__main");
            main.appendChild(el("b", null, r.name));
            if (r.kind) main.appendChild(el("span", "tp-type", r.kind));
            li.appendChild(main);
            var added = !!have[r.key];
            var add = btn("tp-mini", added ? "Đã thêm" : "+ Thêm", (added ? "Đã thêm " : "Thêm ") + r.name + " vào chuyến đi", function () {
                addItem({ type: "note", refId: null, name: r.name, note: r.address || "", lat: r.lat, lng: r.lng, source: "search", sub: r.kind || "", day: ui.target });
            });
            add.disabled = added;
            li.appendChild(add);
            if (r.address) li.appendChild(el("small", null, r.address));
            li.addEventListener("mouseenter", function () { previewPin(r.lat, r.lng, r.name); });
            li.addEventListener("mouseleave", function () { renderDraft(); });
            ul.appendChild(li);
        });
        return ul;
    }
    function group(title, body) {
        var d = el("div", "tp-res-group");
        d.appendChild(el("h4", null, title));
        d.appendChild(body);
        return d;
    }
    function openManual(q) {
        var m = $("tpManual");
        m.open = true;
        if (q != null) $("tpCustomLabel").value = q;
        try { m.scrollIntoView({ block: "center", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
        $("tpCustomLabel").focus();
    }
    /* Lọc kết quả tìm theo loại (máy chủ lọc qua ?type=) + lối tắt "gần tuyến" */
    var Q_TYPES = [["", "Tất cả", "fa-th-large"], ["place", "Địa điểm", "fa-landmark"], ["food", "Ăn uống", "fa-utensils"], ["experience", "Trải nghiệm", "fa-hiking"],
        ["event", "Lễ hội", "fa-calendar-alt"], ["tour", "Tour", "fa-suitcase-rolling"], ["destination", "Điểm đến", "fa-map-marker-alt"]];
    function typeFilterRow(s) {
        var row = el("div", "tpx-qtypes");
        row.setAttribute("role", "tablist");
        Q_TYPES.forEach(function (t) {
            var on = (ui.qType || "") === t[0];
            var b = ibtn("tp-chip tpx-qtype" + (on ? " is-on" : ""), t[2], t[1], "Chỉ hiện " + t[1].toLowerCase(), function () {
                if (on) return;
                ui.qType = t[0];
                runNameSearch(s.q, ++ui.searchSeq);
            });
            b.setAttribute("role", "tab");
            b.setAttribute("aria-selected", on ? "true" : "false");
            row.appendChild(b);
        });
        var d = typeof ui.curDay === "number" ? ui.curDay : (typeof ui.target === "number" ? ui.target : null);
        if (cfg.aroundUrl && d && routePts(d).length) {
            row.appendChild(ibtn("tp-chip tpx-qtype tpx-qtype--around", "fa-route", "Gần tuyến ngày " + d, "Tìm điểm dừng quanh tuyến ngày " + d, function () {
                if (d !== ui.around.day) { ui.around.day = d; ui.around.data = null; }
                setDtab("around");
            }));
        }
        return row;
    }
    function renderResults() {
        var r = $("tpResults"), s = ui.res;
        if (ui.br && ui.br.built) renderBrowseList();
        r.textContent = "";
        r.hidden = !s;
        if (!s) return;
        r.appendChild(typeFilterRow(s));
        r.appendChild(group("Có trên Travela", s.kho === null ? el("p", "tp-muted", "Đang tìm…") : resultList(s.kho, "Chưa có nội dung phù hợp trên Travela.")));
        if (cfg.searchExtUrl && (!ui.qType || ui.qType === "place")) {   // bản đồ OSM chỉ có địa điểm
            var body;
            if (s.ext === null) body = el("p", "tp-muted", "Đang tìm trên bản đồ…");
            else if (!s.ext.ok) body = el("p", "tp-muted", "Chưa tìm được trên bản đồ lúc này. Bạn vẫn thêm được bằng tên ở bên dưới.");
            else if (!s.ext.list.length) body = el("p", "tp-muted", "Không thấy địa điểm nào trên bản đồ. Thử viết có dấu, thêm tên thành phố hoặc dùng tên khác.");
            else body = extList(s.ext.list);
            r.appendChild(group("Địa điểm trên bản đồ (OpenStreetMap)", body));
        }
        var foot = el("div", "tp-res-foot");
        foot.appendChild(el("span", "tp-muted tp-small", "Không đúng ý?"));
        foot.appendChild(btn("tp-mini", "+ Thêm “" + s.q + "” như một ghi chú", "Thêm " + s.q + " như một ghi chú, chưa có vị trí", function () { addItem({ type: "note", refId: null, name: s.q, day: ui.target }); }));
        foot.appendChild(btn("tp-mini", "Tự nhập chi phí, vị trí...", "Mở biểu mẫu tự nhập", function () { openManual(s.q); }));
        r.appendChild(foot);
    }
    /** Điểm làm trung tâm khi tìm trên bản đồ: điểm đến chính, không có thì trung bình các mục đã có vị trí. */
    function biasCenter() {
        var d = meta().dest;
        if (d) {
            var x = (cfg.dests || []).filter(function (z) { return z.id === d; })[0];
            if (x && x.lat != null && x.lng != null) return { lat: x.lat, lng: x.lng };
        }
        var pts = items.filter(G.hasPos);
        if (!pts.length) return null;
        var la = 0, ln = 0;
        pts.forEach(function (p) { la += p.lat; ln += p.lng; });
        return { lat: Math.round(la / pts.length * 1e4) / 1e4, lng: Math.round(ln / pts.length * 1e4) / 1e4 };
    }
    function runNameSearch(q, mine) {
        var enc = encodeURIComponent(q);
        ui.res = { q: q, kho: null, ext: cfg.searchExtUrl ? null : { ok: true, list: [] } };
        renderResults();
        var main = meta().dest;
        fetchJson(cfg.searchUrl + "?q=" + enc + (main && $("tpDestOnly").checked ? "&dest=" + main : "") + (ui.qType ? "&type=" + ui.qType : ""))
            .then(function (j) { if (mine === ui.searchSeq) { ui.res.kho = j.results || []; renderResults(); } })
            .catch(function () { if (mine === ui.searchSeq) { ui.res.kho = []; renderResults(); } });
        if (cfg.searchExtUrl && (!ui.qType || ui.qType === "place")) {
            var c = biasCenter();
            fetchJson(cfg.searchExtUrl + "?q=" + enc + (c ? "&lat=" + c.lat + "&lng=" + c.lng : ""))
                .then(function (j) { if (mine === ui.searchSeq) { ui.res.ext = { ok: j.ok !== false, list: j.results || [] }; renderResults(); } })
                .catch(function () { if (mine === ui.searchSeq) { ui.res.ext = { ok: false, list: [] }; renderResults(); } });
        }
    }
    function runSearch() {
        var q = $("tpQ").value.trim();
        if (q.length < 2) { ui.searchSeq++; ui.res = null; ui.br = null; renderResults(); renderBrowse(); return; }
        var mine = ++ui.searchSeq;
        if (!cfg.browseUrl) { ui.br = null; renderBrowse(); runNameSearch(q, mine); return; }
        showSearching(q);
        // Hiểu câu tìm trước: "nhà hàng chay giá rẻ" -> tìm theo tiêu chí; "Hồ Gươm", "phở Thìn" -> tìm theo tên như cũ.
        // Lượt 1 (osm=0) chỉ đọc dữ liệu có sẵn nên trả ngay; nếu còn thiếu dữ liệu OpenStreetMap thì lượt 2 tải thêm (brMore).
        fetchJson(cfg.browseUrl + "?q=" + encodeURIComponent(q) + brPos() + "&osm=0")
            .then(function (j) {
                if (mine !== ui.searchSeq) return;
                if (j && j.browse && j.filters) {
                    ui.res = null;
                    ui.br = { q: q, intent: j.intent || {}, f: brFromServer(j.filters), data: j.browse, loading: false, seq: 0, built: false };
                    renderResults();
                    renderBrowse();
                    brMore(ui.br);
                } else {
                    ui.br = null;
                    renderBrowse();
                    runNameSearch(q, mine);
                }
            })
            .catch(function () { if (mine === ui.searchSeq) { ui.br = null; renderBrowse(); runNameSearch(q, mine); } });
    }

    /* ---------------- tìm theo tiêu chí (loại, mức giá, khoảng cách, ăn chay, trong nhà/ngoài trời...) ---------------- */
    var BR_CATS = [["", "Mọi loại"], ["hotel", "Khách sạn"], ["restaurant", "Nhà hàng"], ["cafe", "Quán cà phê"], ["bar", "Quán bar"], ["market", "Chợ, mua sắm"], ["sight", "Tham quan"], ["experience", "Trải nghiệm"], ["tour", "Tour"]];
    var BR_LEVELS = [[1, "₫", "Rẻ"], [2, "₫₫", "Vừa"], [3, "₫₫₫", "Cao"], [4, "₫₫₫₫", "Sang"]];
    var BR_CUISINE = [["", "Món bất kỳ"], ["seafood", "Hải sản"], ["vietnamese", "Việt"], ["japanese", "Nhật"], ["korean", "Hàn"], ["hotpot", "Lẩu"], ["barbecue", "Nướng"], ["pizza", "Pizza"], ["italian", "Ý"], ["western", "Âu"], ["chinese", "Hoa"], ["thai", "Thái"]];
    var BR_KM = [1, 2, 3, 5, 10, 20];
    var brTimer = null;

    function brFromServer(f) {
        return {
            cat: f.cat || "", kinds: f.kinds || [], levels: f.levels || null, minPrice: f.minPrice, maxPrice: f.maxPrice, free: !!f.free, veg: !!f.veg,
            cuisine: f.cuisine || "", indoor: f.indoor === null || f.indoor === undefined ? "" : (f.indoor ? "1" : "0"), stars: f.stars || "",
            km: f.radiusKm || 3, sort: f.sort || "", unknown: !!f.includeUnknown, from: "dest",
        };
    }
    function brAnchor(f) {
        if (f && f.from && f.from !== "dest") {
            var it = items.filter(function (x) { return String(x.uid) === String(f.from); })[0];
            if (it && G.hasPos(it)) return { lat: it.lat, lng: it.lng };
        }
        return biasCenter();
    }
    function brPos() {
        var c = biasCenter(), d = meta().dest;
        return (c ? "&lat=" + c.lat + "&lng=" + c.lng : "") + (d ? "&dest=" + d : "");
    }
    function brQuery(b) {
        var f = b.f, p = ["q=" + encodeURIComponent(b.q), "f=1"], c = brAnchor(f), d = meta().dest;
        if (f.cat) p.push("cat=" + f.cat);
        if (f.kinds && f.kinds.length) p.push("kinds=" + f.kinds.join(","));
        if (f.levels) p.push("levels=" + f.levels[0] + "-" + f.levels[1]);
        if (f.minPrice != null && f.minPrice !== "") p.push("min=" + f.minPrice);
        if (f.maxPrice != null && f.maxPrice !== "") p.push("max=" + f.maxPrice);
        if (f.free) p.push("free=1");
        if (f.veg) p.push("veg=1");
        if (f.cuisine) p.push("cuisine=" + f.cuisine);
        if (f.indoor !== "") p.push("indoor=" + f.indoor);
        if (f.stars) p.push("stars=" + f.stars);
        if (f.km) p.push("km=" + f.km);
        if (f.sort) p.push("sort=" + f.sort);
        if (f.unknown) p.push("unknown=1");
        if (c) p.push("lat=" + c.lat, "lng=" + c.lng);
        if (d) p.push("dest=" + d);
        return cfg.browseUrl + "?" + p.join("&");
    }
    /** Đổi một điều kiện lọc rồi tải lại sau một nhịp ngắn (gõ số tiền không gọi mỗi phím). */
    function brChange(mut, delay) {
        var b = ui.br;
        if (!b) return;
        mut(b.f);
        syncBrPanel();
        clearTimeout(brTimer);
        brTimer = setTimeout(function () { brFetch(); }, delay == null ? 120 : delay);
    }
    function brFetch() {
        var b = ui.br;
        if (!b) return;
        var mine = ++b.seq;
        b.loading = true;
        b.more = false;
        renderBrowseList();
        fetchJson(brQuery(b) + "&osm=0")
            .then(function (j) { if (ui.br === b && mine === b.seq) { b.loading = false; b.data = j.browse || b.data; b.failed = false; renderBrowseList(); brMore(b); } })
            .catch(function () { if (ui.br === b && mine === b.seq) { b.loading = false; b.failed = true; renderBrowseList(); } });
    }
    /** Lượt 2: bổ sung dữ liệu OpenStreetMap (máy chủ có hạn thời gian ngắn và tự ngắt khi dịch vụ ngoài lỗi). */
    function brMore(b) {
        if (!b || !b.data || !b.data.osmPending) return;
        var mine = b.seq;
        b.more = true;
        renderBrowseList();
        fetchJson(brQuery(b) + "&osm=1")
            .then(function (j) { if (ui.br === b && mine === b.seq) { b.more = false; if (j && j.browse) b.data = j.browse; renderBrowseList(); } })
            .catch(function () { if (ui.br === b && mine === b.seq) { b.more = false; b.data.note = "Chưa tải thêm được từ OpenStreetMap lúc này, đang hiện các mục có sẵn."; renderBrowseList(); } });
    }
    /** Hiện ngay trạng thái "đang tìm" khi vừa bấm, để người dùng biết yêu cầu đã nhận. */
    function showSearching(q) {
        var r = $("tpResults");
        if (!r || ui.br) return;
        r.hidden = false;
        r.textContent = "";
        var p = el("p", "tp-muted tp-searching", "Đang tìm “" + q + "”…");
        p.setAttribute("role", "status");
        r.appendChild(p);
    }
    function brNum(v) { var n = parseFloat(String(v).replace(",", ".")); return isNaN(n) || n < 0 ? null : Math.round(n * 1000); }   // ô nhập tính bằng nghìn đồng
    function brField(label, node, cls) {
        var l = el("label", "tp-br__f" + (cls ? " " + cls : ""));
        l.appendChild(el("span", "tp-br__l", label));
        l.appendChild(node);
        return l;
    }
    function brSelect(opts, value, onChange) {
        var s = el("select", "no-nice");
        opts.forEach(function (o) { var op = el("option", null, o[1]); op.value = String(o[0]); s.appendChild(op); });
        s.value = String(value == null ? "" : value);
        s.addEventListener("change", function () { onChange(s.value); });
        return s;
    }
    function brCheck(text, checked, onChange) {
        var l = el("label", "tp-br__ck");
        var c = el("input");
        c.type = "checkbox";
        c.checked = !!checked;
        c.addEventListener("change", function () { onChange(c.checked); });
        l.appendChild(c);
        l.appendChild(document.createTextNode(" " + text));
        return l;
    }
    var brRefs = null;
    function buildBrPanel(b) {
        var f = b.f, p = el("div", "tp-br__panel"), refs = {};
        var head = el("div", "tp-br__head");
        head.appendChild(el("b", null, "Tìm theo tiêu chí"));
        var tags = el("span", "tp-br__tags");
        (b.intent.tags || []).forEach(function (t) { tags.appendChild(el("span", "tp-chip tp-chip--static", t)); });
        if ((b.intent.tags || []).length) head.appendChild(el("span", "tp-muted tp-small", "Đã hiểu:"));
        head.appendChild(tags);
        head.appendChild(btn("tp-mini", "Xóa bộ lọc", "Xóa các bộ lọc", function () { brChange(function (x) { x.kinds = []; x.levels = null; x.minPrice = null; x.maxPrice = null; x.free = false; x.veg = false; x.cuisine = ""; x.indoor = ""; x.stars = ""; x.sort = ""; x.unknown = false; syncBrInputs(); }, 0); }));
        p.appendChild(head);

        var grid = el("div", "tp-br__grid");
        refs.cat = brSelect(BR_CATS, f.cat, function (v) { brChange(function (x) { x.cat = v; x.kinds = []; x.stars = ""; x.cuisine = ""; x.veg = false; }, 0); });
        grid.appendChild(brField("Loại", refs.cat));

        // mức giá: bốn nút, chọn một hoặc nhiều mức liền nhau
        var lv = el("div", "tp-br__levels");
        refs.lv = BR_LEVELS.map(function (L) {
            var bt = btn("tp-chip tp-br__lv", L[1] + " " + L[2], "Mức giá " + L[2].toLowerCase(), function () {
                brChange(function (x) {
                    var lo = x.levels ? x.levels[0] : null, hi = x.levels ? x.levels[1] : null, n = L[0];
                    if (lo === null) { x.levels = [n, n]; }
                    else if (n >= lo && n <= hi) { x.levels = lo === hi ? null : (n === lo ? [lo + 1, hi] : (n === hi ? [lo, hi - 1] : [n, n])); }
                    else { x.levels = [Math.min(lo, n), Math.max(hi, n)]; }
                    x.free = false;
                }, 0);
            });
            bt.setAttribute("aria-pressed", "false");
            lv.appendChild(bt);
            return bt;
        });
        grid.appendChild(brField("Mức giá", lv, "tp-br__f--wide"));

        refs.min = el("input"); refs.min.type = "number"; refs.min.min = "0"; refs.min.step = "50"; refs.min.placeholder = "từ";
        refs.max = el("input"); refs.max.type = "number"; refs.max.min = "0"; refs.max.step = "50"; refs.max.placeholder = "đến";
        refs.min.addEventListener("input", function () { brChange(function (x) { x.minPrice = brNum(refs.min.value); }, 500); });
        refs.max.addEventListener("input", function () { brChange(function (x) { x.maxPrice = brNum(refs.max.value); }, 500); });
        var rng = el("div", "tp-br__range");
        rng.appendChild(refs.min); rng.appendChild(el("span", null, "–")); rng.appendChild(refs.max);
        grid.appendChild(brField("Giá (nghìn đồng)", rng));

        refs.km = brSelect(BR_KM.map(function (k) { return [k, "Trong " + k + " km"]; }), f.km, function (v) { brChange(function (x) { x.km = parseFloat(v); }, 0); });
        grid.appendChild(brField("Khoảng cách", refs.km));

        refs.from = el("select", "no-nice");
        refs.from.addEventListener("change", function () { brChange(function (x) { x.from = refs.from.value; }, 0); });
        grid.appendChild(brField("Tính từ", refs.from));

        refs.stars = brSelect([["", "Mọi hạng"], [2, "2 sao"], [3, "3 sao"], [4, "4 sao"], [5, "5 sao"]], f.stars, function (v) { brChange(function (x) { x.stars = v; }, 0); });
        refs.hotelNodes = [brField("Hạng sao", refs.stars, "tp-br__f--hotel")];
        grid.appendChild(refs.hotelNodes[0]);
        refs.cuisine = brSelect(BR_CUISINE, f.cuisine, function (v) { brChange(function (x) { x.cuisine = v; }, 0); });
        refs.eatNodes = [brField("Món", refs.cuisine, "tp-br__f--eat")];
        grid.appendChild(refs.eatNodes[0]);
        refs.indoor = brSelect([["", "Trong nhà hay ngoài trời"], ["1", "Trong nhà"], ["0", "Ngoài trời"]], f.indoor, function (v) { brChange(function (x) { x.indoor = v; }, 0); });
        grid.appendChild(brField("Không gian", refs.indoor));
        refs.sort = brSelect([["", "Nổi bật trước"], ["near", "Gần nhất"], ["price", "Giá thấp đến cao"]], f.sort, function (v) { brChange(function (x) { x.sort = v; }, 0); });
        grid.appendChild(brField("Sắp xếp", refs.sort));
        // bộ lọc thu gọn mặc định để kết quả hiện ngay dưới ô tìm (nhất là khi bấm nhanh "Cà phê", "Khách sạn")
        var more = el("details", "tp-br__filters");
        more.open = !!ui.brFiltersOpen;
        more.addEventListener("toggle", function () { ui.brFiltersOpen = more.open; });
        more.appendChild(el("summary", null, "Bộ lọc: loại, mức giá, khoảng cách, sắp xếp…"));
        more.appendChild(grid);
        p.appendChild(more);

        var cks = el("div", "tp-br__cks");
        refs.free = brCheck("Miễn phí", f.free, function (v) { brChange(function (x) { x.free = v; if (v) x.levels = null; }, 0); });
        refs.veg = brCheck("Có món chay", f.veg, function (v) { brChange(function (x) { x.veg = v; }, 0); });
        refs.eatNodes.push(refs.veg);
        cks.appendChild(refs.free); cks.appendChild(refs.veg);
        more.appendChild(cks);
        brRefs = refs;
        syncBrInputs();
        return p;
    }
    /** Đặt lại các ô nhập theo trạng thái lọc (sau khi bấm Xóa bộ lọc hoặc khi dựng lần đầu). */
    function syncBrInputs() {
        var b = ui.br, r = brRefs;
        if (!b || !r) return;
        var f = b.f;
        r.cat.value = f.cat; r.stars.value = String(f.stars || ""); r.cuisine.value = f.cuisine || ""; r.indoor.value = f.indoor; r.sort.value = f.sort || "";
        r.km.value = String(f.km);
        r.min.value = f.minPrice != null ? String(f.minPrice / 1000) : ""; r.max.value = f.maxPrice != null ? String(f.maxPrice / 1000) : "";
        r.free.firstChild.checked = !!f.free; r.veg.firstChild.checked = !!f.veg;
        // danh sách "Tính từ": trung tâm điểm đến hoặc một mục đã có vị trí trong chuyến
        r.from.textContent = "";
        var o0 = el("option", null, "Trung tâm điểm đến");
        o0.value = "dest";
        r.from.appendChild(o0);
        items.filter(G.hasPos).slice(0, 30).forEach(function (it) { var o = el("option", null, (it.day ? "Ngày " + it.day + " · " : "") + it.name); o.value = String(it.uid); r.from.appendChild(o); });
        r.from.value = f.from;
        if (r.from.value !== f.from) { f.from = "dest"; r.from.value = "dest"; }
        syncBrPanel();
    }
    function syncBrPanel() {
        var b = ui.br, r = brRefs;
        if (!b || !r) return;
        var f = b.f;
        r.lv.forEach(function (bt, i) {
            var on = !!f.levels && i + 1 >= f.levels[0] && i + 1 <= f.levels[1];
            bt.setAttribute("aria-pressed", on ? "true" : "false");
            bt.classList.toggle("is-on", on);
        });
        var eat = !f.cat || f.cat === "restaurant" || f.cat === "cafe" || f.cat === "bar";
        r.eatNodes.forEach(function (n) { n.hidden = !eat; });
        r.hotelNodes.forEach(function (n) { n.hidden = !(!f.cat || f.cat === "hotel"); });
    }
    function fmtDist(m) { return m < 1000 ? Math.round(m / 10) * 10 + " m" : (Math.round(m / 100) / 10).toLocaleString("vi-VN") + " km"; }
    function priceBadge(r) {
        var lv = r.priceLevel ? "₫₫₫₫".slice(0, r.priceLevel) : "";
        if (r.priceSource === "travela") return { t: (lv ? lv + " · " : "") + (r.priceText || "Giá trên Travela"), cls: "tp-br__p" };
        if (r.priceSource === "stars") return { t: lv + " · ước tính theo hạng sao", cls: "tp-br__p tp-br__p--est" };
        if (r.priceSource === "kind") return { t: lv + " · ước tính theo loại hình", cls: "tp-br__p tp-br__p--est" };
        return { t: r.priceText || "Chưa rõ giá", cls: "tp-br__p tp-br__p--none" };
    }
    var CUISINE_LABEL = {};
    BR_CUISINE.forEach(function (c) { if (c[0]) CUISINE_LABEL[c[0]] = c[1]; });
    function brItem(r) {
        var li = el("li", "tp-br__it");
        var main = el("div", "tp-br__main");
        var href = safeUrl(r.url);
        var nm;
        if (href) { nm = el("a", null, r.name); nm.href = href; nm.target = "_blank"; nm.rel = "noopener"; } else { nm = el("b", null, r.name); }
        main.appendChild(nm);
        var meta1 = el("div", "tp-br__meta");
        if (r.kind) meta1.appendChild(el("span", "tp-type", r.kind));
        if (!r.destLevel) meta1.appendChild(el("span", "tp-br__d", fmtDist(r.distM)));
        if (r.stars) meta1.appendChild(el("span", "tp-br__s", "★".repeat(r.stars) + " " + r.stars + " sao"));
        var pb = priceBadge(r);
        meta1.appendChild(el("span", pb.cls, pb.t));
        main.appendChild(meta1);
        var meta2 = [];
        if (r.veg) meta2.push("Có món chay");
        (r.cuisine || []).slice(0, 3).forEach(function (c) { meta2.push(CUISINE_LABEL[c] || c); });
        if (r.wifi) meta2.push("Wi-Fi");
        if (r.indoor === true) meta2.push("Trong nhà"); else if (r.indoor === false) meta2.push("Ngoài trời");
        if (r.duration) meta2.push(r.duration);
        if (r.hours) meta2.push("Giờ: " + r.hours);
        if (meta2.length) main.appendChild(el("small", null, meta2.join(" · ")));
        if (r.address) main.appendChild(el("small", null, r.address));
        li.appendChild(main);

        var typed = r.type !== "note";
        var added = typed ? !!haveMap()[r.type + ":" + r.refId] : !!haveExtMap()[r.lat + "," + r.lng];
        var add = btn("tp-mini", added ? "Đã có" : "+ Thêm", (added ? "Đã có " : "Thêm ") + r.name + " vào chuyến đi", function () {
            var ok = typed
                ? addItem({ type: r.type, refId: r.refId, name: r.name, sub: r.kind, url: r.url, cost: r.cost, lat: r.lat, lng: r.lng, day: ui.target })
                : addItem({ type: "note", refId: null, name: r.name, note: r.address || "", lat: r.lat, lng: r.lng, source: "search", sub: r.kind || "", day: ui.target });
            if (ok !== false) renderBrowseList();
        });
        add.disabled = added;
        li.appendChild(add);
        if (!r.destLevel && r.lat != null) {
            li.addEventListener("mouseenter", function () { previewPin(r.lat, r.lng, r.name); });
            li.addEventListener("mouseleave", function () { renderDraft(); });
        }
        return li;
    }
    function renderBrowseList() {
        var b = ui.br, box = $("tpBrowseList");
        if (!b || !box) return;
        box.textContent = "";
        var d = b.data || {};
        if (d.widenedKm && b.f.km < d.widenedKm) { b.f.km = d.widenedKm; syncBrInputs(); }   // máy chủ đã tự nới bán kính
        var bar = el("div", "tp-br__bar");
        bar.appendChild(el("b", null, b.loading ? "Đang tìm…" : (d.total || 0) + " kết quả"));
        if (b.more && !b.loading) {
            var mo = el("span", "tp-muted tp-small tp-br__more", "Đang tải thêm từ OpenStreetMap…");
            mo.setAttribute("role", "status");
            bar.appendChild(mo);
        }
        if (!b.loading && d.hidden) {
            bar.appendChild(el("span", "tp-muted tp-small", "Đã ẩn " + d.hidden + " mục chưa có thông tin cho bộ lọc này."));
            bar.appendChild(btn("tp-mini", "Hiện cả mục chưa rõ", "Hiện cả mục chưa có thông tin", function () { brChange(function (x) { x.unknown = true; }, 0); }));
        } else if (b.f.unknown && !b.loading) {
            bar.appendChild(btn("tp-mini", "Ẩn mục chưa rõ", "Ẩn mục chưa có thông tin", function () { brChange(function (x) { x.unknown = false; }, 0); }));
        }
        box.appendChild(bar);
        if (b.failed) box.appendChild(el("p", "tp-muted tp-small", "Chưa tải được kết quả lúc này, thử lại sau."));
        if (d.note && (d.results || []).length) box.appendChild(el("p", "tp-muted tp-small", d.note));   // danh sách rỗng: lời nhắn ở trạng thái trống bên dưới
        // loại con (từ dữ liệu vừa tìm được)
        var kinds = (d.facets && d.facets.kinds) || [];
        if (kinds.length > 1) {
            var kb = el("div", "tp-br__kinds");
            kb.appendChild(btn("tp-chip" + (b.f.kinds.length ? "" : " is-on"), "Tất cả", "Mọi loại", function () { brChange(function (x) { x.kinds = []; }, 0); }));
            kinds.forEach(function (k) {
                var on = b.f.kinds.indexOf(k.key) >= 0;
                kb.appendChild(btn("tp-chip" + (on ? " is-on" : ""), k.label + " (" + k.count + ")", "Chỉ hiện " + k.label, function () { brChange(function (x) { x.kinds = on ? [] : [k.key]; }, 0); }));
            });
            box.appendChild(kb);
        }
        var list = d.results || [];
        if (!list.length && !b.loading && b.more) {
            box.appendChild(el("p", "tp-muted", "Chưa có sẵn trên Travela, đang tìm trên OpenStreetMap…"));
        } else if (!list.length && !b.loading) {
            var em = el("div", "tp-br__empty");
            em.appendChild(el("p", "tp-muted", d.osmOk === false
                ? "Chưa có kết quả: Travela chưa có sẵn mục này ở khu vực này và máy chủ bản đồ chưa trả lời. Bạn có thể tự thêm bằng tên hoặc thử lại sau ít phút."
                : "Không có địa điểm khớp. Thử nới bộ lọc, tăng khoảng cách, hoặc bấm “Hiện cả mục chưa rõ”."));
            if (b.f.km < 10) em.appendChild(btn("tp-mini", "Mở rộng 10 km", "Tìm trong bán kính 10 km", function () { brChange(function (x) { x.km = 10; }, 0); }));
            em.appendChild(btn("tp-mini", "Tự thêm “" + b.q + "”", "Tự nhập tên, vị trí, chi phí", function () { openManual(b.q); }));
            box.appendChild(em);
        } else {
            var ul = el("ul", "tp-list tp-sugg tp-br__list");
            list.forEach(function (r) { ul.appendChild(brItem(r)); });
            box.appendChild(ul);
        }
        var s = d.sources || {};
        var foot = el("details", "tp-br__about");
        foot.appendChild(el("summary", null, "Về dữ liệu và mức giá"));
        foot.appendChild(el("p", "tp-muted tp-small", "Giá chỉ hiện khi nguồn có dữ liệu. Trải nghiệm và tour lấy giá thật từ Travela. Dữ liệu OpenStreetMap không có giá: mức giá của khách sạn được ước tính theo hạng sao, của nhà nghỉ và quán ăn nhanh theo loại hình, và có ghi “ước tính”. Hạng sao, món, ăn chay, Wi-Fi, giờ mở cửa chỉ hiện khi tình nguyện viên OpenStreetMap đã điền."));
        if (s.osm) foot.appendChild(el("p", "tp-muted tp-small", "Dữ liệu địa điểm © OpenStreetMap contributors."));
        box.appendChild(foot);
    }
    function renderBrowse() {
        var host = $("tpBrowse");
        if (!host) return;
        var b = ui.br;
        host.hidden = !b;
        if (!b) { host.textContent = ""; brRefs = null; return; }
        if (!b.built) {
            host.textContent = "";
            host.appendChild(buildBrPanel(b));
            var l = el("div", "tp-br__results");
            l.id = "tpBrowseList";
            host.appendChild(l);
            var ft = el("div", "tp-res-foot");
            ft.appendChild(el("span", "tp-muted tp-small", "Muốn tìm theo tên?"));
            ft.appendChild(btn("tp-mini", "Tìm “" + b.q + "” theo tên", "Tìm theo tên thay vì theo tiêu chí", function () {
                var q = b.q, mine = ++ui.searchSeq;
                ui.br = null;
                renderBrowse();
                runNameSearch(q, mine);
            }));
            host.appendChild(ft);
            b.built = true;
        }
        renderBrowseList();
    }

    function renderSugg() {
        var s = $("tpSugg");
        s.textContent = "";
        var any = false, have = haveMap();
        ["tray", "tour", "experience", "food", "event"].forEach(function (g) {
            var list = (sugg[g] || []).filter(function (x) { return !have[key(x)]; });
            if (!list.length) return;
            any = true;
            s.appendChild(el("h4", null, SUGG_TITLE[g]));
            s.appendChild(resultList(list, ""));
        });
        if (!any) s.appendChild(el("p", "tp-muted", "Chưa có gợi ý. Thêm một điểm đến trước, các món, lễ hội và tour tại đó sẽ hiện ở đây."));
    }
    /** Danh mục tìm nhanh (kiểu Roadtrippers: chọn loại thay vì phải biết tên) và mục thêm nhanh không cần tìm. */
    var CATS = [["fa-utensils", "Nhà hàng", "nhà hàng"], ["fa-coffee", "Cà phê", "quán cà phê"], ["fa-bed", "Khách sạn", "khách sạn"], ["fa-shopping-basket", "Chợ", "chợ"], ["fa-cocktail", "Quán bar", "quán bar"]];
    var QUICK = [["fa-taxi", "Taxi / Grab", "transport"], ["fa-plane", "Vé máy bay / tàu / xe", "transport"]];
    // mục tự thêm gọi đúng tên theo việc người dùng muốn làm (không gọi chung là "mục tùy chỉnh")
    var OWN = [["fa-map-marker-alt", "Địa điểm của tôi", "note", "Tên nơi (ví dụ: Khách sạn Sen Vàng, nhà bạn)"], ["fa-bus", "Di chuyển", "transport", "Ví dụ: Xe khách Hà Nội – Ninh Bình 7:00"],
        ["fa-sticky-note", "Ghi chú", "note", "Ví dụ: Đổi tiền, mua sim, gửi hành lý"]];
    function renderPresets() {
        var p = $("tpPresets");
        p.textContent = "";
        var g1 = el("div", "tpx-cats__row");
        CATS.forEach(function (x) {
            g1.appendChild(ibtn("tp-chip tpx-cat", x[0], x[1], "Tìm " + x[1], function () { $("tpQ").value = x[2]; runSearch(); $("tpQ").focus(); }));
        });
        p.appendChild(g1);
        var g2 = el("div", "tpx-cats__row");
        g2.appendChild(el("span", "tp-muted tp-small", "Thêm nhanh:"));
        QUICK.forEach(function (x) {
            g2.appendChild(ibtn("tp-chip tp-chip--add", x[0], x[1], "Thêm " + x[1] + " vào chuyến đi", function () { addItem({ type: x[2], refId: null, name: x[1], day: ui.target }); }));
        });
        p.appendChild(g2);
        var g3 = el("div", "tpx-cats__row");
        g3.appendChild(el("span", "tp-muted tp-small", "Tự thêm:"));
        OWN.forEach(function (x) {
            g3.appendChild(ibtn("tp-chip tp-chip--own", x[0], "+ " + x[1], "Tự thêm " + x[1].toLowerCase(), function () {
                $("tpCustomKind").value = x[2];
                $("tpCustomLabel").placeholder = x[3];
                openManual("");
            }));
        });
        p.appendChild(g3);
    }
    function renderDestOnly() {
        var d = meta().dest, x = d ? (cfg.dests || []).filter(function (z) { return z.id === d; })[0] : null;
        $("tpDestOnlyBox").hidden = !x;
        if (x) $("tpDestName").textContent = x.name;
    }


    /* ---------------- bản đồ (Google Maps, MapLibre hoặc OpenStreetMap, xem planner-map.js) ---------------- */
    var map = null, routeCache = {};
    function initMap(skipGoogle) {
        var m = $("tpMap");
        if (!m || !window.TpMap) return;
        window.TpMap.create(m, {
            googleMapsKey: cfg.googleMapsKey, mapId: cfg.googleMapId, styleUrl: cfg.mapStyleUrl, skipGoogle: !!skipGoogle,
            onAuthFail: function () { m.textContent = ""; map = null; initMap(true); },   // khóa Google bị từ chối: chuyển sang bản đồ vector / OpenStreetMap
        }).then(function (ad) {
            map = ad;
            if (!map) return;
            map.onClick(onMapPick);
            m.setAttribute("data-map", map.kind);
            renderMap();
            renderDraft();
            // bố cục còn đổi sau khi tải xong (phông chữ, ảnh, thanh menu): đo lại khung bản đồ rồi canh lại các ghim
            setTimeout(renderMap, 0);
            if (document.readyState !== "complete") window.addEventListener("load", function () { renderMap(); });
        });
    }

    /* ---------------- chọn vị trí cho mục tự thêm ---------------- */
    function startPick(uid, cb) {
        if (!map) { say("Bản đồ chưa tải được. Hãy dán link Google Maps hoặc tọa độ.", true); return; }
        ui.pick = { uid: uid == null ? null : uid, cb: cb || null };
        if (window.matchMedia && window.matchMedia("(max-width: 991.98px)").matches) setMapOpen(true);
        map.setPicking(true);
        say("Bấm vào một điểm trên bản đồ để chọn vị trí (Esc để hủy).");
        try { map.container().scrollIntoView({ block: "center", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
    }
    function endPick() {
        ui.pick = null;
        if (map) map.setPicking(false);
    }
    function onMapPick(e) {
        if (!ui.pick) return;
        var uid = ui.pick.uid, cb = ui.pick.cb;
        endPick();
        if (document.documentElement.classList.contains("tpx-map-open")) setMapOpen(false);
        var pos = { lat: Math.round(e.lat * 1e6) / 1e6, lng: Math.round(e.lng * 1e6) / 1e6, source: "map" };
        if (cb) { cb(pos); return; }
        if (uid == null) { setDraft(pos); say("Đã chọn vị trí. Bấm “+ Thêm” để thêm mục."); return; }
        var it = items.filter(function (x) { return x.uid === uid; })[0];
        if (it) { setPos(it, pos); say("Đã đặt vị trí cho “" + it.name + "”. Chưa lưu."); }
    }
    /** Vị trí nháp của mục đang soạn trong biểu mẫu "Thêm mục tự nhập". */
    function setDraft(pos) {
        ui.draft = pos;
        renderDraft();
    }
    function renderDraft() {
        var m = $("tpPosMsg"), c = $("tpPosClear");
        if (map) map.clearPreview();
        if (!ui.draft) { m.textContent = ""; c.hidden = true; return; }
        m.textContent = "Vị trí đã chọn: " + ui.draft.lat + ", " + ui.draft.lng + " (" + (SRC[ui.draft.source] || "") + ")";
        c.hidden = false;
        if (map) {
            map.preview(ui.draft.lat, ui.draft.lng, "Vị trí của mục sắp thêm");
            map.view(ui.draft.lat, ui.draft.lng, Math.max(map.zoom(), 13));
        }
    }
    function drawRoute(pts, color) {
        var line = map.line(pts, color);
        if (!cfg.osrmUrl || pts.length < 2) return;
        var coords = pts.map(function (p) { return p.lng + "," + p.lat; }).join(";");
        var gen = ++mapGen;
        function paint(g) { if (gen !== mapGen || !map) return; map.removeLine(line); line = map.geoLine(g, color); }
        if (routeCache[coords]) { paint(routeCache[coords]); return; }
        fetch(cfg.osrmUrl.replace(/\/$/, "") + "/route/v1/" + (cfg.osrmProfile || "driving") + "/" + coords + "?overview=simplified&geometries=geojson")
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.routes && j.routes[0]) { routeCache[coords] = j.routes[0].geometry; paint(routeCache[coords]); }
            })
            .catch(function () { /* giữ đường thẳng */ });
    }
    var mapGen = 0;
    function focusItem(uid) {
        if (ui.tab !== "plan") switchTab("plan");
        var it = byUid(uid);
        if (it && ui.collapsed[it.day]) { delete ui.collapsed[it.day]; renderDays(); }
        var li = document.querySelector('.tpx-stop[data-uid="' + uid + '"]');
        if (!li) return;
        if (!document.documentElement.classList.contains("tpx-map-open")) li.scrollIntoView({ block: "center", behavior: "smooth" });
        li.classList.add("is-flash");
        setTimeout(function () { li.classList.remove("is-flash"); }, 1600);
    }
    function renderMapDays() {
        var s = $("tpMapDay"), cur = ui.mapDay;
        s.textContent = "";
        [["follow", "Ngày đang xem"], ["", "Toàn chuyến"]].concat(cfg.aroundUrl && !RO ? [["explore", "Khám phá quanh tuyến"]] : []).forEach(function (x) { var o = el("option", null, x[1]); o.value = x[0]; s.appendChild(o); });
        for (var d = 1; d <= daysCount(); d++) {
            var o = el("option", null, "Ngày " + d + (dayDate(d) ? " · " + dayDate(d) : ""));
            o.value = String(d);
            s.appendChild(o);
        }
        var ou = el("option", null, "Đã lưu (chưa xếp)");
        ou.value = "x";
        s.appendChild(ou);
        if (cur !== "" && cur !== "x" && cur !== "follow" && cur !== "explore" && +cur > daysCount()) cur = "follow";
        ui.mapDay = cur;
        s.value = cur;
    }
    /** Ngày đang hiển thị trên bản đồ: "" = cả chuyến, "x" = danh sách chờ, "N" = ngày N. */
    function mapScope() {
        if (aroundOnMap()) return String(ui.around.day);   // đang tìm quanh tuyến: bản đồ chỉ hiện tuyến của ngày đó
        if (ui.mapDay !== "follow") return ui.mapDay;
        return ui.curDay == null ? "" : String(ui.curDay);
    }
    function renderMap() {
        renderLegend();
        renderMapSheet();
        if (!map) return;
        mapGen++;
        map.resize();
        map.clear();
        var all = [], n = daysCount(), scope = mapScope();
        for (var d = 1; d <= n + 1; d++) {
            var day = d <= n ? d : null;
            if (scope !== "" && (day === null ? scope !== "x" : String(day) !== scope)) continue;
            var color = dayColor(day);
            var list = dayItems(day);
            if (ui.opt && ui.opt.day === day) list = ui.opt.order;   // đang xem trước tối ưu: vẽ thứ tự mới
            var pts = list.filter(G.hasPos);
            if (!pts.length) continue;
            if (day !== null) {
                var cxm = dayCtx(day);   // tuyến ngày: từ chỗ ở đêm trước, về chỗ ở đêm nay (cùng cách tính km trong lịch)
                drawRoute((cxm.from && cxm.arrive == null ? [cxm.from] : []).concat(pts, cxm.to ? [cxm.to] : []), color);
            }
            list.forEach(function (p, i) {
                if (!G.hasPos(p)) return;
                map.marker({
                    id: p.uid, lat: p.lat, lng: p.lng, color: color, label: day === null ? "" : (i + 1),
                    title: (day === null ? "Đã lưu · " : "Ngày " + day + " · " + (i + 1) + " ") + p.name,
                    onClick: function () { if (!ui.pick) { openCard(p.uid); focusItem(p.uid); } },
                });
                all.push(p);
            });
        }
        // chỗ ở có vị trí: ghim riêng (không đánh số, không phải mục của ngày)
        var seenStay = {};
        ((cfg.logistics || {}).stays || []).forEach(function (st) {
            if (st.lat == null || st.lng == null || seenStay[st.lat + "," + st.lng]) return;
            seenStay[st.lat + "," + st.lng] = 1;
            map.marker({ id: "stay" + st.id, lat: +st.lat, lng: +st.lng, color: "#1f3b3d", label: "⌂", title: "Chỗ ở · " + st.name, onClick: function () { switchTab("info"); } });
            all.push({ lat: +st.lat, lng: +st.lng });
        });
        if (aroundOnMap()) {
            (ui.around.data.results || []).forEach(function (r) {
                map.marker({ id: "ar" + r.type + r.refId, lat: r.lat, lng: r.lng, color: "#e9a23b", label: "+", title: r.name + " · đi thêm +" + Math.max(1, r.detourMin) + " phút",
                    onClick: function () { openAroundCard(r); } });
                all.push(r);
            });
        }
        map.fit(all, 14);
        if (ui.card) { if (all.indexOf(byUid(ui.card.uid)) >= 0) { renderCard(); map.highlight(ui.card.uid); } else closeCard(); }
    }

    /* ---------------- thẻ chi tiết địa điểm (bấm ghim hoặc ảnh để mở) ---------------- */
    function numberOf(it) {
        if (it.day == null) return "";
        return dayItems(it.day).indexOf(it) + 1;
    }
    function dirUrl(it) { return "https://www.google.com/maps/dir/?api=1&destination=" + it.lat + "," + it.lng; }
    function openCard(uid) {
        ui.card = { uid: uid, tab: "about", g: undefined };
        var it = byUid(uid);
        if (it && map) {
            map.highlight(uid);
            if (G.hasPos(it)) map.view(it.lat, it.lng, Math.max(map.zoom(), 14));
        }
        renderCard();
        if (it && map && map.kind === "google" && G.hasPos(it) && !CUSTOM[it.type]) {
            map.details(it).then(function (g) {
                if (ui.card && ui.card.uid === uid) { ui.card.g = g || null; renderCard(); }
            });
        }
    }
    function closeCard() {
        ui.card = null;
        if (map) map.highlight(null);
        var c = $("tpPlaceCard");
        if (c) { c.hidden = true; c.textContent = ""; }
    }
    function cardNav(dir) {
        var it = byUid(ui.card.uid);
        if (!it) return;
        var list = dayItems(it.day).filter(G.hasPos), i = list.indexOf(it) + dir;
        if (i >= 0 && i < list.length) openCard(list[i].uid);
    }
    function renderCard() {
        var c = $("tpPlaceCard");
        if (!c) return;
        var it = ui.card ? byUid(ui.card.uid) : null;
        if (!it) { closeCard(); return; }
        var g = ui.card.g, tab = ui.card.tab;
        c.textContent = "";
        c.hidden = false;

        var head = el("div", "tp-pcard__head");
        var num = numberOf(it);
        var col = it.day == null ? "#6b7280" : COLORS[(it.day - 1) % COLORS.length];
        head.appendChild(window.TpMap.pinEl(col, num === "" ? "" : num, true));
        var tt = el("div", "tp-pcard__title");
        tt.appendChild(el("strong", null, it.name));
        tt.appendChild(el("small", null, [TYPE[it.type] || "", it.sub, it.day != null ? dayLabel(it.day) : "Chưa xếp ngày"].filter(Boolean).join(" · ")));
        head.appendChild(tt);
        var list = it.day != null ? dayItems(it.day).filter(G.hasPos) : [];
        if (list.length > 1) {
            var i = list.indexOf(it);
            var nav = el("div", "tp-pcard__nav");
            nav.appendChild(btn("tp-mini", "‹", "Mục trước trong ngày", function () { cardNav(-1); }));
            nav.appendChild(el("span", null, (i + 1) + "/" + list.length));
            nav.appendChild(btn("tp-mini", "›", "Mục sau trong ngày", function () { cardNav(1); }));
            head.appendChild(nav);
        }
        head.appendChild(btn("tp-mini tp-pcard__x", "✕", "Đóng thẻ chi tiết", closeCard));
        c.appendChild(head);

        var tabs = el("div", "tp-pcard__tabs");
        [["about", "Giới thiệu"]].concat(g && g.photos && g.photos.length ? [["photos", "Ảnh"]] : []).forEach(function (t) {
            var b = btn("tp-pcard__tab" + (tab === t[0] ? " is-on" : ""), t[1], null, function () { ui.card.tab = t[0]; renderCard(); });
            tabs.appendChild(b);
        });
        c.appendChild(tabs);

        var body = el("div", "tp-pcard__body");
        if (tab === "photos" && g && g.photos) {
            var gal = el("div", "tp-pcard__gal");
            g.photos.forEach(function (ph) {
                var im = el("img");
                im.src = ph.url; im.alt = it.name; im.loading = "lazy";
                if (ph.by) im.title = "Ảnh: " + ph.by;
                gal.appendChild(im);
            });
            body.appendChild(gal);
            body.appendChild(el("small", "tp-muted", "Ảnh từ Google Maps, hiển thị trực tiếp, không lưu trên Travela."));
        } else {
            var top = el("div", "tp-pcard__top");
            var txt = el("div", "tp-pcard__txt");
            if (it.blurb) txt.appendChild(el("p", null, it.blurb));
            else if (!g) txt.appendChild(el("p", "tp-muted", CUSTOM[it.type] ? "Mục bạn tự thêm." : "Chưa có lời giới thiệu cho mục này."));
            if (it.reasons && it.reasons.length) txt.appendChild(el("p", "tp-why", "Vì sao chọn: " + it.reasons.join("; ")));
            if (it.fit && it.fit.length) {
                var fl = el("ul", "tp-fit");
                it.fit.forEach(function (f) { fl.appendChild(el("li", "tp-fit__i tp-fit--" + (f.k === "good" || f.k === "warn" ? f.k : "info"), f.x)); });
                txt.appendChild(fl);
            }
            top.appendChild(txt);
            var img = it.image || (g && g.photos && g.photos[0] && g.photos[0].url);
            if (img) { var pi = el("img", "tp-pcard__img"); pi.src = img; pi.alt = it.name; pi.loading = "lazy"; top.appendChild(pi); }
            body.appendChild(top);

            var facts = el("ul", "tp-pcard__facts");
            function fact(label, value, href) {
                if (!value) return;
                var li = el("li");
                li.appendChild(el("b", null, label + ": "));
                if (href && safeUrl(href)) { var a = el("a", null, value); a.href = href; a.target = "_blank"; a.rel = "noopener"; li.appendChild(a); }
                else li.appendChild(el("span", null, value));
                facts.appendChild(li);
            }
            fact("Địa chỉ", it.address || (g && g.address));
            if (g && g.rating != null) fact("Đánh giá Google", g.rating.toFixed(1) + " ★ (" + g.ratings.toLocaleString("vi-VN") + " lượt)", g.mapsUrl);
            fact("Điện thoại", g && g.phone);
            if (it.website || (g && g.website)) fact("Website", (it.website || g.website).replace(/^https?:\/\/(www\.)?/i, "").replace(/\/$/, ""), it.website || g.website);
            if (it.when) fact("Diễn ra", it.when);
            if (it.cost != null) fact("Chi phí", money(it.cost));
            if (it.note) fact("Ghi chú", it.note);
            if (it.src) fact("Nguồn", it.src);
            if (facts.children.length) body.appendChild(facts);
            if (g && g.hours && g.hours.length) {
                var hd = el("details", "tp-pcard__hours");
                hd.appendChild(el("summary", null, "Giờ mở cửa"));
                var hu = el("ul");
                g.hours.forEach(function (h) { hu.appendChild(el("li", null, h)); });
                hd.appendChild(hu);
                body.appendChild(hd);
            }
            if (g) body.appendChild(el("small", "tp-muted", "Giờ mở cửa, đánh giá và ảnh lấy trực tiếp từ Google Maps, có thể thay đổi."));
            else if (map && map.kind === "google" && !CUSTOM[it.type] && ui.card.g === undefined) body.appendChild(el("small", "tp-muted", "Đang tải thêm thông tin từ Google…"));
        }
        c.appendChild(body);

        var act = el("div", "tp-pcard__act");
        if (G.hasPos(it)) {
            var a1 = el("a", "tp-btn tp-btn--ghost", "Chỉ đường");
            a1.href = dirUrl(it); a1.target = "_blank"; a1.rel = "noopener";
            act.appendChild(a1);
            var a2 = el("a", "tp-btn tp-btn--ghost", "Mở Google Maps");
            a2.href = (g && g.mapsUrl) || G.mapsUrl(it.lat, it.lng); a2.target = "_blank"; a2.rel = "noopener";
            act.appendChild(a2);
        }
        var href = safeUrl(it.url);
        if (href && !CUSTOM[it.type]) { var a3 = el("a", "tp-btn tp-btn--ghost", "Xem trên Travela"); a3.href = href; a3.target = "_blank"; a3.rel = "noopener"; act.appendChild(a3); }
        if (!RO) act.appendChild(ibtn("tp-btn tp-btn--ghost", "fa-pen", "Sửa trong lịch", "Mở phần sửa của " + it.name + " trong lịch", function () {
            ui.open[it.uid] = true;
            if (document.documentElement.classList.contains("tpx-map-open")) setMapOpen(false);
            renderDays();
            focusItem(it.uid);
        }));
        c.appendChild(act);
    }
    function showOnMap(uid) {
        var it = byUid(uid);
        if (!it) return;
        if (!G.hasPos(it)) { say("Mục này chưa có vị trí nên chưa hiện trên bản đồ.", true); return; }
        if (isMobile() && !document.documentElement.classList.contains("tpx-map-open")) {   // điện thoại: bản đồ là màn hình riêng
            if (it.day != null) { ui.mapDay = String(it.day); if ($("tpMapDay")) $("tpMapDay").value = ui.mapDay; }
            setMapOpen(true);
            setTimeout(function () { openCard(uid); }, 120);
            return;
        }
        openCard(uid);
    }
    function isMobile() { return window.matchMedia && window.matchMedia("(max-width: 991.98px)").matches; }

    /* ---------------- điện thoại: bảng trượt dưới bản đồ ---------------- */
    function sheetDay() {
        var m = ui.mapDay;
        if (m && m !== "follow" && m !== "x" && m !== "explore" && +m >= 1) return +m;
        return typeof ui.curDay === "number" ? ui.curDay : (daysCount() ? 1 : null);
    }
    function renderMapSheet() {
        var sh = $("tpMapSheet");
        if (!sh) return;
        sh.textContent = "";
        if (!isMobile() || !document.documentElement.classList.contains("tpx-map-open")) return;
        var d = sheetDay();
        if (!d) return;
        var s = schedule(d);
        var head = el("div", "tpx-msheet__h");
        var prev = ibtn("tpx-msheet__nav", "fa-chevron-left", null, "Ngày trước", function () { goSheet(d - 1); });
        prev.disabled = d <= 1;
        head.appendChild(prev);
        var t = el("button", "tpx-msheet__t");
        t.type = "button";
        t.setAttribute("aria-expanded", ui.sheetMin ? "false" : "true");
        t.appendChild(el("b", null, "Ngày " + d + (dayDate(d) ? " · " + dayDate(d) : "")));
        t.appendChild(el("small", null, s.count ? s.count + " điểm · " + durText(s.travel) + " đi lại · " + S.loadLabel(s.load) : "Chưa có điểm nào"));
        t.addEventListener("click", function () { ui.sheetMin = !ui.sheetMin; renderMapSheet(); });
        head.appendChild(t);
        var next = ibtn("tpx-msheet__nav", "fa-chevron-right", null, "Ngày sau", function () { goSheet(d + 1); });
        next.disabled = d >= daysCount();
        head.appendChild(next);
        sh.appendChild(head);
        sh.classList.toggle("is-min", !!ui.sheetMin);
        if (ui.sheetMin || !s.count) return;
        var ol = el("ol", "tpx-msheet__list");
        s.rows.forEach(function (r) {
            var li = el("li");
            var b = el("button", "tpx-msheet__c" + (r.conflict || r.hours ? " is-bad" : ""));
            b.type = "button";
            b.appendChild(el("span", "tpx-msheet__n", String(r.n)));
            var tx = el("span", "tpx-msheet__x");
            tx.appendChild(el("small", null, hhmm(r.start) + "–" + hhmm(r.end)));
            tx.appendChild(el("b", null, r.it.name));
            if (r.leg) tx.appendChild(el("small", "tpx-msheet__leg", "→ " + r.leg.min + " phút · " + r.leg.km + " km"));
            b.appendChild(tx);
            b.addEventListener("click", function () { if (G.hasPos(r.it)) openCard(r.it.uid); else say("Mục này chưa có vị trí.", true); });
            li.appendChild(b);
            ol.appendChild(li);
        });
        sh.appendChild(ol);
    }
    function goSheet(d) {
        if (d < 1 || d > daysCount()) return;
        ui.mapDay = String(d);
        if ($("tpMapDay")) $("tpMapDay").value = ui.mapDay;
        renderMap();
    }

    /* ---------------- tab ---------------- */
    var TABS = ["plan", "cost", "info"], LEGACY = { explore: "plan", overview: "info" };
    function switchTab(name) {
        name = LEGACY[name] || name;
        if (TABS.indexOf(name) < 0) name = "plan";
        ui.tab = name;
        document.querySelectorAll(".tp-tab").forEach(function (t) {
            var on = t.getAttribute("data-tab") === name;
            t.classList.toggle("is-on", on);
            t.setAttribute("aria-selected", on ? "true" : "false");
        });
        TABS.forEach(function (k) {
            var p = $("tpPanel" + k.charAt(0).toUpperCase() + k.slice(1));
            if (p) p.hidden = k !== name;
        });
        try { sessionStorage.setItem("tpTab" + cfg.trip.id, name); } catch (e) { /* bỏ qua */ }
        if (name === "cost") renderCostTable();
        if (name === "plan" && map) setTimeout(function () { map.resize(); }, 0);
        document.querySelectorAll(".tpx-mob__b[data-mob]").forEach(function (b) { var k = b.getAttribute("data-mob"); if (k !== "map" && k !== "add") b.classList.toggle("is-on", k === name); });
        syncSideTop();
    }

    /* ---------------- dựng lại ---------------- */
    function softUpdate() {
        renderBrief();
        renderKpis();
        renderTotals();
        renderStats();
        renderWarn();
        renderCostSummary();
    }
    function renderAll(keepList) {
        if (!keepList) { renderDays(); renderBulk(); if (ui.tab === "cost") renderCostTable(); }
        renderBrief();
        renderKpis();
        renderDestOnly();
        renderTotals();
        renderStats();
        renderWarn();
        renderSummary();
        renderSugg();
        renderResults();
        renderTarget();
        renderMapDays();
        renderMap();
        renderCostSummary();
        renderToday();
        syncButtons();
    }

    /* ---------------- lưu / chia sẻ ---------------- */
    function save(manual) {
        if (RO) return;
        clearTimeout(timer);
        if (saving) { pendingSave = true; return; }
        if (!dirty && !manual) return;
        if (!manual && autoFail) return;
        clampDays();
        var m = meta();
        if (!m.title) { saveState("error", "Thiếu tên chuyến"); say("Hãy nhập tên chuyến đi ở tab “Thông tin & chia sẻ” (chưa lưu).", true); return; }
        var body = window.TpSave.body({ meta: m, items: items, rain: rain, rev: rev });   // planner-save.js: hợp đồng /luu
        var sent = snap();
        saving = true;
        $("tpSave").disabled = true;
        saveState("saving", "Đang lưu…");
        post(cfg.saveUrl, body)
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
            .then(function (res) {
                saving = false;
                $("tpSave").disabled = false;
                if (res.status === 423) { showLock(res.j.by, res.j.message); autoFail = true; saveState("error", "Đang có người sửa"); return; }
                if (res.status === 409) { showSync(res.j.message || "Chuyến đi vừa được người khác cập nhật.", true); autoFail = true; saveState("error", "Xung đột phiên bản"); return; }
                if (res.ok) {
                    if (res.j.rev != null) rev = res.j.rev;
                    savedSnap = sent;
                    cfg.contentWarnings = res.j.contentWarnings || [];
                    cfg.advice = res.j.advice || [];
                    if (res.j.fit) {
                        var same = snap() === sent;
                        items.forEach(function (x) {
                            var f = res.j.fit[x.type + ":" + x.refId];
                            if (f) { x.fit = f.fit || []; x.outdoor = f.outdoor; x.src = f.src || null; }
                        });
                        if (same) { sent = snap(); savedSnap = sent; last = sent; }
                        renderDays();
                    }
                    renderWarn();
                    renderSummary();
                    if (snap() !== sent) { markDirty(); } else { dirty = false; autoFail = false; clearDraft(); saveState("saved", "Đã lưu " + (res.j.savedAt || "")); loadServerHealth(); emit("tp:saved", { rev: rev }); }
                    if ($("tpLastEdit") && res.j.savedAt) $("tpLastEdit").textContent = "Bạn cập nhật lúc " + res.j.savedAt;
                } else {
                    var errs = res.j && res.j.errors ? Object.keys(res.j.errors).map(function (k) { return res.j.errors[k][0]; }) : [];
                    autoFail = true;
                    saveState("error", "Chưa lưu được");
                    say(errs[0] || (res.j && res.j.message) || "Không lưu được, bấm “Lưu ngay” để thử lại.", true);
                }
                if (pendingSave) { pendingSave = false; if (dirty) save(false); }
            })
            .catch(function () {
                saving = false;
                autoFail = true;
                $("tpSave").disabled = false;
                saveState("error", "Mất kết nối");
                say("Mất kết nối, chưa lưu được. Bấm “Lưu ngay” khi có mạng.", true);
            });
    }
    function share() {
        var b = $("tpShareBtn"), inp = $("tpShareUrl");
        var on = !inp.value;   // ô luôn hiện trong hộp chia sẻ: trống = liên kết đang tắt
        b.disabled = true;
        post(cfg.shareUrl, { on: on ? 1 : 0 })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.url) { inp.value = j.url; b.textContent = "Tắt liên kết"; inp.select(); say("Đã bật liên kết chỉ xem."); }
                else { inp.value = ""; b.textContent = "Bật liên kết chỉ xem"; say("Đã tắt liên kết chỉ xem."); }
                document.dispatchEvent(new CustomEvent("tp:share", { detail: { url: j.url || "" } }));
            })
            .catch(function () { /* giữ trạng thái cũ */ })
            .then(function () { b.disabled = false; });
    }

    /* ---------------- khởi tạo ---------------- */
    function fillDestSelects() {
        var main = $("tpDest");
        (cfg.dests || []).forEach(function (d) {
            var a = el("option", null, d.name);
            a.value = String(d.id);
            main.appendChild(a);
        });
        if (cfg.trip.destinationId) main.value = String(cfg.trip.destinationId);
    }

    fillDestSelects();
    renderPresets();

    $("tpSave").addEventListener("click", function () { save(true); });
    $("tpUndo").addEventListener("click", undoOnce);
    $("tpRedo").addEventListener("click", redoOnce);
    if ($("tpShareBtn")) $("tpShareBtn").addEventListener("click", share);

    $("tpTitle").addEventListener("input", function () { $("tpHeading").textContent = $("tpTitle").value || "Chuyến đi"; });
    $("tpTitle").addEventListener("change", function () { commit(true); });
    $("tpStart").addEventListener("change", function () {
        var m = realignAnchors();
        commit();
        if (m) say(m + " mục nhận phòng / chuyến đi đã đặt được dời theo ngày thật của đặt chỗ.");
    });
    $("tpDays").addEventListener("change", function () { clampDays(); realignAnchors(); commit(); });
    $("tpGroup").addEventListener("change", function () { commit(true); });
    $("tpBudget").addEventListener("input", softUpdate);
    $("tpBudget").addEventListener("change", function () { commit(true); });
    $("tpDest").addEventListener("change", function () { commit(true); });

    $("tpTarget").addEventListener("change", function () { var v = $("tpTarget").value; ui.target = v === "" ? null : v === "auto" ? "auto" : +v; renderTarget(); });
    $("tpMapDay").addEventListener("change", function () {
        ui.mapDay = $("tpMapDay").value;
        if (ui.mapDay === "explore") {
            var d0 = typeof ui.curDay === "number" ? ui.curDay : null;
            if (d0 !== ui.around.day) { ui.around.day = d0; ui.around.data = null; }
            if (!aroundDay()) { say("Chưa có ngày nào có điểm với vị trí để khám phá quanh tuyến.", true); return; }
            if (!ui.around.data) loadAround(); else renderMap();
            say("Ghim cam là nơi gần tuyến ngày " + aroundDay() + ". Bấm ghim để thêm vào ngày hoặc lưu lại.");
            return;
        }
        closeCard();
        renderMap();
    });

    /* ---------------- bố cục: ngăn thêm, kiểu xem, bản đồ rộng, thanh điện thoại ---------------- */
    if ($("tpAddOpen")) $("tpAddOpen").addEventListener("click", function () { openAdd(ui.target === undefined ? 1 : ui.target); });
    $("tpAddClose").addEventListener("click", closeAdd);
    if ($("tpAddBack")) $("tpAddBack").addEventListener("click", closeAdd);
    document.querySelectorAll(".tpx-view__b").forEach(function (b) {
        b.addEventListener("click", function () {
            ui.view = b.getAttribute("data-view");
            try { localStorage.setItem("tpView", ui.view); } catch (e) { /* bỏ qua */ }
            document.querySelectorAll(".tpx-view__b").forEach(function (x) { var on = x === b; x.classList.toggle("is-on", on); x.setAttribute("aria-pressed", on ? "true" : "false"); });
            renderDays();
        });
        var on = b.getAttribute("data-view") === ui.view;
        b.classList.toggle("is-on", on);
        b.setAttribute("aria-pressed", on ? "true" : "false");
    });
    $("tpWide").addEventListener("click", function () {
        var root = $("tpRoot"), on = !root.classList.contains("tpx--wide");
        root.classList.toggle("tpx--wide", on);
        this.setAttribute("aria-pressed", on ? "true" : "false");
        this.querySelector("span").textContent = on ? "Hiện lịch trình" : "Mở rộng";
        this.querySelector(".fas").className = "fas " + (on ? "fa-compress-alt" : "fa-expand-alt");
        this.title = on ? "Hiện lại cột lịch trình" : "Thu gọn lịch để bản đồ rộng hơn";
        if (map) setTimeout(function () { map.resize(); renderMap(); }, 260);
    });
    function setMapOpen(on) {
        document.documentElement.classList.toggle("tpx-map-open", on);
        renderMapSheet();
        document.querySelectorAll('.tpx-mob__b[data-mob="map"]').forEach(function (b) { b.classList.toggle("is-on", on); });
        if (map) setTimeout(function () { map.resize(); renderMap(); }, 50);
    }
    $("tpMapClose").addEventListener("click", function () { setMapOpen(false); });
    document.querySelectorAll(".tpx-mob__b").forEach(function (b) {
        b.addEventListener("click", function () {
            var k = b.getAttribute("data-mob");
            if (k === "add") { setMapOpen(false); openAdd(ui.target); return; }
            if (k === "map") { setMapOpen(!document.documentElement.classList.contains("tpx-map-open")); return; }
            if (k === "plan" && ui.tab === "plan" && !document.documentElement.classList.contains("tpx-map-open")) { gotoDay(ui.curDay || 1); return; }
            setMapOpen(false);
            switchTab(k);
            try { $("tpBody").scrollIntoView({ block: "start", behavior: "smooth" }); } catch (e) { /* bỏ qua */ }
        });
    });
    /* Thanh menu của trang ẩn khi cuộn xuống, hiện khi cuộn lên: dải ngày và bản đồ "dính" ngay dưới mép dưới của nó. */
    (function () {
        var head = document.querySelector(".tv-header"), root = $("tpRoot"), raf = 0, cur = -1, rt = null;
        if (!head || !root) return;
        function sync() {
            raf = 0;
            var b = Math.max(0, Math.round(head.getBoundingClientRect().bottom));
            if (b === cur) return;
            cur = b;
            root.style.setProperty("--tpx-stick", b + "px");
            // khung bản đồ đổi chiều cao theo mép menu: báo cho bản đồ vẽ lại kích thước
            clearTimeout(rt);
            rt = setTimeout(function () { if (map) map.resize(); }, 120);
        }
        function req() { if (!raf) raf = requestAnimationFrame(sync); }
        window.addEventListener("scroll", function () { req(); setTimeout(req, 320); }, { passive: true });
        window.addEventListener("resize", req);
        head.addEventListener("transitionend", req);
        sync();
    })();
    document.querySelectorAll(".js-invite").forEach(function (b) { b.addEventListener("click", function () { openMembers(); }); });

    /* thanh bên: đi tới phần tương ứng; thu gọn thành dải biểu tượng (nhớ theo trình duyệt) */
    document.querySelectorAll(".tpx-side__b[data-go]").forEach(function (b) { b.addEventListener("click", function () { sideGo(b.getAttribute("data-go")); }); });
    function setSideMini(on) {
        $("tpRoot").classList.toggle("tpx--sidemini", on);
        var h = $("tpSideHide");
        if (h) { h.querySelector("span").textContent = on ? "Hiện thanh bên" : "Ẩn thanh bên"; h.querySelector(".fas").className = "fas " + (on ? "fa-angle-double-right" : "fa-angle-double-left"); h.setAttribute("aria-expanded", on ? "false" : "true"); }
        try { localStorage.setItem("tpSideMini", on ? "1" : "0"); } catch (e) { /* bỏ qua */ }
        if (map) setTimeout(function () { map.resize(); renderMap(); }, 60);
    }
    if ($("tpSideHide")) $("tpSideHide").addEventListener("click", function () { setSideMini(!$("tpRoot").classList.contains("tpx--sidemini")); });
    try { if (localStorage.getItem("tpSideMini") === "1") setSideMini(true); } catch (e) { /* bỏ qua */ }

    /* ngăn thêm: hai cách tìm */
    document.querySelectorAll(".tpx-dtab").forEach(function (b) { b.addEventListener("click", function () { setDtab(b.getAttribute("data-dtab")); }); });
    /* đóng menu "..." của ngày khi bấm ra ngoài */
    document.addEventListener("click", function (e) {
        document.querySelectorAll(".tpx-menu[open]").forEach(function (m) { if (!m.contains(e.target)) m.open = false; });
    });

    /* in / lưu PDF: mở hết các ngày, kiểu xem chi tiết; xong thì trả lại như cũ */
    var printBak = null;
    window.addEventListener("beforeprint", function () {
        printBak = { c: ui.collapsed, v: ui.view };
        ui.collapsed = {};
        ui.view = "full";
        renderDays();
    });
    window.addEventListener("afterprint", function () {
        if (!printBak) return;
        ui.collapsed = printBak.c;
        ui.view = printBak.v;
        printBak = null;
        renderDays();
    });
    if ($("tpPrint")) $("tpPrint").addEventListener("click", function () { $("tpTopMenu").open = false; switchTab("plan"); window.print(); });
    // menu "⋮" trên thanh công cụ: thao tác ít dùng, theo ngày đang xem
    (function () {
        var m = $("tpTopMenu");
        if (!m) return;
        function curDay() { return typeof ui.curDay === "number" ? ui.curDay : (daysCount() ? 1 : null); }
        m.querySelectorAll("[data-top]").forEach(function (b) {
            b.addEventListener("click", function () {
                m.open = false;
                var k = b.getAttribute("data-top"), d = curDay();
                if (k === "history") return;   // planner-history.js mở hộp thoại lịch sử phiên bản
                if (k === "health") { switchTab("plan"); ui.healthOpen = true; renderWarn(); var h = $("tpHealth"); if (h) h.scrollIntoView({ block: "start", behavior: "smooth" }); }
                else if (k === "cost") switchTab("cost");
                else if (!d) say("Chuyến đi chưa có ngày nào.", true);
                else if (k === "optimize") { switchTab("plan"); gotoDay(d); optimizeDay(d); }
                else if (k === "balance") { switchTab("plan"); gotoDay(d); balanceDay(d); }
            });
        });
        document.addEventListener("click", function (e) { if (m.open && !m.contains(e.target)) m.open = false; });
        document.addEventListener("keydown", function (e) { if (e.key === "Escape" && m.open) { m.open = false; m.querySelector("summary").focus(); } });
    })();

    /* nhân bản chuyến: còn thay đổi chưa lưu thì lưu trước, rồi mới gửi */
    if ($("tpDupForm")) $("tpDupForm").addEventListener("submit", function (e) {
        var f = this;
        if (!window.confirm("Tạo một chuyến đi mới giống hệt chuyến này (bạn là chủ của bản sao)?")) { e.preventDefault(); return; }
        if (!dirty || RO) return;
        e.preventDefault();
        save(true);
        var tries = 0, iv = setInterval(function () {
            if ((!saving && !dirty) || ++tries > 40) { clearInterval(iv); dirty = false; f.submit(); }
        }, 250);
    });
    var qTimer = null;
    $("tpQ").addEventListener("input", function () { clearTimeout(qTimer); qTimer = setTimeout(runSearch, 350); });
    $("tpQ").addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); clearTimeout(qTimer); runSearch(); }
        else if (e.key === "Escape") { clearTimeout(qTimer); $("tpQ").value = ""; ui.searchSeq++; ui.res = null; ui.br = null; renderResults(); renderBrowse(); }
    });
    $("tpDestOnly").addEventListener("change", function () { if ($("tpQ").value.trim()) runSearch(); });
    $("tpCustomForm").addEventListener("submit", function (e) {
        e.preventDefault();
        var label = $("tpCustomLabel").value.trim();
        if (!label) return;
        var c = $("tpCustomCost").value, d = ui.draft;
        if (addItem({ type: $("tpCustomKind").value, refId: null, name: label, cost: c === "" ? null : +c, day: ui.target,
            lat: d ? d.lat : null, lng: d ? d.lng : null, source: d ? d.source : null })) {
            $("tpCustomLabel").value = "";
            $("tpCustomCost").value = "";
            $("tpCustomLink").value = "";
            setDraft(null);
        }
    });
    $("tpCustomLink").addEventListener("input", function () {
        var v = this.value.trim();
        if (!v) { setDraft(null); return; }
        var p = G.parseMapsInput(v);
        if (p) { p.source = "link"; setDraft(p); return; }
        setDraft(null);
        $("tpPosMsg").textContent = G.isShortMapsLink(v) ? SHORT_MSG : BAD_POS_MSG;
    });
    $("tpPickMap").addEventListener("click", function () { startPick(null); });
    $("tpPosClear").addEventListener("click", function () { $("tpCustomLink").value = ""; setDraft(null); });

    document.querySelectorAll(".tp-tab").forEach(function (t) { t.addEventListener("click", function () { switchTab(t.getAttribute("data-tab")); }); });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && ui.pick) { endPick(); say("Đã hủy chọn vị trí."); return; }
        var tag = (e.target && e.target.tagName) || "";
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) return;
        if (!(e.ctrlKey || e.metaKey)) return;
        var k = (e.key || "").toLowerCase();
        if (k === "z" && !e.shiftKey) { e.preventDefault(); undoOnce(); }
        else if ((k === "z" && e.shiftKey) || k === "y") { e.preventDefault(); redoOnce(); }
        else if (k === "s") { e.preventDefault(); save(true); }
    });
    window.addEventListener("beforeunload", function (e) {
        if (dirty) { backupDraft(); e.preventDefault(); e.returnValue = ""; }
    });

    /* ---------------- cộng tác: thông báo có người vừa sửa, mời bạn đồng hành ---------------- */
    function showSync(text, conflict) {
        var b = $("tpSync");
        if (!b) return;
        b.textContent = "";
        b.hidden = false;
        b.classList.toggle("tp-banner--bad", !!conflict);
        b.appendChild(el("span", null, text + " "));
        b.appendChild(btn("tp-mini", "Tải lại", "Tải lại chuyến đi", function () { dirty = false; location.reload(); }));
    }
    /* ---------------- T-40 khóa mềm: chuyến có bạn đồng hành chỉ một người sửa tại một thời điểm ---------------- */
    var lockHeld = true;
    function showLock(by, text) {
        lockHeld = false;
        var b = $("tpSync");
        if (!b) return;
        b.textContent = "";
        b.hidden = false;
        b.classList.add("tp-banner--bad");
        b.appendChild(el("span", null, text || ((by || "Người khác") + " đang sửa chuyến đi này. Bạn sẽ sửa được khi họ xong.")));
    }
    function lockTick(release) {
        if (!cfg.lockUrl || RO) return;
        var fd = new FormData();
        fd.append("_token", cfg.csrf || (document.querySelector("meta[name=csrf-token]") || {}).content || "");
        fd.append("action", release ? "release" : "acquire");
        if (release) { if (navigator.sendBeacon) navigator.sendBeacon(cfg.lockUrl, fd); return; }
        fetch(cfg.lockUrl, { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || j.solo) return;
                if (j.mine) {
                    if (!lockHeld) {
                        // vừa tới lượt mình: tải lại để có bản mới nhất của người trước
                        lockHeld = true; autoFail = false;
                        showSync("Bạn đã có thể sửa chuyến đi. Hãy tải lại để thấy thay đổi mới nhất.", false);
                    }
                } else {
                    showLock(j.holder);
                }
            })
            .catch(function () {});
    }
    if (cfg.lockUrl && !RO) {
        lockTick(false);
        setInterval(function () { if (!document.hidden) lockTick(false); }, 60000);
        window.addEventListener("pagehide", function () { lockTick(true); });
    }

    var pollMs = 20000;
    function pollRev() {
        if (!cfg.revUrl || document.hidden) return;
        API.raw(cfg.revUrl)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) return;
                if (j.role && j.role !== ROLE) { showSync("Quyền của bạn trong chuyến đi vừa thay đổi.", false); return; }
                if (j.rev > rev) {
                    showSync((j.by || "Người khác") + " vừa cập nhật chuyến đi" + (dirty ? ", bạn có thay đổi chưa lưu nên có thể bị xung đột." : "."), dirty);
                    if ($("tpLastEdit") && j.by) $("tpLastEdit").textContent = j.by + " vừa cập nhật";
                }
            })
            .catch(function () { /* mất mạng: bỏ qua lần này */ });
    }
    if (cfg.revUrl) setInterval(pollRev, pollMs);

    var memLoaded = false;
    function memberMsg(t, bad) {
        var m = $("tpInvMsg");
        if (!m) return;
        m.textContent = t || "";
        m.classList.toggle("tp-msg--bad", !!bad);
    }
    function renderMembers(j) {
        var ul = $("tpMemList");
        if (!ul) return;
        ul.textContent = "";
        (j.members || []).forEach(function (m) {
            var li = el("li", "tp-mem");
            li.appendChild(el("span", "tp-mem__av", (m.name || "?").trim().charAt(0).toUpperCase()));
            var who = el("div", "tp-mem__who");
            who.appendChild(el("b", null, m.name + (m.userId === cfg.me ? " (bạn)" : "")));
            if (m.email) who.appendChild(el("small", "tp-muted", m.email));
            li.appendChild(who);
            if (m.isOwner) li.appendChild(el("span", "tp-role tp-role--owner", "Chủ chuyến"));
            else if (ROLE === "owner") {
                var sel = el("select", "no-nice");
                sel.setAttribute("aria-label", "Quyền của " + m.name);
                [["view", "Chỉ xem"], ["edit", "Chỉnh sửa"]].forEach(function (o) { var op = el("option", null, o[1]); op.value = o[0]; sel.appendChild(op); });
                sel.value = m.role;
                sel.addEventListener("change", function () {
                    post(cfg.membersUrl + "/" + m.userId, { role: sel.value }).then(function (r) { memberMsg(r.ok ? "Đã đổi quyền của " + m.name + "." : "Không đổi được quyền.", !r.ok); });
                });
                li.appendChild(sel);
                li.appendChild(btn("tp-mini tp-mini--danger", "Gỡ", "Gỡ " + m.name + " khỏi chuyến đi", function () {
                    post(cfg.membersUrl + "/" + m.userId + "/go", {}).then(function (r) { if (r.ok) { memberMsg("Đã gỡ " + m.name + "."); loadMembers(); } else memberMsg("Không gỡ được.", true); });
                }));
            } else {
                li.appendChild(el("span", "tp-role tp-role--" + m.role, m.role === "edit" ? "Chỉnh sửa" : "Chỉ xem"));
                if (m.userId === cfg.me) li.appendChild(btn("tp-mini tp-mini--danger", "Rời chuyến", "Rời khỏi chuyến đi", function () {
                    if (!window.confirm("Rời khỏi chuyến đi này?")) return;
                    post(cfg.membersUrl + "/" + m.userId + "/go", {}).then(function (r) { if (r.ok) { dirty = false; location.href = "/len-ke-hoach"; } else memberMsg("Không rời được.", true); });
                }));
            }
            ul.appendChild(li);
        });
    }
    function loadMembers() {
        API.raw(cfg.membersUrl)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j) { memLoaded = true; renderMembers(j); } else memberMsg("Chưa tải được danh sách.", true); })
            .catch(function () { memberMsg("Mất kết nối.", true); });
    }
    var memLastFocus = null;
    function openMembers() {
        var d = $("tpMembers");
        if (!d) return;
        memLastFocus = document.activeElement;
        d.hidden = false;
        loadMembers();
        var f = $("tpInvEmail") || d.querySelector("button");
        if (f) f.focus();
    }
    function closeMembers() {
        var d = $("tpMembers");
        if (!d) return;
        d.hidden = true;
        if (memLastFocus && memLastFocus.focus) memLastFocus.focus();
    }
    function inviteSubmit(e) {
        e.preventDefault();
        var email = $("tpInvEmail").value.trim(), role = $("tpInvRole").value;
        if (!email) return;
        memberMsg("Đang mời…");
        post(cfg.membersUrl, { email: email, role: role })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                var j = res.j || {};
                var errs = j.errors ? Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }) : [];
                memberMsg(j.message || errs[0] || (res.ok ? "Đã mời." : "Không mời được."), !res.ok);
                if (res.ok) { $("tpInvEmail").value = ""; loadMembers(); }
            })
            .catch(function () { memberMsg("Mất kết nối.", true); });
    }
    if ($("tpInviteBtn")) $("tpInviteBtn").addEventListener("click", openMembers);
    if ($("tpMembersClose")) $("tpMembersClose").addEventListener("click", closeMembers);
    if ($("tpMembers")) $("tpMembers").addEventListener("click", function (e) { if (e.target === $("tpMembers")) closeMembers(); });
    if ($("tpInvForm")) $("tpInvForm").addEventListener("submit", inviteSubmit);
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape" || ui.pick) return;
        if ($("tpMembers") && !$("tpMembers").hidden) closeMembers();
        else if (!$("tpAddDrawer").hidden) closeAdd();
        else if (ui.card) closeCard();
        else if (document.documentElement.classList.contains("tpx-map-open")) setMapOpen(false);
    });
    if ($("tpPlaceCard")) $("tpPlaceCard").hidden = true;

    if (RO) {
        var root = document.querySelector(".tp");
        if (root) root.classList.add("tp--readonly");
        ["tpTitle", "tpStart", "tpDays", "tpGroup", "tpBudget", "tpDest"].forEach(function (id) { if ($(id)) $(id).disabled = true; });
        saveState("ro", "Bạn đang xem với quyền chỉ xem");
    } else {
        saveState("saved", "Đã lưu");
    }

    clampDays();
    last = snap();
    savedSnap = last;
    renderAll();
    restoreDraftIfNeeded();
    initMap();
    if (items.length) loadServerHealth();
    var startTab = (location.hash || "").replace("#", "");
    if (!/^(plan|cost|info|overview|explore)$/.test(startTab)) {
        try { startTab = sessionStorage.getItem("tpTab" + cfg.trip.id) || "plan"; } catch (e) { startTab = "plan"; }
    }
    switchTab(startTab);

    /* Cửa DUY NHẤT cho La bàn Copilot (planner-ai-copilot.js): đọc trạng thái, áp dụng đề xuất đã được máy chủ kiểm chứng.
       Áp dụng = CHÍNH planner-resolve.js như nút "Xem cách xử lý" (xem trước = áp dụng), rồi commit -> tự lưu -> hoàn tác được. */
    window.TpPlanner = {
        state: function () { return { rev: rev, dirty: dirty || saving, ro: RO, count: items.length }; },
        item: function (uid) {
            var x = items.filter(function (i) { return i.uid === +uid; })[0];
            return x ? { name: x.name, day: x.day } : null;
        },
        saveNow: function () { if (!RO && (dirty || saving)) save(true); },
        /* Đặt chỗ đổi (planner-bookings.js): chỗ ở / vé đi về mới -> tính lại lịch từng ngày ngay, không cần tải lại trang */
        setLogistics: function (x) { if (x && x.stays) { cfg.logistics = x; renderAll(); } },
        /* chọn một điểm trên bản đồ cho việc khác (vị trí chỗ ở ở mục Đặt chỗ); cb({lat, lng}) */
        pickOnMap: function (cb) { if (RO) return false; startPick(null, cb); return !!map; },
        applyAi: function (p) {
            if (RO) return { ok: false, reason: "ro" };
            if (dirty || saving) return { ok: false, reason: "dirty" };
            if (p.baseRev !== rev) return { ok: false, reason: "stale" };
            var r = window.TpResolve.apply(items, rain, p.changes, newItem, RAIN_MAX);
            if (!r.ok) { loadServerHealth(); return { ok: false, reason: "stale" }; }
            commit();
            say("La bàn đã áp dụng đề xuất.", false, [["Hoàn tác", undoOnce]]);
            return { ok: true };
        },
    };
    emit("tp:ready", { rev: rev });
})();

