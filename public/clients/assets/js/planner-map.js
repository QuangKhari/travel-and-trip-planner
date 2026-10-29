/* Bộ chuyển đổi bản đồ cho trình soạn chuyến đi: cùng một giao diện (adapter) cho Google Maps, MapLibre (bản đồ vector) và Leaflet/OpenStreetMap.
 *
 * - Thứ tự: Google Maps (có cfg.googleMapsKey và được Google chấp nhận) -> MapLibre (cfg.mapStyleUrl) -> Leaflet/OpenStreetMap.
 *   Mỗi bước lỗi (khóa bị từ chối, không tải được, trình duyệt không có WebGL) tự chuyển sang bước kế tiếp.
 * - Ghim đánh số theo ngày dùng chung một kiểu (.tp-pin) cho cả hai nền, nên giao diện giống nhau.
 * - Thẻ chi tiết địa điểm: dữ liệu Travela hiển thị trước; với Google, thêm giờ mở cửa / đánh giá / ảnh lấy TRỰC TIẾP từ Google khi
 *   người dùng bấm vào một ghim (không lưu vào cơ sở dữ liệu của Travela, theo điều khoản của Google).
 *
 * Giao diện adapter: kind, container(), onClick(cb), setPicking(on), clear(), marker(o), line(pts,color), geoLine(geojson,color),
 *   fit(pts,maxZoom), view(lat,lng,zoom), zoom(), preview(lat,lng,title), clearPreview(), resize(), details(item) -> Promise
 * Mọi chữ động gán bằng textContent (không innerHTML).
 */
