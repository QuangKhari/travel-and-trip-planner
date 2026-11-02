// J1 — đo tải Travela bằng k6 (§13.3, N-03, N-03a).
//
//   k6 run -e BASE=http://localhost:8080 tools/k6/travela.js                    # 50 người dùng ảo, 2 phút
//   k6 run -e BASE=... -e VUS=50 -e DURATION=60s -e PHASE=cold tools/k6/travela.js
//   k6 run ... --summary-export tools/k6/results/warm.json
//
// Máy chủ cần: LOADTEST_NO_THROTTLE=true (một máy đo = một IP, nếu không các giới hạn N lượt/phút/IP trả 429)
//              QUERY_COUNT_HEADER=true (đếm số truy vấn SQL mỗi request qua header X-Db-Queries). Cả hai bị bỏ qua ở production.
// Cold / warm: PHASE=cold chạy ngay sau `php artisan cache:clear` và khởi động lại php-fpm (OPcache rỗng);
//              PHASE=warm chạy lần hai ngay sau đó.
//
// Mục tiêu (thresholds): p95 < 300 ms toàn bộ ở 50 VU (N-03); TTFB p95 < 200 ms khi cache nóng (N-03a); lỗi < 1 %.

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://localhost:8080').replace(/\/$/, '');
const VUS = parseInt(__ENV.VUS || '50', 10);
const DURATION = __ENV.DURATION || '2m';
const PHASE = __ENV.PHASE || 'warm';
const USER_PASS = __ENV.USER_PASS || 'User@1234';
const USERS = (__ENV.USERS || 'user1,user2,user3,user4,user5,user6,user7,user8').split(',');

const queries = new Trend('db_queries', false);
const pages = {
    tours: new Trend('page_tours', true),
    tour: new Trend('page_tour_detail', true),
    seats: new Trend('api_seats', true),
    quote: new Trend('api_quote', true),
    community: new Trend('page_community', true),
    trip: new Trend('page_trip_public', true),
    planner: new Trend('page_planner_list', true),
};
const errors = new Counter('app_errors');

