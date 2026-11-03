<#
.SYNOPSIS
    Prepares this PC to serve Block Plant ERP to other computers on the office LAN.

.DESCRIPTION
    Run this once on the server PC (the PC where XAMPP and the application are
    installed). It performs the checks and changes that need Administrator rights:

      1. Confirms Apache and MySQL are running and start automatically.
      2. Opens TCP port 80 in Windows Firewall for the local network.
      3. Makes MySQL listen on 127.0.0.1 only, so other PCs cannot reach the
         database directly (they only need the web pages).
      4. Confirms the application answers on the LAN IP address and hostname.

    Nothing here deletes data. my.ini is backed up before it is edited.

.PARAMETER XamppPath
    XAMPP installation folder. Detected automatically when omitted.

.PARAMETER Port
    HTTP port used by Apache. Default 80.

.PARAMETER SkipMySqlHardening
    Leave the MySQL bind-address untouched.

.PARAMETER SkipFirewall
    Do not create or change firewall rules.

.PARAMETER DryRun
    Show what would be changed without touching the system. Runs without
    Administrator rights as well.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File Setup-LAN.ps1
#>
[CmdletBinding()]
param(
    [string]$XamppPath,
    [int]$Port = 80,
    [switch]$SkipMySqlHardening,
    [switch]$SkipFirewall,
    [switch]$DryRun
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
function Write-Warn($Text) { Write-Host "   [WARN]   $Text" -ForegroundColor Yellow }
function Write-Bad($Text)  { Write-Host "   [FAIL]   $Text" -ForegroundColor Red }
function Write-Info($Text) { Write-Host "            $Text" -ForegroundColor Gray }
function Write-Would($Text) { Write-Host "   [DRY]    would $Text" -ForegroundColor Magenta }

$problems = New-Object System.Collections.Generic.List[string]
$changes  = New-Object System.Collections.Generic.List[string]

# ---------------------------------------------------------------------------
# 0. Administrator check
# ---------------------------------------------------------------------------
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
$isAdmin   = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

Write-Head 'Block Plant ERP - LAN server setup'

if (-not $isAdmin -and -not $DryRun) {
    Write-Bad 'This script must run as Administrator.'
    Write-Info 'Close this window and double-click Setup-LAN.bat instead.'
    Write-Info 'That launcher asks Windows for permission automatically.'
    exit 1
}
if ($isAdmin) {
    Write-Ok 'Running with Administrator rights.'
} else {
    Write-Warn 'Not running as Administrator - this is a dry run, nothing will be changed.'
    $DryRun = [switch]$true
}

# ---------------------------------------------------------------------------
# 1. Locate XAMPP and the application
# ---------------------------------------------------------------------------
Write-Step 'Locating XAMPP and the application'

if (-not $XamppPath) {
    $candidate = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..\..') -ErrorAction SilentlyContinue).Path
    if ($candidate -and (Test-Path (Join-Path $candidate 'apache\bin\httpd.exe'))) {
        $XamppPath = $candidate
    } elseif (Test-Path 'C:\xampp\apache\bin\httpd.exe') {
        $XamppPath = 'C:\xampp'
    } elseif (Test-Path 'G:\xampp\apache\bin\httpd.exe') {
        $XamppPath = 'G:\xampp'
    }
}

if (-not $XamppPath -or -not (Test-Path (Join-Path $XamppPath 'apache\bin\httpd.exe'))) {
    Write-Bad 'XAMPP was not found.'
    Write-Info 'Re-run with the folder, for example:'
    Write-Info '  Setup-LAN.ps1 -XamppPath "C:\xampp"'
    exit 1
}
Write-Ok "XAMPP:        $XamppPath"

$appRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$appName = Split-Path $appRoot -Leaf
$htdocs  = Join-Path $XamppPath 'htdocs'
if ((Resolve-Path $htdocs).Path -ne (Resolve-Path (Split-Path $appRoot -Parent)).Path) {
    Write-Warn "The application is not inside $htdocs"
    Write-Info 'Clients will only reach it if Apache serves that folder.'
}
Write-Ok "Application:  $appRoot"
Write-Ok "Web path:     /$appName/"

$httpdConf = Join-Path $XamppPath 'apache\conf\httpd.conf'
$myIni     = Join-Path $XamppPath 'mysql\bin\my.ini'
$mysqlExe  = Join-Path $XamppPath 'mysql\bin\mysql.exe'

# ---------------------------------------------------------------------------
# 2. Network details
# ---------------------------------------------------------------------------
Write-Step 'Reading network details'

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
    Write-Bad 'No LAN IP address found. Connect this PC to the office network first.'
    exit 1
}

