<#
.SYNOPSIS
    Builds the folder that is copied to each office PC.

.DESCRIPTION
    Writes the client installer, the desktop shortcut and a README into
    install\lan\client, then zips it to install\lan\Block-Plant-Client.zip.

    The server address is detected from this PC (LAN IP or host name) and
    written into every file, so the client only has to double-click
    Install-Client.bat.

.PARAMETER ServerUrl
    Address the clients should use, for example http://192.168.5.96/block_plant/
    Detected automatically when omitted.

.PARAMETER OutputDir
    Folder to write. Default: install\lan\client

.PARAMETER NoZip
    Do not create the ZIP file.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File Create-Client-Package.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File Create-Client-Package.ps1 -ServerUrl "http://192.168.5.96/block_plant/"
#>
[CmdletBinding()]
param(
    [string]$ServerUrl,
    [string]$OutputDir,
    [switch]$NoZip
)

$ErrorActionPreference = 'Stop'

function Write-Head($Text) {
    Write-Host ''
    Write-Host ('=' * 72) -ForegroundColor DarkGray
    Write-Host " $Text" -ForegroundColor Cyan
    Write-Host ('=' * 72) -ForegroundColor DarkGray
}
function Write-Step($Text) { Write-Host ''; Write-Host "-> $Text" -ForegroundColor Cyan }
function Write-Ok($Text)   { Write-Host "   [ OK ]   $Text" -ForegroundColor Green }
function Write-Bad($Text)  { Write-Host "   [FAIL]   $Text" -ForegroundColor Red }
function Write-Info($Text) { Write-Host "            $Text" -ForegroundColor Gray }

$kitDir  = $PSScriptRoot
$appRoot = (Resolve-Path (Join-Path $kitDir '..\..')).Path
$appName = Split-Path $appRoot -Leaf

if (-not $OutputDir) { $OutputDir = Join-Path $kitDir 'client' }

# ---------------------------------------------------------------------------
# Work out the server address
# ---------------------------------------------------------------------------
Write-Head 'Block Plant ERP - client package builder'

if (-not $ServerUrl) {
    Write-Step 'Detecting the server address'

    $route = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
             Sort-Object RouteMetric, InterfaceMetric | Select-Object -First 1
    $lanIp = $null
    if ($route) {
        $lanIp = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $route.InterfaceIndex -ErrorAction SilentlyContinue |
                  Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
                  Select-Object -First 1).IPAddress
    }
    if (-not $lanIp) {
        $lanIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
                  Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
                  Select-Object -First 1).IPAddress
    }
    if (-not $lanIp) {
        Write-Bad 'No LAN IP address found.'
        Write-Info 'Pass the address yourself: -ServerUrl "http://192.168.5.96/block_plant/"'
        exit 1
    }
    $ServerUrl = "http://$lanIp/$appName/"
    Write-Ok "Server IP:   $lanIp"
} else {
    Write-Step 'Using the supplied server address'
}

if ($ServerUrl -notmatch '^https?://') { $ServerUrl = "http://$ServerUrl" }
if (-not $ServerUrl.EndsWith('/'))    { $ServerUrl = "$ServerUrl/" }

$hostName = $env:COMPUTERNAME
$altUrl   = "http://$hostName/$appName/"

Write-Ok "Server URL:  $ServerUrl"
Write-Ok "Backup URL:  $altUrl"

# ---------------------------------------------------------------------------
# Create the folder
# ---------------------------------------------------------------------------
Write-Step 'Writing the client folder'

if (-not (Test-Path $OutputDir)) { New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null }
Get-ChildItem $OutputDir -File | Remove-Item -Force -ErrorAction SilentlyContinue

# --- server-url.txt -------------------------------------------------------
$serverUrlTxt = @"
$ServerUrl
"@
Set-Content -Path (Join-Path $OutputDir 'server-url.txt') -Value $serverUrlTxt -Encoding ASCII

# --- Internet shortcut ----------------------------------------------------
$urlShortcut = @"
[InternetShortcut]
URL=$ServerUrl
IconIndex=0
"@
Set-Content -Path (Join-Path $OutputDir 'Block Plant ERP.url') -Value $urlShortcut -Encoding ASCII

# --- Install-Client.ps1 ---------------------------------------------------
$clientPs1 = @'
param(
    [string]$ServerUrl,
    [switch]$NoDesktop,
    [switch]$NoStartMenu,
    [switch]$Quiet
)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path

function Say($Text, $Color) {
    if ($Quiet) { return }
    if ($Color) { Write-Host $Text -ForegroundColor $Color } else { Write-Host $Text }
}

