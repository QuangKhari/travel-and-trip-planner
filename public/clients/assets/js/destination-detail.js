/* Trang chi tiết điểm đến: bản đồ OSM (Leaflet) + lọc/bấm địa điểm. Dữ liệu từ #ddData (đã có sẵn trong DB). */
(function () {
    "use strict";
    var el = document.getElementById("ddData");
    var mapEl = document.getElementById("ddMap");
    if (!el || !mapEl || !window.L) return;

    var d;
    try {
        d = JSON.parse(el.textContent);
    } catch (e) {
        return;
    }

    var map = L.map(mapEl, { scrollWheelZoom: false }).setView(
        [d.lat, d.lng],
        11,
    );
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 18,
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    var COLORS = { see: "#1f8a70", culture: "#c9822b", eat: "#d4503a" };
    L.circleMarker([d.lat, d.lng], {
        radius: 9,
        color: "#fff",
        weight: 3,
        fillColor: "#0b3a5c",
        fillOpacity: 1,
    })
        .addTo(map)
        .bindTooltip(d.name, { permanent: false });

    var markers = {};
    var bounds = [[d.lat, d.lng]];
    Object.keys(d.places || {}).forEach(function (cat) {
        d.places[cat].forEach(function (p) {
            var m = L.circleMarker([p.lat, p.lng], {
                radius: 7,
                color: "#fff",
                weight: 2,
                fillColor: COLORS[cat] || "#555",
                fillOpacity: 0.95,
            });
            var div = document.createElement("div");
            var b = document.createElement("strong");
            b.textContent = p.name;
            div.appendChild(b);
            if (p.address) {
                div.appendChild(document.createElement("br"));
                div.appendChild(document.createTextNode(p.address));
            }
            m.bindPopup(div);
            m.addTo(map);
            markers[p.id] = { m: m, cat: cat };
            bounds.push([p.lat, p.lng]);
        });
    });
    if (bounds.length > 1)
        map.fitBounds(bounds, { padding: [24, 24], maxZoom: 13 });

    var tabs = document.querySelectorAll("#ddTabs button");
    var items = document.querySelectorAll("#ddPlaces li");
    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener("click", function () {
            var cat = t.getAttribute("data-cat");
            Array.prototype.forEach.call(tabs, function (x) {
                x.classList.toggle("is-on", x === t);
            });
            Array.prototype.forEach.call(items, function (li) {
                li.hidden = !(
                    cat === "all" || li.getAttribute("data-cat") === cat
                );
            });
            Object.keys(markers).forEach(function (id) {
                var show = cat === "all" || markers[id].cat === cat;
                if (show) markers[id].m.addTo(map);
                else map.removeLayer(markers[id].m);
            });
        });
    });
    Array.prototype.forEach.call(items, function (li) {
        li.querySelector("button").addEventListener("click", function () {
            var o = markers[li.getAttribute("data-id")];
            if (!o) return;
            map.setView(o.m.getLatLng(), 15);
            o.m.openPopup();
            mapEl.scrollIntoView({ block: "nearest", behavior: "smooth" });
        });
    });
})();
