/* Lớp gọi máy chủ của trình soạn chuyến đi (tách khỏi planner.js).
 * Mọi yêu cầu đi qua đây: cùng header (JSON, AJAX, CSRF), cùng cách báo lỗi; planner.js chỉ lo giao diện và trạng thái.
 * Các API (đường dẫn lấy từ cfg do PlannerController::edit gửi):
 *   save      POST cfg.saveUrl            lưu cả chuyến (optimistic locking bằng rev, 409 khi người khác vừa lưu)
 *   health    GET  cfg.healthUrl          điểm sức khỏe + kiểm tra 5 nhóm ràng buộc (bản đã lưu)
 *   resolve   GET  cfg.resolveUrl         phương án xử lý một vấn đề (xem trước, không ghi)
 *   optimize  POST cfg.optimizeUrl        thứ tự đề xuất cho một ngày (RouteService, không ghi)
 *   around    GET  cfg.aroundUrl          điểm dừng quanh tuyến
 */
(function (root) {
    "use strict";

    function create(cfg) {
        var H = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
        /** fetch thô (trả Response) cho nơi cần tự xử lý mã trạng thái. */
        function raw(url) { return fetch(url, { credentials: "same-origin", headers: H }); }
        /** GET JSON; mã lỗi HTTP -> Promise bị từ chối với mã trạng thái. */
        function getJson(url) {
            // quá 20 giây coi như lỗi để giao diện không đứng im mãi
            var ctl = typeof AbortController !== "undefined" ? new AbortController() : null, t = ctl ? setTimeout(function () { ctl.abort(); }, 20000) : null;
            return fetch(url, { credentials: "same-origin", headers: H, signal: ctl ? ctl.signal : undefined })
                .then(function (r) { clearTimeout(t); return r.ok ? r.json() : Promise.reject(r.status); }, function (e) { clearTimeout(t); return Promise.reject(e); });
        }
        /** POST JSON kèm CSRF; trả Response để nơi gọi đọc được 409 / 422. */
        function post(url, body) {
            return fetch(url, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf || "", "X-Requested-With": "XMLHttpRequest" },
                body: JSON.stringify(body),
            });
        }
        function qs(o) {
            return Object.keys(o).filter(function (k) { return o[k] != null && o[k] !== ""; })
                .map(function (k) { return encodeURIComponent(k) + "=" + encodeURIComponent(o[k]); }).join("&");
        }
        return {
            raw: raw, getJson: getJson, post: post,
            health: function () { return cfg.healthUrl ? getJson(cfg.healthUrl) : Promise.reject(0); },
            resolve: function (c) { return getJson(cfg.resolveUrl + "?" + qs({ code: c.code, day: c.day, index: c.index })); },
        };
    }

    root.TpApi = { create: create };
})(typeof window !== "undefined" ? window : globalThis);
