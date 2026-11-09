/* Hàm thuần của Trip Planner (khoảng cách, thời gian di chuyển, tối ưu thứ tự trong ngày).
 * Không đụng DOM nên chạy được cả trong trình duyệt (window.TpGeo) lẫn Node (module.exports) để kiểm thử.
 * Vai trò: XEM TRƯỚC tức thì ở trình duyệt. Nguồn sự thật là RouteService phía máy chủ (lưu, sức khỏe chuyến đi, tối ưu tuyến).
 * Tham số không khai báo ở đây: trang sửa gọi TpGeo.configure(cfg.rules.route) với đúng số của PlannerRules::route(),
 * nên khi máy chủ dùng ước tính (haversine) hai bên ra cùng con số (PlannerContractTest kiểm tra).
 * Giá trị dưới đây chỉ là mặc định khi chạy ngoài trang (kiểm thử Node cũ).
 */
(function (root) {
    "use strict";
    var ROAD_FACTOR = 1.3, SPEED_KMH = 40, MIN_LEG_KM = 0.5;

    /** Nhận tham số tuyến từ máy chủ: {factor, speedKmh, minLegKm}. */
    function configure(r) {
        if (!r) return api;
        if (r.factor > 0) ROAD_FACTOR = +r.factor;
        if (r.speedKmh > 0) SPEED_KMH = +r.speedKmh;
        if (r.minLegKm >= 0) MIN_LEG_KM = +r.minLegKm;
        api.ROAD_FACTOR = ROAD_FACTOR; api.SPEED_KMH = SPEED_KMH; api.MIN_LEG_KM = MIN_LEG_KM;
        return api;
    }

    function hav(a, b) {
        var R = 6371, rad = Math.PI / 180;
        var dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
        var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
    }

    /** Tọa độ dùng được (cùng quy tắc HaversineProvider::validPoint): số, trong phạm vi, không phải (0,0) do geocode hỏng. */
    function hasPos(p) {
        if (!p || p.lat == null || p.lng == null || p.lat === "" || p.lng === "") return false;
        var a = +p.lat, b = +p.lng;
        return isFinite(a) && isFinite(b) && a >= -90 && a <= 90 && b >= -180 && b <= 180 && !(Math.abs(a) < 1e-6 && Math.abs(b) < 1e-6);
    }

    /** Các đoạn giữa hai điểm có tọa độ liên tiếp; đoạn dưới MIN_LEG_KM (cùng một khu) bị bỏ như phía máy chủ. */
    function legs(items) {
        var pts = items.filter(hasPos), out = [], km = 0, min = 0;
        for (var i = 1; i < pts.length; i++) {
            var s = hav(pts[i - 1], pts[i]);
            if (s < MIN_LEG_KM) continue;
            var road = s * ROAD_FACTOR, m = Math.round(road / SPEED_KMH * 60);
            out.push({ from: pts[i - 1], to: pts[i], km: Math.round(road), kmExact: road, min: m });
            km += road;
            min += m;
        }
        return { legs: out, km: Math.round(km), kmExact: km, minutes: min };
    }

    function pathLen(order, pts) {
        var s = 0;
        for (var i = 1; i < order.length; i++) s += hav(pts[order[i - 1]], pts[order[i]]);
        return s;
    }

    /**
     * Sắp lại thứ tự điểm để đường đi ngắn hơn: láng giềng gần nhất rồi 2-opt (đường hở).
     * @param pts   mảng {lat,lng}
     * @param keepFirst true: giữ nguyên điểm đầu tiên làm điểm xuất phát
     * @return mảng chỉ số của pts theo thứ tự mới
     */
    function optimize(pts, keepFirst) {
        var n = pts.length, i, j;
        if (n < 3) return pts.map(function (_, k) { return k; });
        // Điểm xuất phát: điểm đầu nếu keepFirst, ngược lại thử mọi điểm đầu và lấy đường ngắn nhất
        var starts = keepFirst ? [0] : pts.map(function (_, k) { return k; });
        var best = null, bestLen = Infinity;
        starts.forEach(function (s) {
            var left = [], order = [s];
            for (i = 0; i < n; i++) if (i !== s) left.push(i);
            while (left.length) {
                var cur = order[order.length - 1], bi = 0, bd = Infinity;
                for (i = 0; i < left.length; i++) {
                    var d = hav(pts[cur], pts[left[i]]);
                    if (d < bd) { bd = d; bi = i; }
                }
                order.push(left.splice(bi, 1)[0]);
            }
            var improved = true, guard = 0;
            while (improved && guard++ < 50) {
                improved = false;
                for (i = keepFirst || starts.length === 1 ? 1 : 0; i < n - 1; i++) {
                    for (j = i + 1; j < n; j++) {
                        var cand = order.slice(0, i).concat(order.slice(i, j + 1).reverse(), order.slice(j + 1));
                        if (pathLen(cand, pts) + 1e-9 < pathLen(order, pts)) { order = cand; improved = true; }
                    }
                }
            }
            var len = pathLen(order, pts);
            if (len < bestLen) { bestLen = len; best = order; }
        });
        return best;
    }

    /** Ước tính quãng đường (km, đã nhân hệ số đường bộ) của một thứ tự. */
    function roadKm(pts) { return legs(pts).km; }
    function roadKmExact(pts) {
        var s = 0;
        for (var i = 1; i < pts.length; i++) s += hav(pts[i - 1], pts[i]);
        return s * ROAD_FACTOR;
    }

    /**
     * Đọc tọa độ từ nội dung người dùng dán: link Google Maps đầy đủ (ghim !3d..!4d.., khung nhìn @lat,lng, ?q=lat,lng),
     * link OpenStreetMap (#map=z/lat/lng, mlat/mlon) hoặc chỉ "21.0285, 105.8542". Link rút gọn (goo.gl, maps.app) không chứa tọa độ.
     * @return {lat:number,lng:number}|null  (làm tròn 6 chữ số thập phân; ngoài khoảng ±90/±180 thì null)
     */
    function parseMapsInput(raw) {
        var t = String(raw == null ? "" : raw).trim();
        if (!t) return null;
        try { t = decodeURIComponent(t); } catch (e) { /* giữ nguyên */ }
        var NUM = "(-?\\d+(?:\\.\\d+)?)";
        function ok(la, lo) {
            la = parseFloat(la); lo = parseFloat(lo);
            if (!isFinite(la) || !isFinite(lo) || Math.abs(la) > 90 || Math.abs(lo) > 180) return null;
            return { lat: Math.round(la * 1e6) / 1e6, lng: Math.round(lo * 1e6) / 1e6 };
        }
        var m;
        if ((m = t.match(new RegExp("!3d" + NUM + "!4d" + NUM)))) return ok(m[1], m[2]);                                  // ghim địa điểm (chính xác nhất)
        if ((m = t.match(new RegExp("@" + NUM + "," + NUM)))) return ok(m[1], m[2]);                                      // tâm khung nhìn
        if ((m = t.match(new RegExp("[?&](?:q|query|ll|destination|center)=" + NUM + "[,+ ]+" + NUM)))) return ok(m[1], m[2]);
        if ((m = t.match(new RegExp("[?&]mlat=" + NUM + "&mlon=" + NUM)))) return ok(m[1], m[2]);                         // OpenStreetMap
        if ((m = t.match(new RegExp("#map=\\d+/" + NUM + "/" + NUM)))) return ok(m[1], m[2]);
        if ((m = t.match(new RegExp("^" + NUM + "\\s*[,;\\s]\\s*" + NUM + "$")))) return ok(m[1], m[2]);                  // "21.0285, 105.8542"
        return null;
    }
    /** Link rút gọn: không đọc được tọa độ, giao diện nhắc người dùng chép link đầy đủ. */
    function isShortMapsLink(raw) { return /goo\.gl|maps\.app|g\.co\/kgs/i.test(String(raw || "")); }
    function mapsUrl(lat, lng) { return "https://www.google.com/maps?q=" + lat + "," + lng; }

    var api = { configure: configure, ROAD_FACTOR: ROAD_FACTOR, SPEED_KMH: SPEED_KMH, MIN_LEG_KM: MIN_LEG_KM, hav: hav, hasPos: hasPos, legs: legs, optimize: optimize, roadKm: roadKm, roadKmExact: roadKmExact, parseMapsInput: parseMapsInput, isShortMapsLink: isShortMapsLink, mapsUrl: mapsUrl };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    root.TpGeo = api;
})(typeof window !== "undefined" ? window : globalThis);
