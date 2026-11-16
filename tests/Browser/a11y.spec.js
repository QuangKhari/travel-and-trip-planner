// Đợt E (E5): kiểm tra WCAG 2.1 AA tự động bằng axe-core trên các trang chính.
// Chỉ chặn lỗi mức "serious" / "critical"; lỗi nhẹ in ra để sửa dần. Kiểm tự động chỉ bắt được khoảng một phần ba lỗi,
// phần còn lại (đọc bằng trình đọc màn hình, thứ tự tab) vẫn phải thử tay — xem docs/kiem-tra-tiep-can.md.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { offlineThirdParty, loginClient } from './helpers.js';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function audit(page, name) {
    const r = await new AxeBuilder({ page }).withTags(TAGS)
        // bản đồ Leaflet và khung nhúng của bên thứ ba không thuộc mã của Travela
        .exclude('.leaflet-container').exclude('iframe')
        .analyze();
    const bad = r.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    for (const v of r.violations) {
        console.log(`[${name}] ${v.impact} ${v.id} (${v.nodes.length}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    }
    expect(bad.map((v) => `${v.id}: ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`), name).toEqual([]);
}

test.describe('Tiếp cận (WCAG 2.1 AA, axe-core)', () => {
    test('trang công khai', async ({ page }) => {
        await offlineThirdParty(page);
        for (const [name, url] of [['trang chủ', '/'], ['danh sách tour', '/tours'], ['đăng nhập', '/login']]) {
            await page.goto(url);
            await page.waitForLoadState('networkidle');
            await audit(page, name);
        }
    });

    test('lập lịch trình', async ({ page }) => {
        await offlineThirdParty(page);
        await loginClient(page);
        await page.goto('/len-ke-hoach');
        await page.waitForLoadState('networkidle');
        await audit(page, 'chuyến đi của tôi');
        const href = await page.evaluate(() => {
            const a = [...document.querySelectorAll('a[href]')].find((x) => /\/len-ke-hoach\/\d+$/.test(x.getAttribute('href')));
            return a ? a.getAttribute('href') : null;
        });
        if (href) {
            await page.goto(href);
            await page.waitForLoadState('networkidle');
            await audit(page, 'trang chuyến đi');
        }
    });

    // Chuyến có đủ dữ liệu (đóng cửa, trùng giờ, chuyến nhóm, chuyến đang đi) từ `php artisan demo:reset`; bỏ qua nếu máy chưa có tài khoản demo.
    test('chuyến mẫu: chế độ chi tiết, theo giờ và ngăn La bàn', async ({ page }) => {
        test.setTimeout(120_000);
        await offlineThirdParty(page);
        await page.goto('/login');
        await page.fill('#lgUser', 'demo_khach');
        await page.fill('#lgPass', 'Demo@2026');
        await Promise.all([page.waitForNavigation().catch(() => {}), page.click('#lgSubmit')]);
        test.skip(/\/login/.test(page.url()), 'chưa chạy demo:reset');
        await page.goto('/len-ke-hoach');
        const hrefs = await page.evaluate(() => [...new Set([...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href'))
            .filter((h) => /\/len-ke-hoach\/\d+$/.test(h)))]);
        for (const h of hrefs.slice(0, 3)) {
            await page.goto(h);
            await page.waitForLoadState('networkidle');
            await audit(page, h);
            const hours = page.locator('[data-view="hours"]').first();
            if (await hours.isVisible()) {
                await hours.click();
                await audit(page, h + ' (theo giờ)');
                await page.locator('[data-view="full"]').first().click().catch(() => {});
            }
            const open = page.locator('#aiCoOpen:visible, #aiCoOpen2:visible').first();
            if (await open.count()) {
                await open.click();
                await page.waitForTimeout(800);
                await audit(page, h + ' (La bàn)');
            }
        }
    });
});
