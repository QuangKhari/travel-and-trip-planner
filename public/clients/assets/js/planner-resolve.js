/* Áp dụng một phương án của Resolution Engine (TripResolver phía máy chủ) lên danh sách mục ở trình duyệt.
 * CÙNG ngữ nghĩa với TripResolver::apply() — đó là điều kiện để "xem trước = áp dụng" (PlannerInvariantTest chạy file này bằng Node):
 *   move     : bỏ giờ cố định, đưa xuống CUỐI ngày nhận (toDay = null -> về "Đã lưu")
 *   time     : đặt / bỏ giờ cố định
 *   reorder  : sắp lại thứ tự trong ngày theo order (chỉ số trên bản đã lưu)
 *   replace  : chèn mục mới đúng chỗ, giữ giờ cố định + buổi của mục cũ; mục cũ về "Đã lưu"
 *   rainplan : lưu phương án dự phòng khi mưa cho ngày (lịch không đổi)
 * Mục được chỉ bằng (ngày, vị trí trong ngày) trên BẢN ĐÃ LƯU: chỉ gọi khi không còn thay đổi chưa lưu.
 * Hàm thuần (không DOM). Trả {ok:false} nếu lịch đã khác bản đề xuất (vị trí không còn mục).
 */
(function (root) {
    "use strict";

    function dayItems(items, d) { return items.filter(function (x) { return x.day === d; }); }
    function moveItem(items, it, day) {
        items.splice(items.indexOf(it), 1);
        it.day = day;
        items.push(it);
    }
    /**
     * @param items mảng mục (bị sửa tại chỗ)
     * @param rain  {ngày: [...]} phương án mưa (bị sửa tại chỗ)
     * @param changes thao tác từ máy chủ
     * @param newItem hàm dựng mục mới từ dữ liệu máy chủ (planner.js truyền vào)
     * @param rainMax số mục dự phòng tối đa mỗi ngày
     */
    function apply(items, rain, changes, newItem, rainMax) {
        var refs = changes.map(function (c) {
            return { c: c, it: c.op === "reorder" || c.op === "rainplan" ? null : dayItems(items, c.day)[c.index], list: c.op === "reorder" ? dayItems(items, c.day) : null };
        });
        if (refs.some(function (r) { return r.c.op !== "reorder" && r.c.op !== "rainplan" && !r.it; })) return { ok: false };
        refs.forEach(function (r) {
            var c = r.c;
            if (c.op === "time") r.it.startMin = c.startMin;
            else if (c.op === "move") { r.it.startMin = null; moveItem(items, r.it, c.toDay); }
            else if (c.op === "rainplan") {
                rain[String(c.day)] = c.items.slice(0, rainMax || 4).map(function (x) {
                    return { type: x.type, refId: x.refId, name: x.name, sub: x.sub || "", url: x.url || null, lat: x.lat, lng: x.lng };
                });
            } else if (c.op === "replace") {
                var n = newItem(Object.assign({}, c.with, { day: c.day }));
                n.startMin = r.it.startMin;
                n.slot = r.it.slot;
                n.hours = c.with.hours || null;
                items.splice(items.indexOf(r.it), 0, n);
                r.it.day = null;
                r.it.startMin = null;
            } else if (c.op === "reorder") {
                var slots = r.list.map(function (x) { return items.indexOf(x); }).sort(function (a, b) { return a - b; });
                c.order.forEach(function (k, j) { items[slots[j]] = r.list[k]; });
            }
        });
        return { ok: true };
    }

    root.TpResolve = { apply: apply };
})(typeof window !== "undefined" ? window : globalThis);
