// So sánh giao diện TRƯỚC / SAU khi sửa CSS/JS (chụp toàn trang, so từng điểm ảnh).
//   1) Trước khi áp dụng thay đổi:  npx playwright test -c playwright.visual.config.js --update-snapshots   (lưu ảnh gốc)
//   2) Áp dụng thay đổi, rồi:       npx playwright test -c playwright.visual.config.js                      (so với ảnh gốc)
//   3) Xem kết quả:                 npx playwright show-report playwright-visual-report
// Cần máy chủ đang chạy (php artisan serve) với CÙNG một cơ sở dữ liệu cho cả hai lần chụp.
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Visual',
    timeout: 90_000,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-visual-report' }]],
    outputDir: 'test-results-visual',
    snapshotPathTemplate: '{testDir}/__anh-goc__/{projectName}/{arg}{ext}',
    expect: {
        toHaveScreenshot: { maxDiffPixelRatio: 0.002, animations: 'disabled', caret: 'hide', scale: 'css' },
    },
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
        locale: 'vi-VN',
        timezoneId: 'Asia/Ho_Chi_Minh',
        reducedMotion: 'reduce',
        launchOptions: process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {},
    },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1366, height: 900 } } },
        { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
    ],
});