export const options = {
    scenarios: {
        browse: { executor: 'constant-vus', vus: Math.max(1, Math.round(VUS * 0.7)), duration: DURATION, exec: 'browse' },
        booking: { executor: 'constant-vus', vus: Math.max(1, Math.round(VUS * 0.2)), duration: DURATION, exec: 'booking' },
        planner: { executor: 'constant-vus', vus: Math.max(1, VUS - Math.round(VUS * 0.7) - Math.round(VUS * 0.2)), duration: DURATION, exec: 'planner' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<300'],
        ...(PHASE === 'warm' ? { http_req_waiting: ['p(95)<200'] } : {}),
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    tags: { phase: PHASE },
};

// Dữ liệu dùng chung: id tour và id chuyến công khai, lấy một lần trước khi đo
export function setup() {
    const ids = (path, re) => {
        const r = http.get(BASE + path);
        const out = new Set();
        let m;
        while ((m = re.exec(r.body || '')) !== null) out.add(parseInt(m[1], 10));
        return [...out];
    };
    const tours = ids('/tours', /tour-detail\/(\d+)/g);
    const trips = ids('/cong-dong', /cong-dong\/(\d+)/g);
    if (!tours.length) throw new Error('Không tìm thấy tour nào ở ' + BASE + '/tours');
    return { tours, trips };
}

const pick = (a) => a[Math.floor(Math.random() * a.length)];

function track(res, trend, name) {
    trend.add(res.timings.duration);
    const q = parseInt(res.headers['X-Db-Queries'] || '', 10);
    if (!isNaN(q)) queries.add(q, { page: name });
    const ok = check(res, { [name + ' 2xx']: (r) => r.status >= 200 && r.status < 300 });
    if (!ok) errors.add(1, { page: name, status: String(res.status) });
    return res;
}

export function browse(data) {
    group('danh sách tour', () => track(http.get(BASE + '/tours', { tags: { name: 'tours' } }), pages.tours, 'tours'));
    sleep(Math.random() * 2 + 1);
    const id = pick(data.tours);
    group('chi tiết tour', () => {
        track(http.get(`${BASE}/tour-detail/${id}`, { tags: { name: 'tour-detail' } }), pages.tour, 'tour-detail');
        track(http.get(`${BASE}/tour/${id}/cho-trong`, { tags: { name: 'seats' } }), pages.seats, 'seats');
    });
    sleep(Math.random() * 2 + 1);
    if (data.trips.length) {
        group('cộng đồng', () => {
            track(http.get(BASE + '/cong-dong', { tags: { name: 'community' } }), pages.community, 'community');
            track(http.get(`${BASE}/cong-dong/${pick(data.trips)}`, { tags: { name: 'community-trip' } }), pages.trip, 'community-trip');
        });
        sleep(Math.random() * 2 + 1);
    }
}

// Bước đặt tour: xem tour, chọn ngày, báo giá (API A4). Không tạo đơn thật để không làm cạn chỗ trong lúc đo.
export function booking(data) {
    const id = pick(data.tours);
    const seats = track(http.get(`${BASE}/tour/${id}/cho-trong`, { tags: { name: 'seats' } }), pages.seats, 'seats');
    let dep = null;
    try { dep = (seats.json('departures') || []).find((d) => d.bookable); } catch (e) { /* bỏ qua */ }
    sleep(1);
    for (const adults of [1, 2, 3]) {
        const q = `${BASE}/dat-tour/bao-gia?tourId=${id}&adults=${adults}&children=${adults - 1}` + (dep ? `&availabilityId=${dep.id}` : '');
        track(http.get(q, { tags: { name: 'quote' } }), pages.quote, 'quote');
        sleep(0.5);
    }
    sleep(Math.random() * 2 + 1);
}

// Người dùng đã đăng nhập mở danh sách chuyến đi của mình (Trip Planner)
export function planner() {
    const jar = http.cookieJar();
    if (!jar.cookiesForURL(BASE)['travela-session'] || __ITER === 0) {
        const page = http.get(BASE + '/login', { tags: { name: 'login-page' } });
        const token = (/name="_token"\s+value="([^"]+)"/.exec(page.body || '') || /name="csrf-token"\s+content="([^"]+)"/.exec(page.body || '') || [])[1];
        const user = USERS[(__VU - 1) % USERS.length];
        const r = http.post(BASE + '/login', { _token: token, username: user, password: USER_PASS },
            { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, tags: { name: 'login' } });
        check(r, { 'đăng nhập được': (x) => x.status === 200 && /"success":true/.test(x.body || '') });
    }
    track(http.get(BASE + '/len-ke-hoach', { tags: { name: 'planner' } }), pages.planner, 'planner');
    sleep(Math.random() * 3 + 2);
}

export function handleSummary(data) {
    const m = data.metrics;
    const row = (k, label) => m[k] ? `| ${label} | ${m[k].values['p(95)'].toFixed(0)} | ${m[k].values.med.toFixed(0)} | ${m[k].values.max.toFixed(0)} |` : null;
    const lines = [
        `# Kết quả k6 (${PHASE}, ${VUS} VU, ${DURATION})`, '',
        '| Trang / API | p95 (ms) | trung vị (ms) | max (ms) |', '|---|---:|---:|---:|',
        row('page_tours', 'Danh sách tour'), row('page_tour_detail', 'Chi tiết tour'), row('api_seats', 'API số chỗ'),
        row('api_quote', 'API báo giá'), row('page_community', 'Cộng đồng'), row('page_trip_public', 'Chuyến đi công khai'),
        row('page_planner_list', 'Chuyến đi của tôi'), row('http_req_duration', '**Tất cả**'), row('http_req_waiting', 'TTFB'), '',
        `Số request: ${m.http_reqs.values.count} (${m.http_reqs.values.rate.toFixed(1)}/giây) · lỗi: ${(m.http_req_failed.values.rate * 100).toFixed(2)} %`,
        m.db_queries ? `Truy vấn SQL / request: trung bình ${m.db_queries.values.avg.toFixed(1)}, p95 ${m.db_queries.values['p(95)'].toFixed(0)}, max ${m.db_queries.values.max}` : '',
    ].filter((x) => x !== null);
    const out = { stdout: lines.join('\n') + '\n' };
    if (__ENV.OUT) out[__ENV.OUT] = JSON.stringify(data, null, 2);
    if (__ENV.OUT_MD) out[__ENV.OUT_MD] = lines.join('\n') + '\n';
    return out;
}
