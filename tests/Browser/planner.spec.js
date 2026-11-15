import { test, expect } from '@playwright/test';
import { watchErrors, offlineThirdParty, loginClient } from './helpers.js';

test.describe('Lập lịch trình', () => {
    test('tạo chuyến, đổi chế độ xem, báo mất mạng rồi hết báo khi có mạng lại', async ({ page, context }) => {
        const errors = watchErrors(page);
        await offlineThirdParty(page);
        await loginClient(page);

        // Tạo chuyến (gửi thẳng biểu mẫu, không đi qua từng bước của trình hướng dẫn)
        await page.goto('/len-ke-hoach');
        const title = 'E2E ' + Date.now();
        await page.evaluate((t) => {
            const f = document.getElementById('tplForm');
            f.querySelector('[name=title]').value = t;
            f.querySelector('[name=days]').value = '2';
            f.querySelector('[name=groupSize]').value = '2';
            HTMLFormElement.prototype.submit.call(f);
        }, title);
        await page.waitForURL(/\/len-ke-hoach\/\d+$/);
        await expect(page.locator('#tpData')).toHaveCount(1);
        await expect(page.getByText(title).first()).toBeVisible();

        // Các chế độ xem lịch (desktop: thanh công cụ; điện thoại có thể ẩn bớt)
        const hours = page.locator('[data-view="hours"]').first();
        if (await hours.isVisible()) {
            await hours.click();
            await expect(hours).toHaveAttribute('aria-pressed', 'true');
            await page.reload();   // chế độ xem được nhớ trên máy
            await expect(page.locator('[data-view="hours"]').first()).toHaveAttribute('aria-pressed', 'true');
        }

        // Mất mạng: vẫn sửa được, hiện thanh báo; có mạng lại thì tự lưu
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await expect(page.locator('#tpOffline')).toBeVisible();
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.locator('#tpOffline')).toBeHidden();

        expect(errors).toEqual([]);
    });
});
