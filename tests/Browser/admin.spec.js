import { test, expect } from '@playwright/test';
import { watchErrors, offlineThirdParty, loginAdmin, totp } from './helpers.js';

test.describe('Quản trị', () => {
    test('đăng nhập, mở bảng điều khiển và các trang chính', async ({ page }) => {
        const errors = watchErrors(page);
        await offlineThirdParty(page);
        await loginAdmin(page);
        await expect(page).toHaveURL(/\/admin\/dashboard/);
        for (const url of ['/admin/booking', '/admin/tour-quotes', '/admin/admin']) {
            const r = await page.goto(url);
            expect(r.status(), url).toBe(200);
        }
        expect(errors).toEqual([]);
    });

    test('bật đăng nhập hai lớp, lần sau phải nhập mã, rồi tắt', async ({ page, context }) => {
        await offlineThirdParty(page);
        await loginAdmin(page);
        await page.goto('/admin/admin#bao-mat');
        const secret = (await page.locator('#bao-mat code').textContent()).replace(/\s/g, '');
        await page.fill('#bao-mat input[name=code]', totp(secret, -1));
        await page.click('#bao-mat button[type=submit]');
        await expect(page.locator('#tfaCodes')).toBeVisible();   // mã dự phòng hiện một lần

        await context.clearCookies();
        await loginAdmin(page);
        await expect(page).toHaveURL(/\/admin\/login\/xac-minh/);
        await page.fill('#code', '000000');
        await page.click('#lg-submit');
        await expect(page).toHaveURL(/\/admin\/login\/xac-minh/);
        await page.fill('#code', totp(secret));
        await page.click('#lg-submit');
        await expect(page).toHaveURL(/\/admin\/dashboard/);

        // dọn: tắt lại để các lần chạy sau đăng nhập bình thường (mã kế tiếp, vì mã vừa dùng không nhận lại)
        await page.goto('/admin/admin#bao-mat');
        await page.waitForTimeout(31000 - (Date.now() % 30000));
        await page.fill('#tfaCodeOff', totp(secret));
        await page.click('#tfaCodeOff ~ button');
        await expect(page.locator('#bao-mat')).toContainText('Chưa bật');
    });
});