$computerName = $env:COMPUTERNAME
Write-Ok "LAN IP:       $lanIp"
Write-Ok "Host name:    $computerName"

$profileName = $null
$connProfile = Get-NetConnectionProfile -ErrorAction SilentlyContinue |
               Where-Object { $_.InterfaceAlias -eq $route.InterfaceAlias } | Select-Object -First 1
if ($connProfile) {
    $profileName = $connProfile.NetworkCategory
    Write-Ok "Network type: $profileName"
    if ($profileName -eq 'Public') {
        Write-Warn 'The network is marked Public. Windows blocks file and printer sharing there.'
        Write-Info 'Switch it to Private (Settings > Network > Properties) for the smoothest setup.'
    }
}

# ---------------------------------------------------------------------------
# 3. Services
# ---------------------------------------------------------------------------
Write-Step 'Checking Apache and MySQL services'

$apacheService = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
if (-not $apacheService) {
    $apacheService = Get-Service | Where-Object { $_.Name -like 'Apache*' } | Select-Object -First 1
}
$mysqlService = Get-Service -Name 'mysql' -ErrorAction SilentlyContinue
if (-not $mysqlService) {
    $mysqlService = Get-Service | Where-Object { $_.Name -match '^(mysql|mariadb)' } | Select-Object -First 1
}

foreach ($svc in @($apacheService, $mysqlService)) {
    if (-not $svc) { continue }
    if ($svc.Status -ne 'Running') {
        if ($DryRun) {
            Write-Would "start service $($svc.Name)"
        } else {
            Start-Service -Name $svc.Name
            Write-Ok "Started $($svc.Name)."
        }
        $changes.Add("Start service $($svc.Name)")
    } else {
        Write-Ok "$($svc.Name) is running."
    }
    if ($svc.StartType -ne 'Automatic') {
        if ($DryRun) {
            Write-Would "set $($svc.Name) to start automatically"
        } else {
            Set-Service -Name $svc.Name -StartupType Automatic
            Write-Ok "$($svc.Name) set to start automatically."
        }
        $changes.Add("Set $($svc.Name) to start automatically")
    }
}
if (-not $apacheService) { Write-Bad 'Apache service not found.'; $problems.Add('Apache service missing') }
if (-not $mysqlService)  { Write-Bad 'MySQL service not found.';  $problems.Add('MySQL service missing') }

# Apache must listen on all interfaces, not just localhost.
if (Test-Path $httpdConf) {
    $conf = Get-Content $httpdConf
    $changedConf = $false
    for ($i = 0; $i -lt $conf.Count; $i++) {
        if ($conf[$i] -match "^\s*Listen\s+127\.0\.0\.1:$Port\s*$") {
            $conf[$i] = "Listen $Port"
            $changedConf = $true
        }
    }
    if ($changedConf) {
        if ($DryRun) {
            Write-Would "change '$httpdConf' to 'Listen $Port' and restart Apache"
        } else {
            Copy-Item $httpdConf "$httpdConf.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
            Set-Content -Path $httpdConf -Value $conf
            if ($apacheService) {
                Restart-Service -Name $apacheService.Name -Force
                Start-Sleep -Seconds 2
            }
            Write-Ok 'Apache now listens on all network interfaces.'
        }
        $changes.Add('Apache Listen changed to all interfaces')
    } else {
        Write-Ok 'Apache listens on all network interfaces.'
    }
}

