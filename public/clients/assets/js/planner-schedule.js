/* ScheduleEngine phía trình duyệt: xếp giờ trong một ngày, độ dày, buổi, loại mục.
 *
 * CÙNG HỢP ĐỒNG với App\Services\Planner\Health\DaySchedule (máy chủ). Không khai báo hằng số nghiệp vụ nào ở đây:
 * mọi con số (giờ bắt đầu, sức chứa, buổi, đệm, bữa ăn chừa sẵn, mẫu nhận diện lưu trú / ăn uống) đến từ
 * PlannerRules::client() -> cfg.rules. Tuyến (đoạn đi giữa hai điểm) dùng TpGeo đã configure(cfg.rules.route).
 *
 * Vai trò: cho phản hồi tức thì khi kéo thả / sửa giờ. Kết luận chính thức (điểm sức khỏe, cảnh báo) do máy chủ trả
 * qua /suc-khoe sau mỗi lần lưu. tests/Feature/PlannerContractTest chạy file này bằng Node và so với DaySchedule.
 *
 * Không đụng DOM; chạy được trong trình duyệt (window.TpSchedule) và trong Node (vm, xem PlannerContractTest).
 */
(function (root) {
    "use strict";

    function create(rules, geo) {
        var R = rules || {};
        var SLOTS = R.slots || {};
        var SLOT_KEYS = Object.keys(SLOTS).sort(function (a, b) { return SLOTS[a].start - SLOTS[b].start; });
        var PAT = {};
        Object.keys(R.kindPatterns || {}).forEach(function (k) { PAT[k] = new RegExp(R.kindPatterns[k], "iu"); });

        /** lodging | meal | experience | transport | null (giống PlannerRules::kindOf). */
        function kindOf(it) {
            if (it.type === "food") return "meal";
            if (it.type === "experience" || it.type === "transport") return it.type;
            if (it.type === "place" && it.kind) {
                var pk = R.placeKinds || {};
                if ((pk.meal || []).indexOf(it.kind) >= 0) return "meal";
                if ((pk.lodging || []).indexOf(it.kind) >= 0) return "lodging";
            }
            if (it.type === "note") {
                var t = (it.name || "") + " " + (it.sub || "");
                if (PAT.lodging && PAT.lodging.test(t)) return "lodging";
                if (PAT.meal && PAT.meal.test(t)) return "meal";
            }
            return null;
        }
        /** Thời lượng: người dùng nhập > lưu trú tự thêm 30 phút > mặc định máy chủ gửi kèm (defDur) > 60 (di chuyển 30). */
        function itemDur(it) {
            if (it.durationMin) return it.durationMin;
            if (kindOf(it) === "lodging") return 30;
            if (it.defDur) return it.defDur;
            return it.type === "transport" ? 30 : 60;
        }
        function slotStart(slot) { return slot && SLOTS[slot] ? SLOTS[slot].start : null; }
        /** Giờ thuộc buổi nào (cùng PlannerRules::slotOf). */
        function slotOf(m) {
            for (var i = 0; i < SLOT_KEYS.length; i++) if (m < SLOTS[SLOT_KEYS[i]].end) return SLOT_KEYS[i];
            return SLOT_KEYS[SLOT_KEYS.length - 1] || "evening";
        }
        /* Giờ mở cửa: cùng quy tắc với App\Services\Planner\OpeningHours (hours = {"1":[[mở,đóng],...], "2":[] = đóng cả ngày}). */
        function hoursCheck(hours, dow, start, end) {
            if (!hours || dow == null || !Object.prototype.hasOwnProperty.call(hours, String(dow))) return null;
            var r = hours[String(dow)], i;
            if (!r.length) return "closed";
            for (i = 0; i < r.length; i++) if (start >= r[i][0] && end <= r[i][1]) return null;
            for (i = 0; i < r.length; i++) { if (start < r[i][0]) return "early"; if (start < r[i][1]) return "late"; }
            return "late";
        }
        function hoursFit(hours, dow, start, dur) {
            if (!hours || dow == null || !(hours[String(dow)] || []).length) return null;
            var r = hours[String(dow)];
            for (var i = 0; i < r.length; i++) { var s = Math.max(start, r[i][0]); if (s + dur <= r[i][1]) return s; }
            return null;
        }
        function hoursText(hours, dow) {
            if (!hours || dow == null || !Object.prototype.hasOwnProperty.call(hours, String(dow))) return null;
            var r = hours[String(dow)];
            if (!r.length) return "đóng cửa";
            var f = function (m) { return String(Math.floor(m / 60)).padStart(2, "0") + ":" + String(m % 60).padStart(2, "0"); };
            return r.map(function (x) { return f(x[0]) + "–" + f(x[1]); }).join(", ");
        }
        /** Nhãn độ dày (cùng PlannerRules::loadLabel): Nhẹ < 50% · Vừa phải < mức cảnh báo · Bận < 100% · Quá tải. */
        function loadLabel(load) { return load >= 1 ? "Quá tải" : load >= (R.loadWarn || 0.85) ? "Bận" : load >= 0.5 ? "Vừa phải" : "Nhẹ"; }
        function level(load) { return load >= 1 ? "bad" : load >= (R.loadWarn || 0.85) ? "warn" : "ok"; }

        /**
         * Xếp giờ một ngày (danh sách mục theo thứ tự). Quy tắc giống DaySchedule:
         *  - FIXED (startMin) giữ giờ; SLOT không bắt đầu trước đầu buổi; còn lại nối tiếp, làm tròn 5 phút;
         *  - trùng giờ: giờ cố định sớm hơn lúc mục trước kết thúc (dung sai 5'); không kịp: sớm hơn lúc đi tới nơi;
         *  - chờ >= 15 phút là thời gian trống; độ dày = (hoạt động + di chuyển + bữa chừa sẵn + đệm) / sức chứa.
         */
        /** @param opts {dow: thứ ISO 1-7 của ngày, null khi chưa có ngày đi} */
        function day(list, opts) {
            var dow = opts && opts.dow != null ? opts.dow : null;
            var g = geo.legs(list), legOf = {};
            // T-05: chặng có phương tiện riêng (legMode của mục xuất phát): phút = km / tốc độ phương tiện + chờ (cùng PlannerRules::legMinutes)
            var LM = R.legModes || {}, defMode = (R.route && R.route.mode) || "driving";
            if (g.legs.some(function (l) { return l.from.legMode && l.from.legMode !== defMode && LM[l.from.legMode]; })) {
                var sum = 0;
                g.legs = g.legs.map(function (l) {
                    var m = l.from.legMode, x = m && m !== defMode && LM[m] && l.kmExact > 0 ? LM[m] : null;
                    var c = x ? { from: l.from, to: l.to, km: l.km, kmExact: l.kmExact, min: Math.round(l.kmExact / Math.max(0.1, x.speedKmh) * 60) + (x.waitMin || 0), mode: m } : l;
                    sum += c.min;
                    return c;
                });
                g.minutes = sum;
            }
            g.legs.forEach(function (l) { legOf[l.from.uid != null ? l.from.uid : list.indexOf(l.from)] = l; });
            var clock = R.dayStart, rows = [], act = 0, conflicts = [], free = 0, hasMeal = false;
            // chỗ ở + đi / về (cùng DaySchedule): xuất phát từ nơi ngủ đêm trước, về nơi ngủ đêm nay; ngày tới nơi / ngày về có giờ chặn
            var cx = (opts && opts.ctx) || {}, arrive = cx.arrive != null ? cx.arrive : null, leave = cx.leave != null ? cx.leave : null, blocks = cx.blocks || [];
            var startLeg = null, endLeg = null, first = list[0], posList = list.filter(geo.hasPos);
            if (cx.from && arrive == null && first && geo.hasPos(first)) {
                var sl = geo.legs([cx.from, first]).legs[0];
                if (sl) { startLeg = { min: sl.min, km: sl.kmExact, name: cx.from.name }; if (first.startMin == null) clock += sl.min; }
            }
            if (cx.to && posList.length) {
                var el2 = geo.legs([posList[posList.length - 1], cx.to]).legs[0];
                if (el2) endLeg = { min: el2.min, km: el2.kmExact, name: cx.to.name };
            }
            function exempt(it) { var k = kindOf(it); return it.type === "transport" || k === "transport" || k === "lodging"; }
            list.forEach(function (it, i) {
                var fixed = it.startMin != null, start, prev = rows[rows.length - 1] || null;
                if (fixed) {
                    start = it.startMin;
                    if (arrive != null && start < arrive - 5 && !exempt(it)) conflicts.push({ a: null, b: it, type: "arrival", at: arrive, text: cx.arriveText || null });
                    else if (prev && start < prev.end - 5) conflicts.push({ a: prev, b: it, type: "overlap" });
                    else if (prev && start < clock - 5) conflicts.push({ a: prev, b: it, type: "travel", need: clock - prev.end });
                } else {
                    start = Math.ceil(clock / 5) * 5;
                    if (arrive != null && start < arrive && !exempt(it)) start = Math.ceil(arrive / 5) * 5;
                    var ss = slotStart(it.slot);
                    if (ss != null && ss > start) start = ss;
                    var fit = hoursFit(it.hours, dow, start, itemDur(it));   // tới sớm: chờ tới giờ mở cửa
                    if (fit != null && fit > start) start = fit;
                    // chặng di chuyển giữa ngày (đổi thành phố): không xếp hoạt động chồng lên, dời sau khi tới nơi
                    if (!exempt(it)) blocks.forEach(function (b) { if (start < b.to && start + itemDur(it) > b.from) start = Math.ceil(b.to / 5) * 5; });
                }
                var wait = prev ? Math.max(0, start - clock) : 0;
                if (wait >= 15) free += wait;
                var du = itemDur(it), leg = legOf[it.uid != null ? it.uid : i] || null;
                var hasC = conflicts.some(function (c) { return c.b === it; });
                if (!hasC && !exempt(it)) {
                    if (fixed && blocks.some(function (b) { return start < b.to - 5 && start + du > b.from + 5; })) conflicts.push({ a: null, b: it, type: "transfer", at: null, text: blocks.map(function (b) { return b.text; }).join("; ") });
                    else if (leave != null && start + du > leave + 5) conflicts.push({ a: null, b: it, type: "departure", at: leave, text: cx.leaveText || null });
                }
                var row = { it: it, n: i + 1, start: start, end: start + du, dur: du, fixed: fixed, leg: leg, slot: slotOf(start), conflict: null, wait: wait, hours: hoursCheck(it.hours, dow, start, start + du) };
                rows.push(row);
                act += du;
                if (kindOf(it) === "meal") hasMeal = true;
                clock = row.end + (leg ? leg.min : 0);
            });
            conflicts.forEach(function (c) { rows.forEach(function (r) { if (r.it === c.b) r.conflict = c; }); });
            var meal = rows.length && !hasMeal ? R.mealAllowanceMin : 0;
            var travel = g.minutes + (startLeg ? startLeg.min : 0) + (endLeg ? endLeg.min : 0);
            var kmEx = (g.kmExact || 0) + (startLeg ? startLeg.km : 0) + (endLeg ? endLeg.km : 0);
            var buffer = Math.round((act + travel) * R.bufferRatio);
            var occ = act + travel + meal + buffer;
            return {
                rows: rows, act: act, travel: travel, km: Math.round(kmEx), kmExact: kmEx, legs: g.legs, free: free, meal: meal, buffer: buffer, occ: occ,
                startLeg: startLeg, endLeg: endLeg, ctx: cx,
                start: rows.length ? rows[0].start : R.dayStart, end: rows.length ? rows[rows.length - 1].end : R.dayStart,
                load: occ / R.availableMin, conflicts: conflicts, count: list.length, dow: dow,
            };
        }

        /** Hợp đồng chung với DaySchedule::contract() (cùng khóa, cùng đơn vị). */
        function contract(d, s) {
            return {
                day: d,
                rows: s.rows.map(function (r) {
                    return {
                        key: r.it.key || (r.it.type + ":" + (r.it.refId != null ? r.it.refId : r.it.name)),
                        start: r.start, end: r.end, dur: r.dur, fixed: r.fixed, slot: r.slot,
                        travelMin: r.leg ? r.leg.min : 0, travelKm: r.leg ? Math.round(r.leg.kmExact * 10) / 10 : 0,
                        waitMin: r.wait, conflict: r.conflict ? r.conflict.type : null, hours: r.hours,
                    };
                }),
                activityMin: s.act, travelMin: s.travel, travelKm: Math.round((s.kmExact || 0) * 10) / 10,
                mealMin: s.meal, bufferMin: s.buffer, freeMin: s.free, occupiedMin: s.occ, availableMin: R.availableMin,
                loadPct: Math.round(s.load * 100), level: s.count ? level(s.load) : "ok", loadLabel: loadLabel(s.count ? s.load : 0), start: s.start, end: s.end,
                startLeg: s.startLeg ? { min: s.startLeg.min, km: Math.round(s.startLeg.km * 10) / 10 } : null,
                endLeg: s.endLeg ? { min: s.endLeg.min, km: Math.round(s.endLeg.km * 10) / 10 } : null,
            };
        }

        /* Ngữ cảnh một ngày từ chỗ ở và vé đi / về (CÙNG quy tắc với TripLogistics::dayContext ở máy chủ).
           raw = {stays:[{name,lat,lng,in,out}], moves:[{id,kind,name,dep:"Y-m-d H:i",arr}]}; startDate "Y-m-d" (có thể đang sửa, chưa lưu). */
        function addDays(ymd, n) {
            var d = new Date(Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7) - 1, +ymd.slice(8, 10) + n));
            return d.toISOString().slice(0, 10);
        }
        function minOf(dt) { return +dt.slice(11, 13) * 60 + +dt.slice(14, 16); }
        function hm(m) { return String(Math.floor(m / 60) % 24).padStart(2, "0") + ":" + String(m % 60).padStart(2, "0"); }
        var STATION = { flight: "sân bay", train: "ga", bus: "bến xe" };
        function dayCtx(raw, startDate, d, days) {
            var out = { from: null, to: null, arrive: null, arriveText: null, leave: null, leaveText: null, blocks: [] };
            if (!raw || !startDate || !/^\d{4}-\d{2}-\d{2}$/.test(startDate)) return out;
            var T = R.transfer || { exit: {}, before: {}, typical: {} };
            var start = startDate, date = addDays(startDate, d - 1), prev = addDays(startDate, d - 2), last = addDays(startDate, Math.max(1, days) - 1);
            (raw.stays || []).forEach(function (s) {
                if (s.lat == null || s.lng == null) return;
                var pt = { name: s.name, lat: +s.lat, lng: +s.lng };
                if (!out.from && s.in <= prev && prev < s.out) out.from = pt;
                if (!out.to && s.in <= date && date < s.out) out.to = pt;
            });
            var moves = raw.moves || [];
            function isBackSameDay(m) {
                var same = moves.filter(function (x) { return x.dep.slice(0, 10) === m.dep.slice(0, 10); }).sort(function (a, b) { return a.dep < b.dep ? -1 : a.dep > b.dep ? 1 : 0; });
                return same.length > 1 && same[same.length - 1].id === m.id;
            }
            moves.forEach(function (m) {
                var k = m.kind, depDate = m.dep.slice(0, 10), depMin = minOf(m.dep);
                var isBack = depDate === last && (depDate > start || isBackSameDay(m));
                if (!isBack && depDate <= start) {
                    var est = m.arr == null, arrDate = est ? depDate : m.arr.slice(0, 10), arrMin = est ? depMin + (T.typical[k] || 0) : minOf(m.arr);
                    if (arrDate === date && arrMin < 1440) {
                        var ready = arrMin + (T.exit[k] || 0);
                        if (out.arrive == null || ready > out.arrive) {
                            out.arrive = ready;
                            out.arriveText = m.name + (est ? " tới khoảng " : " tới ") + hm(arrMin) + ", cộng " + (T.exit[k] || 0) + " phút ra khỏi " + (STATION[k] || "bến");
                        }
                    }
                }
                // chặng giữa chuyến (cùng TripLogistics::dayContext)
                if (!isBack && depDate > start) {
                    var est2 = m.arr == null, aD = est2 ? depDate : m.arr.slice(0, 10), aM = est2 ? depMin + (T.typical[k] || 0) : minOf(m.arr);
                    if (est2 && aM >= 1440) { aD = addDays(depDate, 1); aM -= 1440; }
                    var from = Math.max(0, depMin - (T.before[k] || 0)), rdy = aM + (T.exit[k] || 0);
                    if (depDate === date && aD === date) {
                        out.blocks.push({ from: from, to: Math.min(1439, rdy), text: m.name + " " + hm(depMin) + "–" + (est2 ? "khoảng " : "") + hm(aM) + " (gồm thời gian ra " + (STATION[k] || "bến") + ")" });
                    } else if (depDate === date) {
                        if (out.leave == null || from < out.leave) {
                            out.leave = from;
                            out.leaveText = m.name + " khởi hành " + hm(depMin) + ", cần có mặt ở " + (STATION[k] || "bến") + " trước " + (T.before[k] || 0) + " phút";
                        }
                    } else if (aD === date && depDate < date) {
                        if (out.arrive == null || rdy > out.arrive) {
                            out.arrive = Math.min(1439, rdy);
                            out.arriveText = m.name + (est2 ? " tới khoảng " : " tới ") + hm(aM) + ", cộng " + (T.exit[k] || 0) + " phút ra khỏi " + (STATION[k] || "bến");
                        }
                    }
                }
                if (isBack && depDate === date) {
                    var need = depMin - (T.before[k] || 0);
                    if (out.leave == null || need < out.leave) {
                        out.leave = Math.max(0, need);
                        out.leaveText = m.name + " khởi hành " + hm(depMin) + ", cần có mặt ở " + (STATION[k] || "bến") + " trước " + (T.before[k] || 0) + " phút";
                    }
                }
            });
            return out;
        }

        return { rules: R, loadLabel: loadLabel, hoursCheck: hoursCheck, hoursFit: hoursFit, hoursText: hoursText, kindOf: kindOf, itemDur: itemDur, slotOf: slotOf, slotStart: slotStart, level: level, day: day, contract: contract, dayCtx: dayCtx };
    }

    var api = { create: create };
    root.TpSchedule = api;
})(typeof window !== "undefined" ? window : globalThis);
