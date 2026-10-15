# Don dep file thua cho du an Travela.
# Chay tai thu muc goc project (noi co file artisan):
#   powershell -ExecutionPolicy Bypass -File .\cleanup.ps1 -DryRun     # xem truoc, KHONG xoa
#   powershell -ExecutionPolicy Bypass -File .\cleanup.ps1             # xoa nhom AN TOAN
#   ... -IncludeLegacyFonts   # xoa them font .eot/.ttf/.svg cu (chi trinh duyet rat cu moi can)
#   ... -IncludeSessions      # xoa session dang dang nhap (moi nguoi dung phai dang nhap lai)
# NEN commit git hoac copy du an ra noi khac truoc khi xoa that.
param([switch]$DryRun, [switch]$IncludeLegacyFonts, [switch]$IncludeSessions)

if (-not (Test-Path (Join-Path (Get-Location) 'artisan'))) {
    Write-Host "Khong thay file artisan - hay chay tai thu muc goc project." -ForegroundColor Red; exit 1
}
$here = $PSScriptRoot
$lists = @('cleanup-safe.txt')
if ($IncludeLegacyFonts) { $lists += 'cleanup-legacy-fonts.txt' }

$count = 0; $bytes = 0
foreach ($l in $lists) {
    $file = Join-Path $here $l
    if (-not (Test-Path $file)) { Write-Host "Thieu $l" -ForegroundColor Red; continue }
    foreach ($rel in (Get-Content $file | Where-Object { $_.Trim() -ne '' })) {
        $items = Get-Item -Path (Join-Path (Get-Location) ($rel.Trim() -replace '/', '\')) -Force -ErrorAction SilentlyContinue
        foreach ($it in $items) {
            if ($it.PSIsContainer) { continue }
            $count++; $bytes += $it.Length
            if ($DryRun) { Write-Host "[dry] $($it.FullName)" } else { Remove-Item -LiteralPath $it.FullName -Force }
        }
    }
}
if ($IncludeSessions) {
    foreach ($it in (Get-ChildItem 'storage\framework\sessions' -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne '.gitignore' })) {
        $count++; $bytes += $it.Length
        if ($DryRun) { Write-Host "[dry] $($it.FullName)" } else { Remove-Item -LiteralPath $it.FullName -Force }
    }
}
if (-not $DryRun) {
    # don thu muc rong
    foreach ($base in 'public\admin\vendors', 'public\admin\assets\js\ckeditor', 'public\clients\assets') {
        if (Test-Path $base) {
            Get-ChildItem $base -Directory -Recurse -Force | Sort-Object FullName -Descending |
                Where-Object { -not (Get-ChildItem $_.FullName -Force) } | Remove-Item -Force
        }
    }
    php artisan view:clear | Out-Null
}
$mb = [math]::Round($bytes / 1MB, 1)
if ($DryRun) { Write-Host "Se xoa $count file, giai phong khoang $mb MB." -ForegroundColor Yellow }
else { Write-Host "Da xoa $count file, giai phong khoang $mb MB." -ForegroundColor Green }