(function () {
    "use strict";

    function pinEl(color, label, small) {
        var d = document.createElement("div");
        d.className = "tp-pin" + (small ? " tp-pin--sm" : "");
        d.style.background = color;
        d.textContent = label == null ? "" : String(label);
        return d;
    }

    /* ---------------- Leaflet ---------------- */
    function leafletAdapter(el) {
        var L = window.L;
        if (!L) return null;
        var map = L.map(el, { scrollWheelZoom: false });
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 18, attribution: "© OpenStreetMap" }).addTo(map);
        var layer = L.layerGroup().addTo(map), pv = L.layerGroup().addTo(map), clickCb = null, picking = false, byId = {};
        map.setView([16.0, 106.0], 5);
        map.on("click", function (e) {
            if (!clickCb) return;
            var ll = map.wrapLatLng ? map.wrapLatLng(e.latlng) : e.latlng;
            clickCb({ lat: ll.lat, lng: ll.lng });
        });
        return {
            kind: "leaflet",
            container: function () { return map.getContainer(); },
            onClick: function (cb) { clickCb = cb; },
            setPicking: function (on) { picking = !!on; map.getContainer().classList.toggle("is-picking", picking); },
            clear: function () { layer.clearLayers(); byId = {}; },
            marker: function (o) {
                var icon = L.divIcon({ className: "tp-pin-wrap", html: pinEl(o.color, o.label), iconSize: [30, 30], iconAnchor: [15, 15] });
                var mk = L.marker([o.lat, o.lng], { icon: icon, title: o.title || "", keyboard: true, riseOnHover: true }).addTo(layer);
                if (o.onClick) mk.on("click", o.onClick);
                if (o.id != null) byId[o.id] = mk;
                return mk;
            },
            highlight: function (id) {
                Object.keys(byId).forEach(function (k) {
                    var e = byId[k].getElement && byId[k].getElement();
                    if (e) e.classList.toggle("is-hot", String(k) === String(id));
                });
            },
            line: function (pts, color) {
                return L.polyline(pts.map(function (p) { return [p.lat, p.lng]; }), { color: color, weight: 4, opacity: 0.8 }).addTo(layer);
            },
            geoLine: function (g, color) { return L.geoJSON(g, { style: { color: color, weight: 4, opacity: 0.85 } }).addTo(layer); },
            removeLine: function (l) { if (l) layer.removeLayer(l); },
            fit: function (pts, maxZoom) {
                if (pts.length > 1) map.fitBounds(pts.map(function (p) { return [p.lat, p.lng]; }), { padding: [24, 24], maxZoom: maxZoom || 14 });
                else if (pts.length === 1) map.setView([pts[0].lat, pts[0].lng], 12);
            },
            view: function (lat, lng, z) { map.setView([lat, lng], z || map.getZoom()); },
            zoom: function () { return map.getZoom(); },
            preview: function (lat, lng, title) {
                pv.clearLayers();
                L.circleMarker([lat, lng], { radius: 9, color: "#b45309", weight: 3, fillColor: "#fbbf24", fillOpacity: 0.9 }).addTo(pv).bindTooltip(title || "");
            },
            clearPreview: function () { pv.clearLayers(); },
            resize: function () { map.invalidateSize(); },
            details: function () { return Promise.resolve(null); },
        };
    }

    /* ---------------- MapLibre (bản đồ vector) ---------------- */
    var MLIB = "https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl";
    var mlLoading = null;
    function loadMapLibre() {
        if (window.maplibregl) return Promise.resolve();
        if (mlLoading) return mlLoading;
        mlLoading = new Promise(function (resolve, reject) {
            var css = document.createElement("link");
            css.rel = "stylesheet"; css.href = MLIB + ".css";
            document.head.appendChild(css);
            var s = document.createElement("script");
            s.src = MLIB + ".js"; s.async = true;
            s.onload = function () { window.maplibregl ? resolve() : reject(new Error("maplibre missing")); };
            s.onerror = function () { mlLoading = null; reject(new Error("maplibre load failed")); };
            document.head.appendChild(s);
            setTimeout(function () { reject(new Error("maplibre timeout")); }, 12000);
        });
        return mlLoading;
    }

    function hasWebGL() {
        try {
            var c = document.createElement("canvas");
            return !!(window.WebGLRenderingContext && (c.getContext("webgl2") || c.getContext("webgl")));
        } catch (e) { return false; }
    }

    function maplibreAdapter(el, opts) {
        var ml = window.maplibregl;
        return new Promise(function (resolve, reject) {
            var map;
            try {
                map = new ml.Map({
                    container: el, style: opts.styleUrl, center: [106.0, 16.0], zoom: 4, attributionControl: { compact: true },
                    cooperativeGestures: true, dragRotate: false, pitchWithRotate: false,
                    locale: {
                        "CooperativeGesturesHandler.WindowsHelpText": "Giữ Ctrl và cuộn để phóng to bản đồ",
                        "CooperativeGesturesHandler.MacHelpText": "Giữ ⌘ và cuộn để phóng to bản đồ",
                        "CooperativeGesturesHandler.MobileHelpText": "Dùng hai ngón tay để di chuyển bản đồ",
                    },
                });
            } catch (e) { reject(e); return; }
            var settled = false;
            var timer = setTimeout(function () { if (!settled) { settled = true; try { map.remove(); } catch (e) { /* bỏ qua */ } reject(new Error("maplibre style timeout")); } }, 12000);
            map.on("error", function (ev) {
                if (settled) return;   // lỗi ô bản đồ sau khi đã tải xong: bỏ qua, bản đồ vẫn dùng được
                settled = true; clearTimeout(timer);
                try { map.remove(); } catch (e) { /* bỏ qua */ }
                reject((ev && ev.error) || new Error("maplibre style error"));
            });
            map.on("load", function () {
                if (settled) return;
                settled = true; clearTimeout(timer);
                map.addControl(new ml.NavigationControl({ showCompass: false }), "top-right");
                resolve(build(map));
            });
        });

        function build(map) {
            var marks = [], byId = {}, lineIds = [], seq = 0, pv = null, clickCb = null;
            map.on("click", function (e) { if (clickCb) clickCb({ lat: e.lngLat.lat, lng: e.lngLat.lng }); });
            function addLine(coords, color, opacity) {
                var id = "tp-line-" + (++seq);
                map.addSource(id, { type: "geojson", data: { type: "Feature", geometry: { type: "LineString", coordinates: coords } } });
                map.addLayer({ id: id, type: "line", source: id, layout: { "line-join": "round", "line-cap": "round" }, paint: { "line-color": color, "line-width": 4, "line-opacity": opacity } });
                lineIds.push(id);
                return id;
            }
            function dropLine(id) {
                if (map.getLayer(id)) map.removeLayer(id);
                if (map.getSource(id)) map.removeSource(id);
                lineIds = lineIds.filter(function (x) { return x !== id; });
            }
            function wrap(pin) {   // MapLibre đặt transform lên phần tử gốc, nên bọc ghim để hiệu ứng phóng to không bị đè
                var w = document.createElement("div");
                w.className = "tp-pin-wrap";
                w.appendChild(pin);
                return w;
            }
            return {
                kind: "vector",
                container: function () { return el; },
                onClick: function (cb) { clickCb = cb; },
                setPicking: function (on) {
                    el.classList.toggle("is-picking", !!on);
                    map.getCanvas().style.cursor = on ? "crosshair" : "";
                },
                clear: function () {
                    marks.forEach(function (m) { m.remove(); });
                    lineIds.slice().forEach(dropLine);
                    marks = []; byId = {};
                },
                marker: function (o) {
                    var pin = pinEl(o.color, o.label);
                    var w = wrap(pin);
                    if (o.title) w.title = o.title;
                    if (o.onClick) w.addEventListener("click", function (ev) { ev.stopPropagation(); o.onClick(ev); });
                    var m = new ml.Marker({ element: w, anchor: "center" }).setLngLat([o.lng, o.lat]).addTo(map);
                    marks.push(m);
                    if (o.id != null) byId[o.id] = pin;
                    return m;
                },
                highlight: function (id) {
                    Object.keys(byId).forEach(function (k) { byId[k].classList.toggle("is-hot", String(k) === String(id)); });
                },
                line: function (pts, color) {
                    return addLine(pts.map(function (p) { return [p.lng, p.lat]; }), color, 0.85);
                },
                geoLine: function (geo, color) {
                    return addLine(geo && geo.coordinates ? geo.coordinates : [], color, 0.9);
                },
                removeLine: function (id) { if (id) dropLine(id); },
                fit: function (pts, maxZoom) {
                    if (pts.length > 1) {
                        var b = new ml.LngLatBounds();
                        pts.forEach(function (p) { b.extend([p.lng, p.lat]); });
                        map.fitBounds(b, { padding: 56, maxZoom: maxZoom || 14, duration: 0 });
                    } else if (pts.length === 1) map.jumpTo({ center: [pts[0].lng, pts[0].lat], zoom: 12 });
                },
                view: function (lat, lng, z) { map.jumpTo({ center: [lng, lat], zoom: z || map.getZoom() }); },
                zoom: function () { return map.getZoom(); },
                preview: function (lat, lng, title) {
                    if (pv) pv.remove();
                    var w = wrap(pinEl("#f59e0b", "+"));
                    if (title) w.title = title;
                    pv = new ml.Marker({ element: w, anchor: "center" }).setLngLat([lng, lat]).addTo(map);
                },
                clearPreview: function () { if (pv) { pv.remove(); pv = null; } },
                resize: function () { map.resize(); },
                details: function () { return Promise.resolve(null); },
            };
        }
    }

    /* ---------------- Google Maps ---------------- */
    var gLoading = null;
    function loadGoogle(key, onAuthFail) {
        if (window.google && window.google.maps && window.google.maps.importLibrary) return Promise.resolve();
        if (gLoading) return gLoading;
        gLoading = new Promise(function (resolve, reject) {
            window.gm_authFailure = function () { if (onAuthFail) onAuthFail(); };
            window.__tpGmapsReady = function () { resolve(); };
            var s = document.createElement("script");
            s.src = "https://maps.googleapis.com/maps/api/js?key=" + encodeURIComponent(key) + "&v=weekly&loading=async&language=vi&region=VN&callback=__tpGmapsReady";
            s.async = true;
            s.onerror = function () { gLoading = null; reject(new Error("google maps load failed")); };
            document.head.appendChild(s);
            setTimeout(function () { reject(new Error("google maps timeout")); }, 12000);
        });
        return gLoading;
    }

    function googleAdapter(el, opts) {
        var g = window.google.maps;
        return Promise.all([g.importLibrary("maps"), g.importLibrary("marker"), g.importLibrary("places")]).then(function (libs) {
            var map = new g.Map(el, {
                center: { lat: 16.0, lng: 106.0 }, zoom: 5, mapId: opts.mapId || "DEMO_MAP_ID", gestureHandling: "cooperative",
                streetViewControl: false, mapTypeControl: true, fullscreenControl: false, clickableIcons: false,
            });
            var Adv = libs[1].AdvancedMarkerElement, Place = libs[2].Place;
            var marks = [], lines = [], pv = null, clickCb = null, byId = {}, detailCache = {};
            map.addListener("click", function (e) { if (clickCb && e.latLng) clickCb({ lat: e.latLng.lat(), lng: e.latLng.lng() }); });
            return {
                kind: "google",
                container: function () { return el; },
                onClick: function (cb) { clickCb = cb; },
                setPicking: function (on) { map.setOptions({ draggableCursor: on ? "crosshair" : null }); el.classList.toggle("is-picking", !!on); },
                clear: function () {
                    marks.forEach(function (m) { m.map = null; });
                    lines.forEach(function (l) { l.setMap(null); });
                    marks = []; lines = []; byId = {};
                },
                marker: function (o) {
                    var m = new Adv({ map: map, position: { lat: o.lat, lng: o.lng }, content: pinEl(o.color, o.label), title: o.title || "", gmpClickable: !!o.onClick });
                    if (o.onClick) m.addListener("click", o.onClick);
                    marks.push(m);
                    if (o.id != null) byId[o.id] = m;
                    return m;
                },
                highlight: function (id) {
                    Object.keys(byId).forEach(function (k) {
                        var c = byId[k].content;
                        if (c && c.classList) c.classList.toggle("is-hot", String(k) === String(id));
                        byId[k].zIndex = String(k) === String(id) ? 999 : null;
                    });
                },
                line: function (pts, color) {
                    var l = new g.Polyline({ map: map, path: pts.map(function (p) { return { lat: p.lat, lng: p.lng }; }), strokeColor: color, strokeOpacity: 0.85, strokeWeight: 4 });
                    lines.push(l);
                    return l;
                },
                geoLine: function (geo, color) {
                    var c = geo && geo.coordinates ? geo.coordinates : [];
                    var l = new g.Polyline({ map: map, path: c.map(function (x) { return { lat: x[1], lng: x[0] }; }), strokeColor: color, strokeOpacity: 0.9, strokeWeight: 4 });
                    lines.push(l);
                    return l;
                },
                removeLine: function (l) { if (l) { l.setMap(null); lines = lines.filter(function (x) { return x !== l; }); } },
                fit: function (pts, maxZoom) {
                    if (pts.length > 1) {
                        var b = new g.LatLngBounds();
                        pts.forEach(function (p) { b.extend({ lat: p.lat, lng: p.lng }); });
                        map.fitBounds(b, 56);
                        g.event.addListenerOnce(map, "idle", function () { if (map.getZoom() > (maxZoom || 14)) map.setZoom(maxZoom || 14); });
                    } else if (pts.length === 1) { map.setCenter({ lat: pts[0].lat, lng: pts[0].lng }); map.setZoom(12); }
                },
                view: function (lat, lng, z) { map.setCenter({ lat: lat, lng: lng }); if (z) map.setZoom(z); },
                zoom: function () { return map.getZoom() || 5; },
                preview: function (lat, lng, title) {
                    if (pv) pv.map = null;
                    pv = new Adv({ map: map, position: { lat: lat, lng: lng }, content: pinEl("#f59e0b", "+"), title: title || "" });
                },
                clearPreview: function () { if (pv) { pv.map = null; pv = null; } },
                resize: function () { g.event.trigger(map, "resize"); },
                /* Chi tiết trực tiếp từ Google (không lưu): giờ mở cửa, đánh giá, ảnh. Trả null nếu không tìm thấy. */
                details: function (item) {
                    var key = item.type + ":" + (item.refId || item.name) + ":" + item.lat + "," + item.lng;
                    if (detailCache[key] !== undefined) return Promise.resolve(detailCache[key]);
                    var req = { textQuery: item.name, fields: ["displayName", "formattedAddress", "rating", "userRatingCount", "regularOpeningHours", "photos", "googleMapsURI", "websiteURI", "nationalPhoneNumber"], maxResultCount: 1, language: "vi", region: "VN" };
                    if (item.lat != null && item.lng != null) req.locationBias = { center: { lat: item.lat, lng: item.lng }, radius: 3000 };
                    return Place.searchByText(req).then(function (r) {
                        var p = r && r.places && r.places[0];
                        if (!p) { detailCache[key] = null; return null; }
                        var photos = (p.photos || []).slice(0, 6).map(function (ph) {
                            return { url: ph.getURI({ maxWidth: 640 }), by: (ph.authorAttributions && ph.authorAttributions[0] && ph.authorAttributions[0].displayName) || "" };
                        });
                        var out = {
                            name: p.displayName || "", address: p.formattedAddress || "", rating: p.rating == null ? null : p.rating, ratings: p.userRatingCount || 0,
                            hours: p.regularOpeningHours && p.regularOpeningHours.weekdayDescriptions ? p.regularOpeningHours.weekdayDescriptions : [],
                            phone: p.nationalPhoneNumber || "", website: p.websiteURI || "", mapsUrl: p.googleMapsURI || "", photos: photos,
                        };
                        detailCache[key] = out;
                        return out;
                    }).catch(function () { detailCache[key] = null; return null; });
                },
            };
        });
    }

    /**
     * @param {HTMLElement} el
     * @param {{googleMapsKey?:string, mapId?:string, styleUrl?:string, skipGoogle?:boolean, forceLeaflet?:boolean, onAuthFail?:function}} opts
     * @returns {Promise<object|null>}
     */
    function create(el, opts) {
        opts = opts || {};
        function leaflet() { el.textContent = ""; return leafletAdapter(el); }
        function vector() {
            if (!opts.styleUrl || !hasWebGL()) return Promise.resolve(leaflet());
            return loadMapLibre()
                .then(function () { return maplibreAdapter(el, opts); })
                .catch(function () { return leaflet(); });   // không tải được bản đồ vector: dùng OpenStreetMap (Leaflet)
        }
        if (opts.forceLeaflet) return Promise.resolve(leaflet());
        if (opts.skipGoogle || !opts.googleMapsKey) return vector();
        return loadGoogle(opts.googleMapsKey, opts.onAuthFail)
            .then(function () { return googleAdapter(el, opts); })
            .catch(function () { el.textContent = ""; return vector(); });
    }

    window.TpMap = { create: create, pinEl: pinEl };
})();
