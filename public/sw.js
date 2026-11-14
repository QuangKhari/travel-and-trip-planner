/*
 * Service worker của Travela (PWA):
 *  1. Mở lại trang chuyến đi khi MẤT MẠNG: trang /len-ke-hoach/{id} lấy mạng trước, thành công thì lưu bản sao; mất mạng thì trả bản sao
 *     (lịch nằm sẵn trong trang nên "Hôm nay", giờ đi, chỉ đường vẫn xem được; sửa khi mất mạng được giữ ở bản nháp của trình duyệt).
 *  2. CSS/JS/ảnh tĩnh: lấy bộ nhớ đệm trước, cập nhật ngầm.
 *  3. Thông báo đẩy: máy chủ chỉ "gõ cửa" (không gửi nội dung); service worker tự hỏi /thong-bao/moi-nhat rồi hiện thông báo.
 * Không đụng tới yêu cầu POST, API, trang quản trị, thanh toán.
 */
const VERSION = "tv-pwa-1";
const PAGES = VERSION + "-pages";
const STATIC = VERSION + "-static";

self.addEventListener("install", (e) => { self.skipWaiting(); });
self.addEventListener("activate", (e) => {
    e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

function isTripPage(url) { return /^\/len-ke-hoach\/\d+\/?$/.test(url.pathname); }
function isStatic(url) { return /^\/(clients|admin\/vendors)\/assets?\/|^\/clients\/assets\//.test(url.pathname) && /\.(css|js|png|jpe?g|webp|svg|woff2?)$/.test(url.pathname); }

self.addEventListener("fetch", (e) => {
    const req = e.request;
    if (req.method !== "GET") return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (isTripPage(url) && req.mode === "navigate") {
        e.respondWith(fetch(req).then((res) => {
            if (res.ok) { const copy = res.clone(); caches.open(PAGES).then((c) => c.put(url.pathname, copy)); }
            return res;
        }).catch(() => caches.open(PAGES).then((c) => c.match(url.pathname)).then((hit) => hit || offlinePage())));
        return;
    }
    if (isStatic(url)) {
        e.respondWith(caches.open(STATIC).then((c) => c.match(req).then((hit) => {
            const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
            return hit || net;
        })));
    }
});

function offlinePage() {
    const html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mất kết nối</title>'
        + '<body style="font-family:system-ui,sans-serif;padding:24px;color:#111827;background:#f4f7f7"><h1 style="font-size:1.2rem">Bạn đang ngoại tuyến</h1>'
        + '<p>Trang này chưa được mở lần nào khi có mạng nên chưa có bản lưu. Mở chuyến đi một lần khi có mạng, lần sau xem được cả khi mất sóng.</p></body>';
    return new Response(html, { headers: { "Content-Type": "text/html; charset=utf-8" } });
}

self.addEventListener("push", (e) => {
    e.waitUntil(fetch("/thong-bao/moi-nhat", { credentials: "include", headers: { Accept: "application/json" } })
        .then((r) => r.ok ? r.json() : null)
        .catch(() => null)
        .then((j) => {
            const ev = j && j.event;
            const title = ev ? ev.title : "Travela";
            const body = ev ? (ev.trip ? ev.trip + ": " : "") + (ev.text || "") : "Chuyến đi của bạn có thông báo mới.";
            return self.registration.showNotification(title, { body: body.slice(0, 180), icon: "/clients/assets/images/pwa/icon-192.png",
                badge: "/clients/assets/images/pwa/icon-192.png", tag: ev ? "trip-" + ev.tripId : "travela", data: { url: ev ? ev.url : "/len-ke-hoach" } });
        }));
});

self.addEventListener("notificationclick", (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || "/len-ke-hoach";
    e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((ws) => {
        for (const w of ws) { if (new URL(w.url).pathname === new URL(url, location.origin).pathname) { return w.focus(); } }
        return self.clients.openWindow(url);
    }));
});
