<#
.SYNOPSIS
    Runs the whole installation, in order.

.DESCRIPTION
    Called by the installer once the files are in place. Each step is a script
    that can also be run on its own, so a failed installation can be resumed or
    a single part re-run without repeating the rest.

    Ordered by dependency: configuration before the database, because the
    database step reads it; the database before the web server, because the
    application will not start without one; the web server before the health
    check, because there is nothing to check until it is up.

    Safe to run again. Every step adopts what it finds rather than replacing it,
    which is what makes a repair and an upgrade the same operation.

.PARAMETER InstallRoot
    Where the application files were placed.

.PARAMETER Port
    Port to serve on.

.PARAMETER DbPassword
    Password for the PharmaVerify database login.

.PARAMETER Hostname
    Preferred name for handhelds to use.

.PARAMETER SkipDatabase
    Leaves the database alone. For reconfiguring an installation that already
    has one.

.PARAMETER HhtAddress
    The address the installer told handheld terminals to use. Not acted on —
    only logged, so the installation log records what the operator was shown
    without this script having to re-derive or second-guess it.

.EXAMPLE
    .\Install-PharmaVerify.ps1 -DbPassword $secure
#>

[CmdletBinding()]
param(
    [string] $InstallRoot = (Join-Path ${env:ProgramFiles} 'PharmaVerify'),
    [int]    $Port = 8000,
    # Not Mandatory: the installer supplies it through the PV_DB_PASSWORD
    # environment variable instead of the command line, because a command
    # line is readable by every process on the machine and an environment
    # variable of one short-lived elevated process is not.
    [securestring] $DbPassword,
    [string] $Hostname = 'pharmaverify.local',
    [switch] $SkipDatabase,
    [string] $HhtAddress
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

# EVIDENCE: under StrictMode, reading $LASTEXITCODE before any native command
# has ever run in the session is a terminating error - exactly how the
# 2026-08-31 07:53 installation died silently between the web-server and
# firewall steps, with the log simply stopping at 07:55:26 and no failure
# line. Primed once here, every later "if ($LASTEXITCODE -ne 0)" check below
# is safe even if the step it follows happens to be the first native command
# this session ever runs.
$global:LASTEXITCODE = 0

Initialize-PharmaVerifyLog

if (-not $DbPassword) {
    if ($env:PV_DB_PASSWORD) {
        $DbPassword = ConvertTo-SecureString $env:PV_DB_PASSWORD -AsPlainText -Force
        # Gone from this process the moment it is captured; the installer blanks
        # its own copy the moment this script returns.
        Remove-Item Env:\PV_DB_PASSWORD -ErrorAction SilentlyContinue
    } else {
        Write-Log 'No database password was provided (parameter or PV_DB_PASSWORD).' -Level FAIL
        exit 3
    }
}

# Everything from here down to the final `exit` runs inside one try/catch:
# under StrictMode, nothing may ever again stop without a FAIL line reaching
# the log, the way the 2026-08-31 07:53 run did when it died silently between
# the web-server and firewall steps. `exit N` statements inside this try are
# NOT caught by the catch below - PowerShell treats `exit` as an immediate
# process exit, not a throw - so every step's existing exit code still works
# exactly as before; this net exists only for the terminating errors no step
# already handles. Left unindented rather than reflowing ~200 lines: the
# brace below marks where the try body begins, which reads at least as
# clearly as re-indenting the whole thing.
try {

Write-Log '======================================================' -Level INFO
Write-Log "PharmaVerify installation starting" -Level INFO
Write-Log "Target: $InstallRoot, port $Port" -Level INFO
Write-Log '======================================================' -Level INFO

if (-not (Test-Administrator)) {
    Write-Log 'Installation needs administrator rights.' -Level FAIL
    exit 1
}

# Each entry is one step. Failure is fatal unless the step is optional, in which
# case installation continues and the health check reports what is missing —
# an installation that stops over a hostname would be worse than one that
# finishes and tells you the hostname needs arranging.
$steps = @(
    @{ Name = 'Network address';  Script = 'Set-Hostname.ps1';       Optional = $true  }
    @{ Name = 'Configuration';    Script = 'Set-Environment.ps1';    Optional = $false }
    @{ Name = 'Database';         Script = 'Initialize-Database.ps1'; Optional = $false }
    @{ Name = 'Web server';       Script = 'Install-WebServer.ps1';  Optional = $false }
    @{ Name = 'Firewall';         Script = 'Set-Firewall.ps1';       Optional = $true  }
)

# Settled first: the address decides what goes into the configuration, so it
# cannot be worked out later.
Write-Log '' -Level INFO
Write-Log '--- Network address ---' -Level INFO

$localEndpoint = $null
$lanEndpoint = $null
$serverAddress = $null
$additionalOrigins = @()
$databaseFailed = $false

try {
    $result = & (Join-Path $PSScriptRoot 'Set-Hostname.ps1') -Hostname $Hostname -Port $Port |
        Select-Object -Last 1 | ConvertFrom-Json

    $localEndpoint = $result.LocalEndpoint
    $lanEndpoint   = $result.LanEndpoint

    # The hostname, not the IP: the loopback hosts entry makes it work on this
    # PC, and it is what stays correct if DHCP later moves the address.
    $serverAddress = $Hostname
    if ($lanEndpoint) { $additionalOrigins = @($lanEndpoint) }
} catch {
    Write-Log "Could not settle the network address: $($_.Exception.GetType().FullName): $($_.Exception.Message)" -Level WARN
}

if (-not $localEndpoint) {
    # Set-Hostname failed outright rather than merely not resolving — fall
    # back exactly as before this script had a hostname step at all: the
    # detected IP stands in for everything.
    $lan = Get-LanAddress
    if (-not $lan) {
        Write-Log 'No network was found. Connect this PC to the store network and try again.' -Level FAIL
        exit 2
    }
    $serverAddress = $lan.IPAddress
    $localEndpoint = "http://$serverAddress`:$Port"
    $lanEndpoint = $localEndpoint
    Write-Log "Falling back to the detected address: $localEndpoint" -Level WARN
}

if ($HhtAddress) {
    Write-Log "Handheld terminals were told to use: $HhtAddress" -Level INFO
}

# ------------------------------------------------------------ configuration

Write-Log '' -Level INFO
Write-Log '--- Configuration ---' -Level INFO

try {
    & (Join-Path $PSScriptRoot 'Set-Environment.ps1') `
        -InstallRoot $InstallRoot `
        -ServerAddress $serverAddress `
        -Port $Port `
        -DbConnection 'sqlsrv' `
        -DbPort 1433 `
        -DbPassword $DbPassword `
        -AdditionalOrigins $additionalOrigins
} catch {
    Write-Log "Configuration failed: $($_.Exception.Message)" -Level FAIL
    exit 3
}

# --------------------------------------------------------------- web server

Write-Log '' -Level INFO
Write-Log "--- Port $Port ---" -Level INFO

$owner = Get-PortOwner -Port $Port
if ($owner -and -not $owner.IsOurSite) {
    Write-Log "Port $Port is already in use by $($owner.ProcessName) (process $($owner.ProcessId)). PharmaVerify needs this port. Close that program, or uninstall whatever is using it, then run the installer again." -Level FAIL
    # Never silently choose another port: every handheld is configured with
    # the documented address, and a silently different port here would strand
    # all of them with nothing on the PC explaining why.
    exit 6
} elseif ($owner) {
    Write-Log 'This port is already served by this installation; it will be updated.' -Level OK
} else {
    Write-Log "Port $Port is free." -Level OK
}

Write-Log '' -Level INFO
Write-Log '--- Web server ---' -Level INFO

& (Join-Path $PSScriptRoot 'Install-WebServer.ps1') -InstallRoot $InstallRoot -Port $Port

if ($LASTEXITCODE -ne 0) {
    Write-Log 'The web server could not be configured.' -Level FAIL
    exit 5
}

# ---------------------------------------------------------------- database

if (-not $SkipDatabase) {
    Write-Log '' -Level INFO
    Write-Log '--- Database ---' -Level INFO

    & (Join-Path $PSScriptRoot 'Initialize-Database.ps1') `
        -InstallRoot $InstallRoot `
        -DbPassword $DbPassword

    if ($LASTEXITCODE -ne 0) {
        # Reported, not fatal. The web server is already configured by this
        # point - deliberately, because a machine that stops here should still
        # open the real application and a health report naming the database as
        # the one thing left to fix. Stopping dead used to leave nothing
        # configured at all, which read as a completed install serving the
        # wrong page.
        Write-Log 'Database setup did not complete. Nothing was removed; the existing database is untouched.' -Level FAIL
        Write-Log 'The web application is still being configured so the health report can say exactly what is left.' -Level INFO
        $databaseFailed = $true
    }
} else {
    Write-Log 'Database step skipped as requested.' -Level INFO
}

# ----------------------------------------------------------------- firewall

Write-Log '' -Level INFO
Write-Log '--- Firewall ---' -Level INFO

try {
    & (Join-Path $PSScriptRoot 'Set-Firewall.ps1') -Port $Port
} catch {
    Write-Log "The firewall rule could not be created: $($_.Exception.Message)" -Level WARN
    Write-Log 'PharmaVerify will work on this PC, but handhelds will not reach it until the port is open.' -Level WARN
}

# ------------------------------------------------------------ caches, warmed

Write-Log '' -Level INFO
Write-Log '--- Preparing the application ---' -Level INFO

$php = Join-Path $InstallRoot 'runtime\php\php.exe'
if (-not (Test-Path $php)) {
    # The `?.` null-conditional operator is PowerShell 7+ only; the installer
    # runs this under powershell.exe (Windows PowerShell 5.1), where it is a
    # parse error. Written out as an explicit check instead.
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $php = $cmd.Source }
}

if ($php) {
    Push-Location (Join-Path $InstallRoot 'backend')
    try {
        # Cached so the first request of the morning is not the one that pays
        # for parsing every configuration and route file.
        & $php artisan config:cache *> $null
        & $php artisan route:cache  *> $null
        & $php artisan view:cache   *> $null
        Write-Log 'Application caches built.' -Level OK
    } catch {
        Write-Log 'Could not build the caches. The application still runs, a little slower.' -Level WARN
    } finally { Pop-Location }
}

# -------------------------------------------------------------- health check

Write-Log '' -Level INFO
Write-Log '--- Checking the installation ---' -Level INFO

# Given a moment to come up. IIS starts the pool on the first request, and
# checking instantly reports a healthy site as down.
Start-Sleep -Seconds 3

& (Join-Path $PSScriptRoot 'Test-Health.ps1') -InstallRoot $InstallRoot -Port $Port
$healthy = ($LASTEXITCODE -eq 0) -and (-not $databaseFailed)

Write-Log '' -Level INFO
Write-Log '======================================================' -Level INFO

if ($healthy) {
    Write-Log 'PharmaVerify is installed and running.' -Level OK
    Write-Log "Open it on this PC at: $localEndpoint" -Level OK
    if ($lanEndpoint) {
        Write-Log "Handheld terminals use: $lanEndpoint" -Level INFO
    } else {
        Write-Log 'No LAN address was found; handheld terminals have nothing to connect to yet.' -Level WARN
    }
} else {
    # Deliberately not called a failure. The files are in place and the database
    # is set up; something needs attention, and the health report above names
    # it. Telling an administrator the installation failed would send them to
    # start again, undoing work that is fine.
    Write-Log 'Installed, but some checks did not pass — see the report above.' -Level WARN
    Write-Log "Full log: $($script:LogFile)" -Level INFO
}

Write-Log '======================================================' -Level INFO

# Handed back to the installer so it can show the right final screen.
[pscustomobject]@{
    LocalEndpoint = $localEndpoint
    LanEndpoint   = $lanEndpoint
    Healthy       = $healthy
    LogFile       = $script:LogFile
} | ConvertTo-Json -Compress | Write-Output

exit $(if ($healthy) { 0 } else { 10 })

# End of the try body opened above the installation banner.
} catch {
    Write-Log ("Installation stopped unexpectedly: " + $_.Exception.GetType().FullName + ': ' + $_.Exception.Message) -Level FAIL
    Write-Log $_.ScriptStackTrace -Level INFO
    exit 9
}
