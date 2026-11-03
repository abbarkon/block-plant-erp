$ErrorActionPreference = 'SilentlyContinue'
$targets = @()

$desktop = [Environment]::GetFolderPath('Desktop')
$programs = [Environment]::GetFolderPath('Programs')

foreach ($dir in @($desktop, $programs)) {
    if (-not $dir) { continue }
    $targets += (Join-Path $dir 'Block Plant ERP.lnk')
    $targets += (Join-Path $dir 'Block Plant ERP.url')
}
$menuFolder = Join-Path $programs 'Block Plant ERP'
$targets += (Join-Path $menuFolder 'Block Plant ERP.lnk')
$targets += (Join-Path $menuFolder 'Block Plant ERP.url')

$removed = 0
foreach ($path in $targets) {
    if ($path -and (Test-Path $path)) {
        Remove-Item $path -Force -ErrorAction SilentlyContinue
        if (-not (Test-Path $path)) {
            Write-Host "   removed  $path" -ForegroundColor Gray
            $removed++
        }
    }
}
if (Test-Path $menuFolder) { Remove-Item $menuFolder -Recurse -Force -ErrorAction SilentlyContinue }

Write-Host ''
if ($removed -gt 0) {
    Write-Host " Removed $removed shortcut(s)." -ForegroundColor Green
} else {
    Write-Host ' No Block Plant ERP shortcut was found on this PC.' -ForegroundColor Yellow
}
Write-Host ' The application itself stays on the server; nothing else was changed.' -ForegroundColor Gray
Write-Host ''
exit 0
