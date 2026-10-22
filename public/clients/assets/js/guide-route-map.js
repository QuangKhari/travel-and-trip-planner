/* B5: bản đồ Leaflet vẽ tuyến lịch trình mẫu. Điểm lấy từ #gxPoints (JSON do máy chủ sinh từ tọa độ trong DB). */
(function () {
    "use strict";
    var box = document.getElementById("gxMap");
    var raw = document.getElementById("gxPoints");
    if (!box || !raw || typeof L === "undefined") return;
    var pts;
    try {
        pts = JSON.parse(raw.textContent);
    } catch (e) {
        return;
    }
    if (!pts.length) return;
    var map = L.map(box, { scrollWheelZoom: false });
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 18, attribution: "© OpenStreetMap" }).addTo(map);
    var colors = ["#087f7b", "#e07a1f", "#6d4bc3", "#c2410c", "#0f766e", "#be185d", "#4d7c0f"];
    var byDay = {};
    pts.forEach(function (p) {
        (byDay[p.day] = byDay[p.day] || []).push(p);
    });
    var all = [];
    Object.keys(byDay).forEach(function (d, i) {
        var c = colors[i % colors.length];
        var ll = byDay[d].map(function (p) {
            return [p.lat, p.lng];
        });
        all = all.concat(ll);
        if (ll.length > 1) L.polyline(ll, { color: c, weight: 4, opacity: 0.85 }).addTo(map);
        byDay[d].forEach(function (p, n) {
            var m = L.circleMarker([p.lat, p.lng], { radius: 8, color: "#fff", weight: 2, fillColor: c, fillOpacity: 1 }).addTo(map);
            /* Leaflet gán chuỗi vào innerHTML: truyền phần tử DOM với textContent để tên địa điểm không chạy được mã */
            var tip = document.createElement("span");
            tip.textContent = "Ngày " + p.day + " · " + (n + 1) + ". " + p.label;
            m.bindTooltip(tip);
        });
    });
    map.fitBounds(all, { padding: [30, 30], maxZoom: 12 });
})();