if (-not $ServerUrl) {
    $urlFile = Join-Path $here 'server-url.txt'
    if (-not (Test-Path $urlFile)) {
        Say 'server-url.txt is missing from this folder.' 'Red'
        Say 'Ask your administrator for the server address.' 'Gray'
        exit 1
    }
    $ServerUrl = (Get-Content $urlFile -Raw).Trim()
}
if (-not $ServerUrl) {
    Say 'The server address is empty.' 'Red'
    exit 1
}
if ($ServerUrl -notmatch '^https?://') { $ServerUrl = "http://$ServerUrl" }
if (-not $ServerUrl.EndsWith('/'))    { $ServerUrl = "$ServerUrl/" }

Say ''
Say ' Block Plant ERP - client setup' 'Cyan'
Say ' ------------------------------' 'DarkGray'
Say ''
Say " Server: $ServerUrl" 'White'

# ---------------------------------------------------------------------------
# 1. Check the server answers
# ---------------------------------------------------------------------------
Say ''
Say ' Checking the connection to the server...' 'Cyan'

$uri  = [Uri]$ServerUrl
$port = if ($uri.Port -gt 0) { $uri.Port } else { 80 }
$reachable = $false
try {
    $client = New-Object System.Net.Sockets.TcpClient
    $async  = $client.BeginConnect($uri.Host, $port, $null, $null)
    if ($async.AsyncWaitHandle.WaitOne(5000, $false) -and $client.Connected) { $reachable = $true }
    $client.Close()
} catch {
    $reachable = $false
}

$httpOk = $false
if ($reachable) {
    try {
        $response = Invoke-WebRequest -Uri $ServerUrl -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        $httpOk = $true
    } catch {
        $resp = $_.Exception.Response
        if ($resp -and $resp.StatusCode) { $httpOk = $true }   # login redirect counts
    }
}

if ($httpOk) {
    Say ' The server answered correctly.' 'Green'
} elseif ($reachable) {
    Say ' The server PC is reachable but the web page did not load.' 'Yellow'
    Say ' The application may be stopped. The shortcut is still created.' 'Gray'
} else {
    Say ' Cannot reach the server right now.' 'Red'
    Say ' Check that the server PC is switched on and connected to the office network,' 'Gray'
    Say ' and that its firewall allows the connection.' 'Gray'
    Say ' The shortcut is still created so you can try again later.' 'Gray'
}

# ---------------------------------------------------------------------------
# 2. Create the shortcuts
# ---------------------------------------------------------------------------
Say ''
Say ' Creating shortcuts...' 'Cyan'

$shell = New-Object -ComObject WScript.Shell

function New-UrlShortcut {
    param([string]$Path)
    $parent = Split-Path $Path -Parent
    if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Force -Path $parent | Out-Null }
    $lines = @('[InternetShortcut]', "URL=$ServerUrl", 'IconIndex=0')
    Set-Content -Path $Path -Value $lines -Encoding ASCII
}

function Find-Browser {
    $list = @(
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    )
    foreach ($path in $list) {
        if ($path -and (Test-Path $path)) { return $path }
    }
    return $null
}

function New-AppShortcut {
    param([string]$Path, [string]$Browser)
    $parent = Split-Path $Path -Parent
    if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Force -Path $parent | Out-Null }
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath       = $Browser
    $shortcut.Arguments        = "--app=$ServerUrl"
    $shortcut.WorkingDirectory = Split-Path $Browser -Parent
    $shortcut.IconLocation     = "$Browser,0"
    $shortcut.Description      = 'Block Plant ERP'
    $shortcut.Save()
}

$browser = Find-Browser
$desktop = [Environment]::GetFolderPath('Desktop')
$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) 'Block Plant ERP'

$created = @()

if (-not $NoDesktop -and $desktop) {
    if ($browser) {
        $lnk = Join-Path $desktop 'Block Plant ERP.lnk'
        New-AppShortcut -Path $lnk -Browser $browser
        $created += $lnk
    } else {
        $url = Join-Path $desktop 'Block Plant ERP.url'
        New-UrlShortcut -Path $url
        $created += $url
    }
}

if (-not $NoStartMenu -and $startMenu) {
    if ($browser) {
        $lnk = Join-Path $startMenu 'Block Plant ERP.lnk'
        New-AppShortcut -Path $lnk -Browser $browser
        $created += $lnk
    } else {
        $url = Join-Path $startMenu 'Block Plant ERP.url'
        New-UrlShortcut -Path $url
        $created += $url
    }
}

foreach ($item in $created) {
    Say "   $item" 'Gray'
}
if ($created.Count -eq 0) {
    Say '   No shortcut could be created (no Desktop or Start Menu folder found).' 'Yellow'
}

# ---------------------------------------------------------------------------
# 3. Open the application
# ---------------------------------------------------------------------------
if ($httpOk -and -not $Quiet) {
    Say ''
    $answer = Read-Host ' Open Block Plant ERP now? [Y/n]'
    if ($answer -eq '' -or $answer -match '^[Yy]') {
        if ($browser) { Start-Process -FilePath $browser -ArgumentList "--app=$ServerUrl" }
        else { Start-Process $ServerUrl }
    }
}

