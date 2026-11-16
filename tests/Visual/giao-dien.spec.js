// Chụp toàn trang các màn hình chính để so sánh trước / sau khi gộp CSS-JS. Xem playwright.visual.config.js.
// Tài khoản khách mặc định giống tests/Browser (BrowserTestSeeder); đổi bằng biến môi trường VISUAL_USER / VISUAL_PASS.
import { test, expect } from '@playwright/test';

const USER = process.env.VISUAL_USER || 'nguyenvana';
const PASS = process.env.VISUAL_PASS || '12345678';

/** Chặn tài nguyên ngoài (CDN, bản đồ, phông Google) để hai lần chụp giống hệt nhau về điều kiện mạng. */
async function offline(page) {
    await page.route(/^https?:\/\/(?!127\.0\.0\.1|localhost)/, (r) => r.abort());
}

/** Mở trang, chờ ổn định, chụp toàn trang. Che các vùng luôn thay đổi (bản đồ, video, canvas, giờ hiện tại). */
async function shot(page, url, name) {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const res = await page.goto(url, { waitUntil: 'load' });
    expect(res && res.status(), `${url} trả về lỗi`).toBeLessThan(400);
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1200); // preloader mờ dần, ảnh lazy
    // cuộn hết trang một lượt để ảnh lazy / hiệu ứng hiện dần chạy giống nhau ở cả hai lần
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 600) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 60));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(800);
    await expect(page).toHaveScreenshot(`${name}.png`, {
        fullPage: true,
        mask: [page.locator('.leaflet-container, .maplibregl-map, canvas, iframe, video, [data-visual-mask]')],
    });
    expect(errors, `Lỗi JavaScript trên ${url}`).toEqual([]);
}

/** Đường dẫn đầu tiên trên trang danh sách khớp biểu thức (chi tiết tour, điểm đến, chuyến đi...). */
async function firstLink(page, listUrl, re) {
    await page.goto(listUrl);
    const hrefs = await page.locator('a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    return hrefs.find((h) => re.test(h)) || null;
}

test.beforeEach(async ({ page }) => {
    await offline(page);
});

const PUBLIC = [
    ['/', 'trang-chu'],
    ['/tours', 'danh-sach-tour'],
    ['/diem-den', 'diem-den'],
    ['/explore', 'kham-pha'],
    ['/about', 'gioi-thieu'],
    ['/contact', 'lien-he'],
    ['/blogs', 'blog'],
    ['/travel-guides', 'huong-dan-vien'],
    ['/am-thuc', 'am-thuc'],
    ['/cam-nang', 'cam-nang'],
    ['/cong-dong', 'cong-dong'],
    ['/login', 'dang-nhap'],
    ['/register', 'dang-ky'],
];

for (const [url, name] of PUBLIC) {
    test(`trang công khai: ${name}`, async ({ page }) => {
        await shot(page, url, name);
    });
}

test('chi tiết tour đầu tiên', async ({ page }) => {
    const href = await firstLink(page, '/tours', /\/tour-detail\/\d+$/);
    test.skip(!href, 'Chưa có tour nào trong dữ liệu');
    await shot(page, href, 'chi-tiet-tour');
});

test('chi tiết điểm đến đầu tiên', async ({ page }) => {
    const href = await firstLink(page, '/diem-den', /\/diem-den\/(?!tat-ca)[a-z0-9-]+$/);
    test.skip(!href, 'Chưa có điểm đến nào');
    await shot(page, href, 'chi-tiet-diem-den');
});

test.describe('đã đăng nhập', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.fill('#lgUser', USER);
        await page.fill('#lgPass', PASS);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('#lgSubmit')]);
    });
    for (const [url, name] of [
        ['/my-tours', 'tour-cua-toi'],
        ['/user-profile', 'ho-so'],
        ['/so-tay-du-hanh', 'so-tay'],
        ['/len-ke-hoach', 'chuyen-cua-toi'],
        ['/len-ke-hoach/de-xuat', 'goi-y-lich-trinh'],
    ]) {
        test(`trang người dùng: ${name}`, async ({ page }) => {
            await shot(page, url, name);
        });
    }
    test('trình soạn chuyến đầu tiên', async ({ page }) => {
        const href = await firstLink(page, '/len-ke-hoach', /\/len-ke-hoach\/\d+$/);
        test.skip(!href, 'Tài khoản chưa có chuyến nào');
        await shot(page, href, 'soan-chuyen');
    });
});