# ---------------------------------------------------------------------------
# 4. Firewall
# ---------------------------------------------------------------------------
Write-Step "Checking Windows Firewall for TCP port $Port"

if ($SkipFirewall) {
    Write-Warn 'Skipped (-SkipFirewall).'
} else {
    $ruleName = "Block Plant ERP (TCP $Port)"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        if ($existing.Enabled -ne 'True') {
            if ($DryRun) {
                Write-Would "enable firewall rule '$ruleName'"
            } else {
                Enable-NetFirewallRule -DisplayName $ruleName
            }
            $changes.Add("Enable firewall rule $ruleName")
        }
        Write-Ok "Firewall rule '$ruleName' already exists."
    } else {
        # Reuse an existing Apache rule if one is already open.
        $apacheRule = Get-NetFirewallRule -DisplayName '*Apache*' -ErrorAction SilentlyContinue |
                      Where-Object { $_.Enabled -eq 'True' -and $_.Direction -eq 'Inbound' -and $_.Action -eq 'Allow' } |
                      Select-Object -First 1
        if ($apacheRule) {
            Write-Ok "An Apache firewall rule is already enabled ('$($apacheRule.DisplayName)')."
            Write-Info 'No new rule was needed.'
        } elseif ($DryRun) {
            Write-Would "create firewall rule '$ruleName' for TCP $Port (Domain and Private)"
            $changes.Add("Create firewall rule $ruleName")
        } else {
            New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow `
                -Protocol TCP -LocalPort $Port -Profile Domain,Private `
                -Description 'Allows office PCs to open Block Plant ERP in a browser.' | Out-Null
            $changes.Add("Create firewall rule $ruleName")
            Write-Ok "Created firewall rule '$ruleName' (Domain and Private networks)."
        }
    }
}

# ---------------------------------------------------------------------------
# 5. MySQL should not be reachable from the network
# ---------------------------------------------------------------------------
Write-Step 'Hardening MySQL'

if ($SkipMySqlHardening) {
    Write-Warn 'Skipped (-SkipMySqlHardening).'
} elseif (-not (Test-Path $myIni)) {
    Write-Warn "my.ini not found at $myIni - skipped."
} else {
    $ini = Get-Content $myIni
    $sectionStart = -1
    for ($i = 0; $i -lt $ini.Count; $i++) {
        if ($ini[$i].Trim() -eq '[mysqld]') { $sectionStart = $i; break }
    }
    if ($sectionStart -lt 0) {
        Write-Warn 'No [mysqld] section in my.ini - skipped.'
    } else {
        $sectionEnd = $ini.Count - 1
        for ($i = $sectionStart + 1; $i -lt $ini.Count; $i++) {
            if ($ini[$i].Trim() -match '^\[') { $sectionEnd = $i - 1; break }
        }

        $bindIndex = -1
        for ($i = $sectionStart + 1; $i -le $sectionEnd; $i++) {
            if ($ini[$i] -match '^\s*bind-address\s*=') { $bindIndex = $i; break }
        }

        $alreadyBound = $false
        if ($bindIndex -ge 0) {
            if ($ini[$bindIndex] -match '^\s*bind-address\s*=\s*127\.0\.0\.1\s*$') {
                $alreadyBound = $true
            }
        }

        if ($alreadyBound) {
            Write-Ok 'MySQL already listens on 127.0.0.1 only.'
        } else {
            $backup = "$myIni.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
            if ($DryRun) {
                Write-Would "set bind-address=127.0.0.1 in '$myIni' and restart MySQL"
            } else {
                Copy-Item $myIni $backup
                if ($bindIndex -ge 0) {
                    $ini[$bindIndex] = 'bind-address=127.0.0.1'
                } else {
                    $insertAt = $sectionStart + 1
                    $before = @()
                    $after  = @()
                    if ($insertAt -gt 0) { $before = $ini[0..($insertAt - 1)] }
                    if ($insertAt -le $ini.Count - 1) { $after = $ini[$insertAt..($ini.Count - 1)] }
                    $ini = @($before) + @('bind-address=127.0.0.1') + @($after)
                }
                Set-Content -Path $myIni -Value $ini
                if ($mysqlService) {
                    Restart-Service -Name $mysqlService.Name -Force
                    Start-Sleep -Seconds 2
                }
                Write-Ok 'MySQL now listens on 127.0.0.1 only (local connections still work).'
                Write-Info "Backup of the old file: $backup"
            }
            $changes.Add('Bind MySQL to 127.0.0.1')
        }
    }
}

