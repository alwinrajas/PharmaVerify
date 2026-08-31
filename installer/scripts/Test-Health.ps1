<#
.SYNOPSIS
    Checks whether PharmaVerify is actually working.

.DESCRIPTION
    Run at the end of installation, and afterwards from the Start menu whenever
    someone asks "is it broken?".

    Each check answers a question an administrator would otherwise answer by
    guesswork, and the checks are ordered so the first failure is usually the
    cause rather than a symptom: a database that is down makes the application
    look broken too, so the database is checked first and reported plainly.

    Exit code 0 when everything essential passes, 1 otherwise, so the installer
    can decide between "Installation Complete" and "completed with attention
    required" without parsing this output.

.PARAMETER InstallRoot
    Where PharmaVerify was installed. Defaults to the standard location.

.PARAMETER Port
    The port the web application listens on.

.PARAMETER Quiet
    Suppresses the report; sets only the exit code. For scripted checks.

.EXAMPLE
    .\Test-Health.ps1
#>

[CmdletBinding()]
param(
    [string] $InstallRoot = (Join-Path ${env:ProgramFiles} 'PharmaVerify'),
    [int]    $Port = 8000,
    [switch] $Quiet
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Continue'

. (Join-Path $PSScriptRoot 'Common.ps1')

$results = [System.Collections.Generic.List[object]]::new()

function Add-Check {
    param(
        [Parameter(Mandatory)] [string] $Name,
        [Parameter(Mandatory)] [bool]   $Passed,
        [string] $Detail = '',
        # Something that is worth reporting but does not mean the system is
        # unusable — an optional component, or a warning about how it is set up.
        [switch] $Advisory
    )

    $results.Add([pscustomobject]@{
        Name     = $Name
        Passed   = $Passed
        Detail   = $Detail
        Advisory = [bool] $Advisory
    })
}

# ------------------------------------------------------------ application files

$backend = Join-Path $InstallRoot 'backend'
$envFile = Join-Path $script:DataRoot 'config\.env'

Add-Check 'Application files' (Test-Path (Join-Path $backend 'artisan')) $backend
Add-Check 'Web application'   (Test-Path (Join-Path $backend 'public\index.html')) 'Compiled front end in public/'
Add-Check 'Configuration'     (Test-Path $envFile) $envFile

# ---------------------------------------------------------------------- runtime

$php = Join-Path $InstallRoot 'runtime\php\php.exe'
if (-not (Test-Path $php)) {
    # `?.` is a PowerShell 7+ operator and a parse error under Windows
    # PowerShell 5.1, which is what this script runs under (invoked via
    # powershell.exe). Written out as an explicit check instead.
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $php = $cmd.Source }
}

if ($php) {
    $version = (& $php -r 'echo PHP_VERSION;' 2>$null)
    Add-Check 'Runtime' ([bool] $version) "PHP $version"
} else {
    Add-Check 'Runtime' $false 'No PHP runtime found'
}

# --------------------------------------------------------------------- database

# Asked of the application rather than of the database directly, so the answer
# reflects the credentials PharmaVerify actually uses. A database that is up but
# refuses these credentials is down as far as the pharmacy is concerned.
if ($php -and (Test-Path (Join-Path $backend 'artisan'))) {
    Push-Location $backend
    try {
        # Opening the connection, and nothing more. `db:show` was the obvious
        # choice and is the wrong one: it queries performance_schema, which is
        # absent on some MySQL and MariaDB builds, so it reports a perfectly
        # healthy database as unreachable. A health tool that cries wolf gets
        # ignored, and then it is worse than having none.
        # The probe is written to a file and run as one, never passed inline
        # with -r: Windows PowerShell 5.1 re-quotes multi-line native-command
        # arguments differently from PowerShell 7, and the mangled source
        # reached PHP as a parse error - reported as a database failure on a
        # database that was fine.
        $probeFile = Join-Path $env:TEMP 'pharmaverify-db-probe.php'
        [System.IO.File]::WriteAllText($probeFile, @'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "OK:" . Illuminate\Support\Facades\DB::connection()->getDatabaseName();
} catch (Throwable $e) {
    echo "ERR:" . $e->getMessage();
}
'@, [System.Text.UTF8Encoding]::new($false))
        try {
            $probe = & $php $probeFile 2>&1 | Out-String
        } finally {
            Remove-Item $probeFile -Force -ErrorAction SilentlyContinue
        }

        $connected = $probe -match '^OK:'
        $detail = if ($connected) {
            "Connected to '$($probe -replace '^OK:', '' -replace '\s+$', '')'"
        } else {
            # The driver's own words. They name the actual cause — refused,
            # unknown database, access denied — which is what the administrator
            # needs, and no generic message can supply.
            ($probe -replace '^ERR:', '' -replace '\s+', ' ').Trim() -replace '^(.{140}).*$', '$1...'
        }
        Add-Check 'Database' $connected $detail

        if ($connected) {
            # Pending migrations mean the schema is older than the code. The
            # application starts anyway and then fails on whichever screen
            # touches the missing column, which is a far worse way to find out.
            & $php artisan migrate:status *> $null
            Add-Check 'Schema' ($LASTEXITCODE -eq 0) 'Migrations applied'
        }
    } finally { Pop-Location }
} else {
    Add-Check 'Database' $false 'Cannot check without a runtime and application files'
}

