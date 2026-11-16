// Kiểm thử trình duyệt các luồng chính (Đợt C mục 18). Chạy: npx playwright test
// Cần máy chủ đang chạy với dữ liệu BrowserTestSeeder: php artisan migrate:fresh --seed --seeder=BrowserTestSeeder && php artisan serve
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 60_000,
    retries: process.env.CI ? 1 : 0,
    workers: 1,   // các bài dùng chung một cơ sở dữ liệu
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
        locale: 'vi-VN',
        timezoneId: 'Asia/Ho_Chi_Minh',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {},
    },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1366, height: 900 } } },
        { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }, testMatch: /public|planner|a11y/ },
    ],
});
