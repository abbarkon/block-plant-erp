<#
.SYNOPSIS
    Installs, replaces or backs up the Block Plant ERP database on this PC.

.DESCRIPTION
    Three modes:

      Full    Replace the database with the full dump, including the current
              employees, attendance, payroll, sales and accounting records.

      Clean   Erase the database and install a fresh one with only the default
              admin login and the starter master data.

      Backup  Do not change anything - just write a timestamped dump of the
              current database so it can be restored later.

    A safety backup of the existing database is always written before Full or
    Clean replaces it.

.PARAMETER Mode
    Full, Clean or Backup. Default: Full.

.PARAMETER BackupDir
    Where backups are written. Default: install\lan\backups

.PARAMETER MySqlUser
    MySQL user. Default: root.

.PARAMETER MySqlPassword
    MySQL password. Default: empty (XAMPP default).

.PARAMETER Force
    Skip the typed confirmation for Clean.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File Install-Database.ps1 -Mode Full

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File Install-Database.ps1 -Mode Clean
#>
[CmdletBinding()]
param(
    [ValidateSet('Full', 'Clean', 'Backup')]
    [string]$Mode = 'Full',

    [string]$BackupDir,

    [string]$MySqlUser = 'root',
    [string]$MySqlPassword = '',

    [switch]$Force
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

$dbName = 'block_plant'
$kitDir = $PSScriptRoot
$dbDir  = Join-Path $kitDir 'database'

if (-not $BackupDir) { $BackupDir = Join-Path $kitDir 'backups' }

# ---------------------------------------------------------------------------
# Locate the MySQL tools
# ---------------------------------------------------------------------------
$candidates = @(
    (Resolve-Path (Join-Path $PSScriptRoot '..\..\..\..') -ErrorAction SilentlyContinue).Path,
    'C:\xampp',
    'G:\xampp'
)
$XamppPath = $null
foreach ($c in $candidates) {
    if ($c -and (Test-Path (Join-Path $c 'mysql\bin\mysql.exe'))) { $XamppPath = $c; break }
}
if (-not $XamppPath) {
    Write-Bad 'XAMPP MySQL tools were not found (mysql.exe / mysqldump.exe).'
    Write-Info 'Run this from the XAMPP installation, or install XAMPP first.'
    exit 1
}

$mysqlExe = Join-Path $XamppPath 'mysql\bin\mysql.exe'
$dumpExe  = Join-Path $XamppPath 'mysql\bin\mysqldump.exe'

$authArgs = @("-u", $MySqlUser)
if ($MySqlPassword -ne '') { $authArgs += "--password=$MySqlPassword" }

function Invoke-MySqlFile {
    param([string]$FilePath, [string]$Database)
    $argList = @()
    $argList += $authArgs
    $argList += '--default-character-set=utf8mb4'
    if ($Database) { $argList += $Database }

    $stderr = [System.IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $mysqlExe -ArgumentList $argList `
             -RedirectStandardInput $FilePath -RedirectStandardError $stderr `
             -NoNewWindow -PassThru -Wait
        $errText = ''
        if (Test-Path $stderr) { $errText = (Get-Content $stderr -Raw) }
        return @{ Code = $p.ExitCode; Error = $errText }
    } finally {
        Remove-Item $stderr -ErrorAction SilentlyContinue
    }
}

function Invoke-MySqlQuery {
    param([string]$Query, [string]$Database)
    $argList = @()
    $argList += $authArgs
    $argList += @('-N', '-B')
    if ($Database) { $argList += $Database }
    $argList += @('-e', $Query)
    return (& $mysqlExe @argList 2>&1)
}

function Get-DbExists {
    $result = Invoke-MySqlQuery "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$dbName';"
    return (($result | Out-String).Trim() -eq '1')
}

function New-SafetyBackup {
    if (-not (Get-DbExists)) {
        Write-Info 'No existing database - nothing to back up.'
        return $null
    }
    if (-not (Test-Path $BackupDir)) { New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null }
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $path  = Join-Path $BackupDir "block_plant_before_${Mode}_$stamp.sql"

    $dumpArgs = @($authArgs) + @(
        '--routines', '--triggers', '--single-transaction',
        '--default-character-set=utf8mb4', '--databases', $dbName
    )
    $stderr = [System.IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $dumpExe -ArgumentList $dumpArgs `
             -RedirectStandardOutput $path -RedirectStandardError $stderr `
             -NoNewWindow -PassThru -Wait
        if ($p.ExitCode -ne 0) {
            $errText = (Get-Content $stderr -Raw)
            throw "mysqldump failed: $errText"
        }
    } finally {
        Remove-Item $stderr -ErrorAction SilentlyContinue
    }

    $sizeKb = [math]::Round((Get-Item $path).Length / 1KB, 1)
    Write-Ok "Safety backup written: $path ($sizeKb KB)"
    return $path
}

Write-Head "Block Plant ERP - database install ($Mode)"

# ---------------------------------------------------------------------------
# Backup mode
# ---------------------------------------------------------------------------
if ($Mode -eq 'Backup') {
    Write-Step 'Writing a backup of the current database'
    $path = New-SafetyBackup
    if ($path) {
        Write-Host ''
        Write-Host ' Restore it later with:' -ForegroundColor White
        Write-Host "   `"$mysqlExe`" -u $MySqlUser < `"$path`"" -ForegroundColor Gray
    }
    exit 0
}

# ---------------------------------------------------------------------------
# Pick the dump file
# ---------------------------------------------------------------------------
if ($Mode -eq 'Full') {
    $dumpFile = Join-Path $dbDir 'block_plant_full.sql'
    $description = 'the current company data (employees, attendance, payroll, sales, accounting)'
} else {
    $dumpFile = Join-Path $dbDir 'block_plant_clean.sql'
    $description = 'an empty system with only the default admin login and starter master data'
}

if (-not (Test-Path $dumpFile)) {
    Write-Bad "Dump file not found: $dumpFile"
    exit 1
}
$dumpKb = [math]::Round((Get-Item $dumpFile).Length / 1KB, 1)

Write-Step 'Install plan'
Write-Info "Mode:      $Mode"
Write-Info "Source:    $dumpFile ($dumpKb KB)"
Write-Info "Target:    database '$dbName' on this PC"
Write-Info "Contents:  $description"

# ---------------------------------------------------------------------------
# Confirmation
# ---------------------------------------------------------------------------
if ($Mode -eq 'Clean' -and -not $Force) {
    Write-Host ''
    Write-Host ' WARNING: this ERASES the current database and every record in it.' -ForegroundColor Red
    Write-Host ' A safety backup is taken first, but the application will start empty.' -ForegroundColor Yellow
    Write-Host ''
    $answer = Read-Host ' Type ERASE to continue'
    if ($answer -cne 'ERASE') {
        Write-Bad 'Cancelled. Nothing was changed.'
        exit 1
    }
} elseif ($Mode -eq 'Full' -and -not $Force) {
    Write-Host ''
    Write-Host ' The current database will be REPLACED by the data in the dump.' -ForegroundColor Yellow
    Write-Host ' Any record created after the dump was taken will be lost.' -ForegroundColor Yellow
    Write-Host ''
    $answer = Read-Host ' Type YES to continue'
    if ($answer -cne 'YES') {
        Write-Bad 'Cancelled. Nothing was changed.'
        exit 1
    }
}

# ---------------------------------------------------------------------------
# Safety backup, then import
# ---------------------------------------------------------------------------
Write-Step 'Backing up the existing database'
try {
    New-SafetyBackup | Out-Null
} catch {
    Write-Bad "Could not back up the existing database: $($_.Exception.Message)"
    Write-Info 'The install was stopped so nothing is lost.'
    exit 1
}

Write-Step 'Importing the database'
if ($Mode -eq 'Clean' -and (Get-DbExists)) {
    Write-Info "Dropping database '$dbName'..."
    $drop = Invoke-MySqlQuery "DROP DATABASE IF EXISTS ``$dbName``;"
    if (($drop | Out-String).Trim() -ne '') {
        Write-Bad "Could not drop the database: $drop"
        exit 1
    }
}

$import = Invoke-MySqlFile -FilePath $dumpFile
if ($import.Code -ne 0 -or ($import.Error -and $import.Error -notmatch 'Using a password')) {
    Write-Bad "Import failed (exit code $($import.Code))."
    if ($import.Error) { Write-Info $import.Error.Trim() }
    Write-Info "Your previous data is still in the backup folder: $BackupDir"
    exit 1
}
Write-Ok 'Import finished without errors.'

# ---------------------------------------------------------------------------
# Verify
# ---------------------------------------------------------------------------
Write-Step 'Verifying the installation'

$tables = (Invoke-MySqlQuery "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbName';" | Out-String).Trim()
$users  = (Invoke-MySqlQuery "SELECT COUNT(*) FROM users;" $dbName | Out-String).Trim()
$emps   = (Invoke-MySqlQuery "SELECT COUNT(*) FROM employees;" $dbName | Out-String).Trim()

Write-Info "Tables:    $tables"
Write-Info "Users:     $users"
Write-Info "Employees: $emps"

if ([int]$tables -lt 50) {
    Write-Bad 'The database looks incomplete (fewer than 50 tables).'
    exit 1
}
Write-Ok 'Database installed successfully.'

Write-Host ''
if ($Mode -eq 'Clean') {
    Write-Host ' Login: admin / password  -  change it right after the first login.' -ForegroundColor Yellow
}
Write-Host ' Open the application and sign in to confirm everything works.' -ForegroundColor White
exit 0