# ---------------------------------------------------------------------------
# 6. Verify
# ---------------------------------------------------------------------------
Write-Step 'Verifying the application'

function Test-AppUrl($Url) {
    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        return @{ Ok = $true; Status = [int]$response.StatusCode }
    } catch {
        $status = $null
        try {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $status = [int]$_.Exception.Response.StatusCode
            }
        } catch {
            $status = $null
        }
        # 2xx/3xx means the web server answered (the login page may redirect).
        if ($status -and $status -ge 200 -and $status -lt 400) {
            return @{ Ok = $true; Status = $status }
        }
        $reason = if ($status) { "HTTP $status" } else { $_.Exception.Message }
        return @{ Ok = $false; Status = $reason }
    }
}

$checks = @(
    @{ Label = 'Localhost';  Url = "http://localhost/$appName/" },
    @{ Label = 'LAN IP';     Url = "http://$lanIp/$appName/" },
    @{ Label = 'Host name';  Url = "http://$computerName/$appName/" }
)
foreach ($check in $checks) {
    $result = Test-AppUrl $check.Url
    if ($result.Ok) {
        Write-Ok ("{0,-10} {1}  (HTTP {2})" -f $check.Label, $check.Url, $result.Status)
    } else {
        Write-Bad ("{0,-10} {1}  -> {2}" -f $check.Label, $check.Url, $result.Status)
        $problems.Add("$($check.Url) did not answer")
    }
}

# MySQL listening state
if ($mysqlService) {
    $listen = Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue |
              Select-Object -ExpandProperty LocalAddress -Unique
    if ($listen) {
        Write-Info ("MySQL is listening on: " + ($listen -join ', '))
        if ($listen -contains '0.0.0.0' -or $listen -contains '::') {
            Write-Warn 'MySQL is still reachable from the network. Run this script again without -SkipMySqlHardening.'
        }
    }
}

# ---------------------------------------------------------------------------
# 7. Summary
# ---------------------------------------------------------------------------
Write-Head 'Summary'

if ($DryRun) {
    Write-Host ' DRY RUN - nothing was changed.' -ForegroundColor Magenta
}

if ($changes.Count -gt 0) {
    if ($DryRun) { Write-Host ' Changes that would be made:' -ForegroundColor White }
    else         { Write-Host ' Changes made:' -ForegroundColor White }
    foreach ($c in $changes) { Write-Host "   - $c" -ForegroundColor Gray }
} else {
    Write-Host ' No changes were needed.' -ForegroundColor Gray
}

if ($problems.Count -gt 0) {
    Write-Host ''
    Write-Host ' Problems found:' -ForegroundColor Red
    foreach ($p in $problems) { Write-Host "   - $p" -ForegroundColor Red }
}

Write-Host ''
Write-Host ' Clients on the office network can open:' -ForegroundColor White
Write-Host "   http://$lanIp/$appName/" -ForegroundColor Green
Write-Host "   http://$computerName/$appName/" -ForegroundColor Green
Write-Host ''
Write-Host ' Next steps:' -ForegroundColor White
Write-Host '   1. Install the database        -> Install-Database.bat (Full or Clean)'
Write-Host '   2. Build the client package    -> Create-Client-Package.bat'
Write-Host '   3. Copy the client folder/ZIP to each office PC and run Install-Client.bat'
Write-Host ''

if ($problems.Count -gt 0) { exit 1 }
exit 0
