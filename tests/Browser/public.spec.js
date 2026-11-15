import { test, expect } from '@playwright/test';
import { watchErrors, offlineThirdParty } from './helpers.js';

test.describe('Trang công khai', () => {
    test('trang chủ, danh sách tour và chi tiết tour mở được, không lỗi JavaScript', async ({ page }) => {
        const errors = watchErrors(page);
        await offlineThirdParty(page);

        const home = await page.goto('/');
        expect(home.status()).toBe(200);

        const list = await page.goto('/tours');
        expect(list.status()).toBe(200);
        const link = page.locator('a[href*="/tour-detail/"]').first();
        await expect(link).toBeVisible();
        const href = await link.getAttribute('href');

        const detail = await page.goto(href);
        expect(detail.status()).toBe(200);
        await expect(page.locator('h1, h2').first()).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('trang lập lịch trình yêu cầu đăng nhập', async ({ page }) => {
        await offlineThirdParty(page);
        await page.goto('/len-ke-hoach');
        await expect(page).toHaveURL(/login/);
    });
});