Say ''
if ($created.Count -gt 0) {
    Say ' Done. Double-click "Block Plant ERP" on the Desktop to start.' 'Green'
    Say ' The first time, sign in with the account your administrator gave you.' 'Gray'
} else {
    Say ' Finished with warnings. See the messages above.' 'Yellow'
}
Say ''
exit 0
'@
Set-Content -Path (Join-Path $OutputDir 'Install-Client.ps1') -Value $clientPs1 -Encoding UTF8

# --- Install-Client.bat ---------------------------------------------------
$clientBat = @'
@echo off
setlocal
title Block Plant ERP - client setup
cd /d "%~dp0"

echo.
echo  Installing the Block Plant ERP shortcut for this PC...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Client.ps1" %*
set RESULT=%ERRORLEVEL%

echo.
if not "%RESULT%"=="0" (
    echo  Setup did not finish. Read the messages above.
) else (
    echo  Setup finished.
)
echo.
pause
exit /b %RESULT%
'@
Set-Content -Path (Join-Path $OutputDir 'Install-Client.bat') -Value $clientBat -Encoding ASCII

# --- Uninstall-Client.ps1 -------------------------------------------------
$uninstallPs1 = @'
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
'@
Set-Content -Path (Join-Path $OutputDir 'Uninstall-Client.ps1') -Value $uninstallPs1 -Encoding UTF8

$uninstallBat = @'
@echo off
setlocal
title Block Plant ERP - remove shortcut
cd /d "%~dp0"

echo.
echo  Removing the Block Plant ERP shortcuts from this PC...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Uninstall-Client.ps1"

echo.
pause
exit /b 0
'@
Set-Content -Path (Join-Path $OutputDir 'Uninstall-Client.bat') -Value $uninstallBat -Encoding ASCII

# --- Test-Connection.ps1 --------------------------------------------------
$testPs1 = @'
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
'@
Set-Content -Path (Join-Path $OutputDir 'Test-Connection.ps1') -Value $testPs1 -Encoding UTF8

$testBat = @'
@echo off
setlocal
title Block Plant ERP - connection test
cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Test-Connection.ps1"

echo.
pause
exit /b 0
'@
Set-Content -Path (Join-Path $OutputDir 'Test-Connection.bat') -Value $testBat -Encoding ASCII

# --- README.txt -----------------------------------------------------------
$readme = @"
BLOCK PLANT ERP - CLIENT SETUP
==============================

This folder connects this computer to the Block Plant ERP system that runs on
the office server. Nothing is installed on this PC and no data is stored here.

SERVER
  $ServerUrl

HOW TO SET IT UP
  1. Copy this whole folder to the computer (for example to the Desktop or to
     C:\BlockPlantClient).
  2. Double-click  Install-Client.bat
  3. Wait for the "Done" message. A shortcut named "Block Plant ERP" appears on
     the Desktop and in the Start Menu.
  4. Double-click the shortcut and sign in with the account your administrator
     gave you.

WHAT THE SETUP DOES
  - Checks that the server answers.
  - Creates the desktop and Start Menu shortcuts.
  It does not install software, change Windows settings or copy any company data.

IF THE SHORTCUT DOES NOT OPEN
  1. Make sure the server PC is switched on and connected to the office network.
  2. Run  Test-Connection.bat  to see whether the server answers.
  3. If the server address changed, ask your administrator for the new address
     and replace the contents of  server-url.txt  with it (keep the trailing /).
  4. Check that you are on the office network (not a guest Wi-Fi or mobile data).

TO REMOVE THE SHORTCUTS
  Run  Uninstall-Client.bat

NOTES
  - The server must stay switched on while people use the system.
  - Only one copy of the database exists - on the server. Every PC sees the same
    data, so no synchronisation is needed.
  - Do not rename the server PC or change its IP address without rebuilding this
    package (run Create-Client-Package.bat on the server again).
"@
Set-Content -Path (Join-Path $OutputDir 'README.txt') -Value $readme -Encoding UTF8

Write-Ok "Client files written to $OutputDir"

# ---------------------------------------------------------------------------
# ZIP
# ---------------------------------------------------------------------------
if (-not $NoZip) {
    Write-Step 'Creating the ZIP file'
    $zipPath = Join-Path $kitDir 'Block-Plant-Client.zip'
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path (Join-Path $OutputDir '*') -DestinationPath $zipPath -CompressionLevel Optimal
    $sizeKb = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
    Write-Ok "ZIP created: $zipPath ($sizeKb KB)"
}

Write-Host ''
Write-Host ' Next step:' -ForegroundColor White
Write-Host '   Copy the client folder (or the ZIP) to each office PC and run' -ForegroundColor Gray
Write-Host '   Install-Client.bat there.' -ForegroundColor Gray
Write-Host ''
exit 0
