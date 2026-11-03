$ErrorActionPreference = 'SilentlyContinue'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$urlFile = Join-Path $here 'server-url.txt'

if (-not (Test-Path $urlFile)) {
    Write-Host ' server-url.txt is missing from this folder.' -ForegroundColor Red
    exit 1
}
$url = (Get-Content $urlFile -Raw).Trim()
if (-not $url) {
    Write-Host ' server-url.txt is empty.' -ForegroundColor Red
    exit 1
}
if ($url -notmatch '^https?://') { $url = "http://$url" }
if (-not $url.EndsWith('/'))    { $url = "$url/" }

Write-Host ''
Write-Host " Testing $url" -ForegroundColor White
Write-Host ''

$uri  = [Uri]$url
$port = if ($uri.Port -gt 0) { $uri.Port } else { 80 }
$reachable = $false
try {
    $client = New-Object System.Net.Sockets.TcpClient
    $async  = $client.BeginConnect($uri.Host, $port, $null, $null)
    if ($async.AsyncWaitHandle.WaitOne(5000, $false) -and $client.Connected) { $reachable = $true }
    $client.Close()
} catch { $reachable = $false }

if (-not $reachable) {
    Write-Host " FAIL  The server PC ($($uri.Host)) did not answer on port $port." -ForegroundColor Red
    Write-Host '       Check that the server PC is switched on and on the office network,' -ForegroundColor Gray
    Write-Host '       and that its firewall allows the connection.' -ForegroundColor Gray
    exit 1
}

$status = $null
try {
    $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
    $status = [int]$r.StatusCode
} catch {
    if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
}

if ($status) {
    Write-Host " OK    The server answered (HTTP $status)." -ForegroundColor Green
    exit 0
}
Write-Host ' WARN  The server PC answered, but the web page did not load.' -ForegroundColor Yellow
Write-Host '       Apache or the application may be stopped on the server.' -ForegroundColor Gray
exit 2