# ---------------------------------------------------------------------- network

$lan = Get-LanAddress
Add-Check 'Network' ([bool] $lan) $(if ($lan) { "$($lan.AdapterName) - $($lan.IPAddress) ($($lan.NetworkProfile))" } else { 'No active network adapter' })

# The hosts entry is loopback now (127.0.0.1), not the LAN IP, so this checks
# what actually matters on this PC: does the name resolve at all, and does it
# resolve to the canonical address. GetHostAddresses is used rather than
# Resolve-DnsName because it goes through the same resolver path an
# application uses, hosts file included.
try {
    $hostnameResolved = [System.Net.Dns]::GetHostAddresses('pharmaverify.local')
    $loopback = $hostnameResolved | Where-Object { $_.IPAddressToString -eq '127.0.0.1' } | Select-Object -First 1
    if ($loopback) {
        Add-Check 'Local hostname' $true 'resolves to 127.0.0.1 on this PC'
    } elseif ($hostnameResolved) {
        $resolvedIp = ($hostnameResolved | Select-Object -First 1).IPAddressToString
        # A plain hyphen, not an em dash - see the note above the "Reachable on
        # the network" detail for why a double-quoted string cannot risk one.
        Add-Check 'Local hostname' $true "resolves to $resolvedIp - works, but the canonical entry is 127.0.0.1; rerun Set-Hostname elevated to correct it"
    } else {
        Add-Check 'Local hostname' $false 'does not resolve; run Set-Hostname.ps1 as administrator, or browse by IP address'
    }
} catch {
    Add-Check 'Local hostname' $false 'does not resolve; run Set-Hostname.ps1 as administrator, or browse by IP address'
}

if ($lan -and $lan.NetworkProfile -eq 'Public') {
    # The firewall rule is scoped to Private. On a Public profile the rule does
    # not apply and no handheld can connect, while everything on the PC itself
    # keeps working — which is exactly the kind of fault nobody finds quickly.
    Add-Check 'Network profile' $false 'Network is set to Public; handhelds will be blocked. Set it to Private.' -Advisory
}

Add-Check 'Port listening' (Test-PortListening -Port $Port) "TCP $Port"

# A plain hyphen, not an em dash: this string is double-quoted in a .ps1 file
# saved without a BOM, and Windows PowerShell 5.1 reads such a file through
# the system's ANSI code page when there is no BOM to say otherwise. An em
# dash's UTF-8 bytes misread that way can decode to a smart quote character,
# which PowerShell's tokenizer treats as a string terminator - silently
# truncating the string and breaking the parse.
$reachableDetail = if ($lan) { "Listening on all interfaces - handhelds use http://$($lan.IPAddress):$Port" } else { 'Listening on all interfaces, not only this PC' }
Add-Check 'Reachable on the network' (Test-BoundToAllInterfaces -Port $Port) $reachableDetail

$rule = Get-NetFirewallRule -DisplayName 'PharmaVerify*' -ErrorAction SilentlyContinue | Select-Object -First 1
Add-Check 'Firewall rule' ([bool] $rule) $(if ($rule) { "$($rule.DisplayName) [$($rule.Enabled)]" } else { 'No PharmaVerify rule found' })

# ---------------------------------------------------------------------- service

$service = Get-Service -Name 'PharmaVerify' -ErrorAction SilentlyContinue
if ($service) {
    Add-Check 'Windows service' ($service.Status -eq 'Running') "PharmaVerify is $($service.Status)"
} else {
    $iis = Get-Service -Name 'W3SVC' -ErrorAction SilentlyContinue
    if ($iis) {
        Add-Check 'Web server' ($iis.Status -eq 'Running') "IIS is $($iis.Status)"
    } else {
        Add-Check 'Windows service' $false 'Neither a PharmaVerify service nor IIS was found'
    }
}

