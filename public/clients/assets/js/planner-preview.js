/* Màn xem trước lịch tự đề xuất: bản đồ nhỏ (Leaflet + OpenStreetMap), ghim đánh số theo ngày, bật/tắt từng ngày,
 * rê chuột vào mục trong lịch thì sáng ghim tương ứng (và ngược lại). Không có mạng / không tải được Leaflet: ẩn bản đồ, trang vẫn dùng bình thường. */
(function () {
    "use strict";
    var save = document.getElementById("pvSave");
    if (save) save.addEventListener("submit", function () {
        var b = save.querySelector("button");
        if (b) { b.disabled = true; b.textContent = "Đang lưu…"; }
    });
    window.addEventListener("pageshow", function () { var b = save && save.querySelector("button"); if (b) b.disabled = false; });

    var box = document.getElementById("pvMap");
    if (!box) return;
    var data = {};
    try { data = JSON.parse(document.getElementById("pvData").textContent) || {}; } catch (e) { return; }
    function start() {
        var L = window.L;
        if (!L) { box.closest("section").hidden = true; return; }
        var map = L.map(box, { scrollWheelZoom: false, zoomControl: true });
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 18, attribution: "© OpenStreetMap" }).addTo(map);
        var layers = {}, byKey = {}, all = [];
        (data.days || []).forEach(function (d) {
            var color = data.colors[(d.day - 1) % data.colors.length], g = L.layerGroup(), pts = [];
            d.items.forEach(function (it, i) {
                var pin = document.createElement("div");
                pin.className = "tp-pin";
                pin.style.background = color;
                pin.textContent = String(i + 1);
                var mk = L.marker([it.lat, it.lng], { icon: L.divIcon({ className: "tp-pin-wrap", html: pin, iconSize: [26, 26], iconAnchor: [13, 13] }), title: "Ngày " + d.day + " · " + it.name, keyboard: false });
                mk.on("mouseover", function () { hot(it.key, true); });
                mk.on("mouseout", function () { hot(it.key, false); });
                mk.on("click", function () {
                    var li = document.querySelector('.tp-pv__it[data-key="' + CSS.escape(it.key) + '"]');
                    if (li) li.scrollIntoView({ behavior: "smooth", block: "center" });
                });
                mk.addTo(g);
                byKey[it.key] = pin;
                pts.push([it.lat, it.lng]);
            });
            if (pts.length > 1) L.polyline(pts, { color: color, weight: 3, opacity: .7, dashArray: "6 6" }).addTo(g);
            g.addTo(map);
            layers[d.day] = { g: g, pts: pts, color: color };
            all = all.concat(pts);
        });
        function fit() {
            var pts = [];
            Object.keys(layers).forEach(function (k) { if (map.hasLayer(layers[k].g)) pts = pts.concat(layers[k].pts); });
            if (pts.length > 1) map.fitBounds(pts, { padding: [24, 24], maxZoom: 15 });
            else if (pts.length === 1) map.setView(pts[0], 14);
            else if (data.center) map.setView(data.center, 12);
        }
        function hot(key, on) {
            if (byKey[key]) byKey[key].classList.toggle("is-hot", on);
            var li = document.querySelector('.tp-pv__it[data-key="' + CSS.escape(key) + '"]');
            if (li) li.classList.toggle("is-hot", on);
        }
        document.querySelectorAll(".tp-pv__it[data-key]").forEach(function (li) {
            li.addEventListener("mouseenter", function () { hot(li.dataset.key, true); });
            li.addEventListener("mouseleave", function () { hot(li.dataset.key, false); });
        });
        var bar = document.getElementById("pvMapDays");
        Object.keys(layers).forEach(function (k) {
            if (!layers[k].pts.length) return;
            var b = document.createElement("button");
            b.type = "button";
            b.className = "tp-chip";
            b.setAttribute("aria-pressed", "true");
            var sw = document.createElement("span");
            sw.className = "sw";
            sw.style.background = layers[k].color;
            b.appendChild(sw);
            b.appendChild(document.createTextNode("Ngày " + k));
            b.addEventListener("click", function () {
                var on = b.getAttribute("aria-pressed") !== "true";
                b.setAttribute("aria-pressed", on ? "true" : "false");
                if (on) layers[k].g.addTo(map); else map.removeLayer(layers[k].g);
                fit();
            });
            bar.appendChild(b);
        });
        fit();
        setTimeout(function () { map.invalidateSize(); fit(); }, 200);
    }
    if (window.L) start();
    else window.addEventListener("load", start);
})();
