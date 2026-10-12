# Xoa cac anh khong con duoc dung trong public/clients/assets/images
# Cach dung: mo PowerShell tai thu muc goc project (noi co file artisan) roi chay:
#   powershell -ExecutionPolicy Bypass -File .\delete-unused-images.ps1
# Chay thu khong xoa:  ... -File .\delete-unused-images.ps1 -DryRun
param([switch]$DryRun)
$root = Join-Path (Get-Location) 'public\clients\assets\images'
if (-not (Test-Path $root)) { Write-Host "Khong thay $root - hay chay tai thu muc goc project." -ForegroundColor Red; exit 1 }
$list = Get-Content (Join-Path $PSScriptRoot 'delete-unused-images.txt') | Where-Object { $_.Trim() -ne '' }
$n = 0
foreach ($rel in $list) {
    $p = Join-Path $root ($rel.Trim() -replace '/', '\')
    if (Test-Path $p) {
        if ($DryRun) { Write-Host "[dry] $rel" } else { Remove-Item $p -Force; $n++ }
    }
}
if (-not $DryRun) {
    # don thu muc rong (khong dong vao user-profile / transfer-proofs)
    Get-ChildItem $root -Directory -Recurse | Sort-Object FullName -Descending |
        Where-Object { $_.Name -notin 'user-profile','transfer-proofs' -and -not (Get-ChildItem $_.FullName -Force) } |
        Remove-Item -Force
    Write-Host "Da xoa $n file." -ForegroundColor Green
}