# ------------------------------------------------------------ address routing

# EVIDENCE: without the IIS URL Rewrite module, Install-WebServer.ps1 writes
# web.config without a <rewrite> section, so /up below can pass perfectly
# while every route that needs it - page refreshes, the handheld API - 404s.
# Checked only when IIS is actually the web server in use: a development
# machine running `artisan serve` has no URL Rewrite concept at all, and
# flagging its absence there would be a false alarm about a component it
# will never need.
$w3svc = Get-Service -Name 'W3SVC' -ErrorAction SilentlyContinue
if ($w3svc) {
    $rewritePresent = Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite'
    if ($rewritePresent) {
        Add-Check 'Address routing' $true 'URL Rewrite component present'
    } else {
        Add-Check 'Address routing' $false 'the URL Rewrite component is missing - page refreshes and the handheld API will not work; run Install-WebServer.ps1 as administrator, it installs the component automatically'
    }
}

# ------------------------------------------------------------------- responding

# The last check, and the only one that proves the whole chain: runtime,
# configuration, database and web server all have to work for this to answer.
try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/up" -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
    Add-Check 'Application responding' ($response.StatusCode -eq 200) "HTTP $($response.StatusCode) from /up"
} catch {
    Add-Check 'Application responding' $false 'No response from the health endpoint'
}

# A 200 from the health endpoint above proves the stack runs; it does not
# prove the client will see PharmaVerify when they open the site — a fresh
# deployment where the built front end was not copied into backend\public
# serves IIS's or Laravel's own placeholder page and answers /up perfectly
# well regardless. Only fetching the real front page catches that.
try {
    $appResponse = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
    $body = $appResponse.Content
    # data-pv-placeholder is our own placeholder page; the Laravel markers
    # cover installations from builds that predate it.
    # The placeholder markers are tested FIRST, and the title is no marker at
    # all: the placeholder titles itself from APP_NAME, so '<title>PharmaVerify'
    # appears on both pages and once made this check pass against the very page
    # it exists to catch. Only id="root" - the mount point the application is
    # rendered into - separates the two reliably.
    if ($body -match 'data-pv-placeholder' -or $body -match 'Laravel' -or $body -match "Let's get started") {
        Add-Check 'Serving the application' $false 'the placeholder page is being served, not PharmaVerify - the web application build is missing from backend\public'
    } elseif ($appResponse.StatusCode -eq 200 -and $body -match 'id="root"') {
        Add-Check 'Serving the application' $true 'PharmaVerify responded at /'
    } else {
        Add-Check 'Serving the application' $false 'responded, but not with the PharmaVerify application'
    }
} catch {
    Add-Check 'Serving the application' $false 'No response from the web application'
}

# ----------------------------------------------------------------------- report

# @() throughout: under StrictMode, Windows PowerShell 5.1 throws on .Count
# when a pipeline unwraps to a single object — so a report with exactly one
# failing check would crash the very tool meant to explain it.
$essential = @($results | Where-Object { -not $_.Advisory })
$failed    = @($essential | Where-Object { -not $_.Passed })
$advisory  = @($results | Where-Object { $_.Advisory -and -not $_.Passed })

if (-not $Quiet) {
    Write-Host "`n  PharmaVerify Health`n" -ForegroundColor Cyan

    foreach ($check in $results) {
        $mark   = if ($check.Passed) { 'OK  ' } elseif ($check.Advisory) { 'NOTE' } else { 'FAIL' }
        $colour = if ($check.Passed) { 'Green' } elseif ($check.Advisory) { 'Yellow' } else { 'Red' }

        Write-Host ("  {0,-4} {1,-26} {2}" -f $mark, $check.Name, $check.Detail) -ForegroundColor $colour
    }

    Write-Host ''

    if ($failed.Count -gt 0) {
        Write-Host "  Attention required: $($failed.Count) check(s) failed.`n" -ForegroundColor Red
    } elseif ($advisory.Count -gt 0) {
        Write-Host "  Working, with notes above.`n" -ForegroundColor Yellow
    } else {
        Write-Host "  Everything is working.`n" -ForegroundColor Green
    }
}

exit $(if ($failed.Count -gt 0) { 1 } else { 0 })
