# Gộp giao diện + dọn CSS/JS rác — đợt 2, 16/11/2026. Chạy ở THƯ MỤC GỐC dự án, SAU KHI đã chép đè gói travela-gop-giao-dien-16-11-2026.zip:
#   powershell -ExecutionPolicy Bypass -File tools\don-rac.ps1 -XemTruoc     (chỉ liệt kê, không xóa)
#   powershell -ExecutionPolicy Bypass -File tools\don-rac.ps1               (xóa thật)
# Xóa: các tệp CSS theme đã gộp vào explore / theme-pages / theme-tv / shell / blue-lagoon (nội dung nằm nguyên trong tệp mới,
# đúng thứ tự cũ), jQuery UI CSS, slick, magnific-popup (không trang nào dùng), sea-theme.js + client-ui-polish.js (đã gộp vào
# page-motion.js) và các bản gộp cũ trong public/clients/assets/build.
param([switch]$XemTruoc)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path 'artisan')) { Write-Host 'Hãy chạy ở thư mục gốc dự án (nơi có tệp artisan).' -ForegroundColor Red; exit 1 }
if (-not (Test-Path 'public\clients\assets\css\theme-tv.css')) { Write-Host 'Chưa chép đè gói tệp mới (thiếu theme-tv.css). Giải nén gói vào thư mục gốc trước rồi chạy lại.' -ForegroundColor Red; exit 1 }

$ds = @'
public/clients/assets/build/site-a.069e253175.css
public/clients/assets/build/site-a.709bced7d3.js
public/clients/assets/build/site-b.f42ceb21d6.css
public/clients/assets/build/site-c.f9c0f8cd55.css
public/clients/assets/build/site-d.db1940bfef.css
public/clients/assets/css/blue-lagoon-layout-fix.css
public/clients/assets/css/blue-lagoon-tour-explore.css
public/clients/assets/css/client-ui-polish.css
public/clients/assets/css/explore-c.css
public/clients/assets/css/explore-kit.css
public/clients/assets/css/gx-discover.css
public/clients/assets/css/journal.css
public/clients/assets/css/jquery-ui.min.css
public/clients/assets/css/magnific-popup.min.css
public/clients/assets/css/mobile-nav.css
public/clients/assets/css/sea-theme.css
public/clients/assets/css/site-ux.css
public/clients/assets/css/slick.min.css
public/clients/assets/css/travel-pages.css
public/clients/assets/css/tv-decor-fix.css
public/clients/assets/css/tv-final.css
public/clients/assets/css/tv-pages-10.css
public/clients/assets/css/tv-pages.css
public/clients/assets/css/tv-tours-10.css
public/clients/assets/css/tv-watercolor-refine.css
public/clients/assets/css/tv-watercolor.css
public/clients/assets/css/tv3.css
public/clients/assets/css/ux-pass.css
public/clients/assets/css/ux-watercolor.css
public/clients/assets/js/client-ui-polish.js
public/clients/assets/js/jquery.magnific-popup.min.js
public/clients/assets/js/sea-theme.js
public/clients/assets/js/slick.min.js
'@ -split "`r?`n" | Where-Object { $_.Trim() -ne '' }

$xoa = 0; $byte = 0
foreach ($p in $ds) {
    $full = Join-Path (Get-Location) ($p -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full)) { continue }
    $size = (Get-ChildItem -LiteralPath $full -Recurse -File -Force -ErrorAction SilentlyContinue | Measure-Object Length -Sum).Sum
    if ($null -eq $size) { $size = (Get-Item -LiteralPath $full -Force).Length }
    $byte += $size; $xoa++
    if ($XemTruoc) { Write-Host "  sẽ xóa  $p" } else { Remove-Item -LiteralPath $full -Recurse -Force }
}
if (-not $XemTruoc) {
    Remove-Item 'storage\framework\views\*.php' -Force -ErrorAction SilentlyContinue
    php artisan optimize:clear | Out-Null
}
$kb = [math]::Round($byte / 1KB)
if ($XemTruoc) { Write-Host "$xoa mục, khoảng $kb KB sẽ được xóa. Chạy lại không có -XemTruoc để xóa." -ForegroundColor Yellow }
else {
    Write-Host "Đã xóa $xoa mục, khoảng $kb KB." -ForegroundColor Green
    Write-Host 'Tiếp theo: php artisan test, rồi so ảnh: npx playwright test -c playwright.visual.config.js' -ForegroundColor Green
}
