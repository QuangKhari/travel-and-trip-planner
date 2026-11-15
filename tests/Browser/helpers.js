import { expect } from '@playwright/test';
import crypto from 'node:crypto';

/** Ghi lại lỗi JavaScript trên trang; cuối bài kiểm tra phải rỗng. */
export function watchErrors(page) {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    return errors;
}

/** Bỏ qua tài nguyên ngoài (CDN, bản đồ, phông) để bài kiểm tra không phụ thuộc mạng. */
export async function offlineThirdParty(page) {
    await page.route(/^https?:\/\/(?!127\.0\.0\.1|localhost)/, (r) => r.abort());
}

export async function loginClient(page, user = 'nguyenvana', pass = '12345678') {
    await page.goto('/login');
    await page.fill('#lgUser', user);
    await page.fill('#lgPass', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#lgSubmit')]);
    await expect(page).not.toHaveURL(/\/login$/);
}

export async function loginAdmin(page, user = 'e2e_admin', pass = 'matkhau123') {
    await page.goto('/admin/login');
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await page.click('#lg-submit');
    await page.waitForLoadState('domcontentloaded');
}

/** Mã TOTP 6 số (RFC 6238) từ khóa base32, như ứng dụng xác thực trên điện thoại. */
export function totp(secret, offset = 0) {
    const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of secret.replace(/\s/g, '').toUpperCase()) bits += A.indexOf(ch).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const step = Math.floor(Date.now() / 30000) + offset;
    const msg = Buffer.alloc(8);
    msg.writeUInt32BE(Math.floor(step / 2 ** 32), 0);
    msg.writeUInt32BE(step >>> 0, 4);
    const h = crypto.createHmac('sha1', key).update(msg).digest();
    const o = h[19] & 15;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0');
}
