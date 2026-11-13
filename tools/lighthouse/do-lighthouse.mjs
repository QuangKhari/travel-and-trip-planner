// PF-10 / PF-12: đo Lighthouse các trang chính và in bảng TRƯỚC / SAU (Markdown) để dán vào báo cáo.
//
// Cài một lần (trong thư mục dự án):   npm i -D lighthouse chrome-launcher
// Chạy (máy đang chạy php artisan serve):
//   node tools/lighthouse/do-lighthouse.mjs --sau=http://127.0.0.1:8000 [--truoc=http://127.0.0.1:8001] [--lan=3]
//        [--cookie="laravel_session=..."]  (trang cần đăng nhập: mở DevTools → Application → Cookies, chép laravel_session)
//        [--trang=/,/tours,/tour-detail/5,/booking/5,/len-ke-hoach/2] [--desktop]
// "--truoc": bản code TRƯỚC khi tối ưu chạy ở cổng khác (git worktree của commit cũ + php artisan serve --port=8001).
// Kết quả: tools/lighthouse/ket-qua-<ngày giờ>.json + bảng Markdown in ra màn hình. Mỗi trang chạy --lan lần, lấy TRUNG VỊ.
import fs from 'node:fs';
import path from 'node:path';
import lighthouse from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';

const arg = (k, d) => {
  const a = process.argv.find((x) => x.startsWith(`--${k}=`));
  return a ? a.slice(k.length + 3) : (process.argv.includes(`--${k}`) ? true : d);
};
const pages = String(arg('trang', '/,/tours,/tour-detail/5,/booking/5,/len-ke-hoach/2')).split(',');
const runs = Math.max(1, Number(arg('lan', 3)));
const cookie = arg('cookie', '');
const targets = [['Trước', arg('truoc', '')], ['Sau', arg('sau', 'http://127.0.0.1:8000')]].filter(([, u]) => u);
const median = (xs) => { const s = [...xs].sort((a, b) => a - b); return s.length ? s[Math.floor((s.length - 1) / 2)] : null; };

const chrome = await chromeLauncher.launch({ chromeFlags: ['--headless=new', '--no-sandbox'] });
const out = {};
try {
  for (const [label, base] of targets) {
    out[label] = {};
    for (const p of pages) {
      const rs = [];
      for (let i = 0; i < runs; i++) {
        const r = await lighthouse(base.replace(/\/$/, '') + p, {
          port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: ['performance', 'accessibility', 'best-practices'],
          extraHeaders: cookie ? { Cookie: cookie } : undefined, formFactor: arg('desktop') ? 'desktop' : 'mobile',
          screenEmulation: arg('desktop') ? { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false } : undefined,
        });
        const a = r.lhr.audits;
        rs.push({ perf: r.lhr.categories.performance.score * 100, a11y: r.lhr.categories.accessibility.score * 100, bp: r.lhr.categories['best-practices'].score * 100,
          fcp: a['first-contentful-paint'].numericValue, lcp: a['largest-contentful-paint'].numericValue, tbt: a['total-blocking-time'].numericValue,
          cls: a['cumulative-layout-shift'].numericValue, kb: a['total-byte-weight'].numericValue / 1024, reqs: (a['network-requests'].details?.items || []).length });
      }
      const m = {};
      for (const k of Object.keys(rs[0])) m[k] = median(rs.map((x) => x[k]));
      out[label][p] = m;
      process.stderr.write(`${label} ${p}: perf ${Math.round(m.perf)} LCP ${Math.round(m.lcp)}ms\n`);
    }
  }
} finally {
  await chrome.kill();
}
const stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 16);
const file = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(\w:)/, '$1')), `ket-qua-${stamp}.json`);
fs.writeFileSync(file, JSON.stringify(out, null, 2));

const f = (v, d = 0) => (v == null ? '–' : Number(v).toFixed(d));
const rows = ['| Trang | Bản | Điểm hiệu năng | FCP (ms) | LCP (ms) | TBT (ms) | CLS | Dung lượng (KB) | Số request | Truy cập | Thực hành tốt |', '|---|---|---|---|---|---|---|---|---|---|---|'];
for (const p of pages) {
  for (const [label] of targets) {
    const m = out[label][p];
    rows.push(`| ${p} | ${label} | ${f(m.perf)} | ${f(m.fcp)} | ${f(m.lcp)} | ${f(m.tbt)} | ${f(m.cls, 3)} | ${f(m.kb)} | ${f(m.reqs)} | ${f(m.a11y)} | ${f(m.bp)} |`);
  }
}
console.log(rows.join('\n'));
console.log(`\nĐã lưu: ${file} (trung vị của ${runs} lần chạy, ${arg('desktop') ? 'desktop' : 'mobile, Lighthouse giả lập mạng 4G chậm'})`);
