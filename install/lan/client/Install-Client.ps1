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
