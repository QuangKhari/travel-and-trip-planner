/* Hợp đồng lưu chuyến đi (POST cfg.saveUrl, App\Services\Planner\TripPlanner::tripRules() + itemRules()).
 * Tách khỏi planner.js: hàm thuần, không đụng DOM -> kiểm thử được bằng Node.
 *   TpSave.body({meta, items, rain, rev, days}) -> thân yêu cầu gửi máy chủ
 * Mục tự thêm (note / transport) gửi tên + tọa độ; mục trong kho chỉ gửi type + refId (máy chủ tự giải nghĩa).
 */
(function (root) {
    "use strict";
    var CUSTOM = { note: true, transport: true };
    function hasPos(p) { return p && p.lat != null && p.lng != null && isFinite(p.lat) && isFinite(p.lng); }

    function rainPlan(rain, days) {
        var o = {};
        Object.keys(rain || {}).forEach(function (k) {
            if (+k >= 1 && +k <= days) o[k] = rain[k].map(function (r) { return { type: r.type, refId: r.refId }; });
        });
        return o;
    }
    function item(x) {
        var o = { type: x.type, refId: CUSTOM[x.type] ? null : x.refId, day: x.day, note: x.note, cost: x.cost, slot: x.slot || null, reasons: x.reasons || [],
            startMin: x.startMin == null ? null : x.startMin, durationMin: x.durationMin || null, costType: x.cost != null ? (x.costType || null) : null, locked: !!x.locked };
        if (CUSTOM[x.type]) {
            o.label = x.name;
            if (hasPos(x)) { o.lat = x.lat; o.lng = x.lng; o.source = x.source || "map"; }
        }
        return o;
    }
    /** @param s {meta:{title,start,days,group,budget,dest}, items:[], rain:{}, rev:number} */
    function body(s) {
        var m = s.meta;
        return {
            rev: s.rev,
            title: m.title, startDate: m.start, days: m.days, groupSize: m.group, budget: m.budget, destinationId: m.dest,
            rainPlan: rainPlan(s.rain, m.days),
            items: s.items.map(item),
        };
    }

    root.TpSave = { body: body, item: item, rainPlan: rainPlan };
})(typeof window !== "undefined" ? window : globalThis);
