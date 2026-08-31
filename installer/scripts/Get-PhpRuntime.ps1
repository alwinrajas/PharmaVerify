<#
.SYNOPSIS
    Stages the PHP runtime that ships inside the installer.

.DESCRIPTION
    Run on the BUILD machine, not on a client. Downloads the official Windows
    build of PHP, unpacks it into installer/runtime/php and writes a php.ini
    configured for PharmaVerify. The installer then carries it, so the pharmacy
    never installs PHP.

    Non-thread-safe on purpose. IIS talks to PHP over FastCGI, which runs one
    request per process and needs no thread safety; the thread-safe build is for
    in-process module SAPIs such as Apache's mod_php. Using the wrong one here
    is slower and unsupported.

    The SQL Server driver is not part of PHP and is fetched separately from
    Microsoft's own release. Without it the application cannot reach the
    database at all.

    The PHP version is chosen to match the driver, not the other way round.
    Driver 5.13.3 dropped PHP 8.2, so bundling 8.2 would produce a runtime that
    cannot talk to SQL Server. The application requires ^8.2, so 8.3 satisfies
    it and is supported by the current driver.

.PARAMETER Version
    PHP version to stage. Must satisfy the application's ^8.2 requirement.

.PARAMETER Destination
    Where to unpack it. Defaults to installer/runtime/php.

.PARAMETER Force
    Replaces an already-staged runtime.

.EXAMPLE
    .\Get-PhpRuntime.ps1 -Version 8.2.29
#>

[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version = '8.3.33',

    # Microsoft's SQL Server driver release to pair with it. Its supported PHP
    # versions change between releases, which is what decides the PHP version
    # above rather than the other way round.
    [string] $DriverVersion = '5.13.3',

    # Resolved in the body, not here: $PSScriptRoot is not dependably set
    # inside a param default, and the failure is an obscure empty-string
    # error rather than anything that names the cause.
    [string] $Destination,

    [switch] $Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

if (-not $Destination) {
    $Destination = Join-Path (Split-Path -Parent $PSScriptRoot) 'runtime\php'
}

if ((Test-Path (Join-Path $Destination 'php.exe')) -and -not $Force) {
    Write-Log "A PHP runtime is already staged at $Destination. Use -Force to replace it." -Level OK
    exit 0
}

# vs16 covers PHP 8.2; later versions moved to vs17. Getting this wrong yields
# a build that will not start for want of the matching C++ runtime.
$vs = if ([version]$Version -ge [version]'8.4.0') { 'vs17' } else { 'vs16' }
$zipName = "php-$Version-nts-Win32-$vs-x64.zip"
$archive = Join-Path $env:TEMP $zipName

# php.net keeps only the current patch of each branch in the releases folder and
# moves the rest to archives. Which of the two a given version lives in changes
# over time and without notice, so both are tried rather than making whoever
# runs this work out where it went.
$sources = @(
    "https://windows.php.net/downloads/releases/$zipName",
    "https://windows.php.net/downloads/releases/archives/$zipName"
)

Write-Log "Downloading PHP $Version (non-thread-safe, x64)." -Level INFO

$downloaded = $false
foreach ($url in $sources) {
    Write-Log $url -Level INFO
    try {
        Invoke-WebRequest -Uri $url -OutFile $archive -UseBasicParsing -ErrorAction Stop
        $downloaded = $true
        break
    } catch {
        Write-Log "Not there: $($_.Exception.Message)" -Level INFO
    }
}

if (-not $downloaded) {
    Write-Log "PHP $Version could not be downloaded from either location." -Level FAIL
    Write-Log 'Check the version exists at https://windows.php.net/downloads/releases/ and pass -Version.' -Level INFO
    exit 1
}

Write-Log "Downloaded $([math]::Round((Get-Item $archive).Length / 1MB, 1)) MB." -Level OK

if (Test-Path $Destination) { Remove-Item $Destination -Recurse -Force }
New-Item -ItemType Directory -Path $Destination -Force | Out-Null

Expand-Archive -Path $archive -DestinationPath $Destination -Force
Remove-Item $archive -Force

if (-not (Test-Path (Join-Path $Destination 'php.exe'))) {
    Write-Log 'The archive did not contain php.exe.' -Level FAIL
    exit 1
}

# ------------------------------------------------------------- the driver

# The DLL names carry the PHP version with no dot: 8.3 becomes 83.
$parts = $Version.Split('.')
$abi = "$($parts[0])$($parts[1])"

$extDir = Join-Path $Destination 'ext'
$driverZip = Join-Path $env:TEMP "msphpsql-$DriverVersion.zip"
$driverUrl = "https://github.com/microsoft/msphpsql/releases/download/v$DriverVersion/Windows_${DriverVersion}RTW.zip"

Write-Log "Downloading the SQL Server driver $DriverVersion." -Level INFO

try {
    Invoke-WebRequest -Uri $driverUrl -OutFile $driverZip -UseBasicParsing -ErrorAction Stop
} catch {
    Write-Log "Could not download the SQL Server driver: $($_.Exception.Message)" -Level FAIL
    Write-Log "Check the release exists at https://github.com/microsoft/msphpsql/releases" -Level INFO
    exit 2
}

$wanted = @("php_sqlsrv_${abi}_nts_x64.dll", "php_pdo_sqlsrv_${abi}_nts_x64.dll")
$staging = Join-Path $env:TEMP "msphpsql-$DriverVersion"

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
Expand-Archive -Path $driverZip -DestinationPath $staging -Force

$found = 0
foreach ($name in $wanted) {
    $dll = Get-ChildItem $staging -Recurse -Filter $name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($dll) {
        Copy-Item $dll.FullName (Join-Path $extDir $name) -Force
        $found++
    }
}

Remove-Item $driverZip -Force -ErrorAction SilentlyContinue
Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue

if ($found -ne $wanted.Count) {
    # Almost always a version mismatch: the driver release does not carry a
    # build for this PHP. Said plainly, because the alternative is a runtime
    # that starts perfectly and cannot reach the database.
    Write-Log "Driver $DriverVersion has no build for PHP $Version (looked for $($wanted -join ', '))." -Level FAIL
    Write-Log 'Either choose a PHP version the driver supports, or a driver release that supports this PHP.' -Level INFO
    exit 2
}

Write-Log "SQL Server driver staged for PHP $Version." -Level OK

# ---------------------------------------------------------------- php.ini

# Written here rather than shipped as a static file so extension_dir always
# matches where the runtime actually landed.
$ini = @"
; PharmaVerify — PHP configuration.
; Generated by Get-PhpRuntime.ps1. Edit the generator, not this file: it is
; replaced whenever the runtime is re-staged.

extension_dir = "ext"

; Required by the application.
extension=mbstring
extension=openssl
extension=fileinfo
extension=curl
extension=zip
extension=gd

; SQL Server. Added by this script from Microsoft's release; the file names
; carry the PHP version, so they change when the runtime does.
extension=php_sqlsrv_$($abi)_nts_x64.dll
extension=php_pdo_sqlsrv_$($abi)_nts_x64.dll

; MySQL, for sites that use it instead.
extension=pdo_mysql

; Stock imports are spreadsheets of a few thousand rows; the defaults are too
; small for them and the failure looks like a hung upload.
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300

; Errors are logged, never shown. A stack trace on screen tells whoever is
; standing at the PC the database credentials and the file layout.
display_errors = Off
log_errors = On
error_log = "$($script:DataRoot)\logs\php-error.log"

date.timezone = UTC

; Compiles once and keeps it, which matters on a back-office PC that is not fast.
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.validate_timestamps = 0
"@

$iniPath = Join-Path $Destination 'php.ini'
[System.IO.File]::WriteAllText($iniPath, $ini, [System.Text.UTF8Encoding]::new($false))

Write-Log "Staged PHP $Version at $Destination." -Level OK

# ------------------------------------------------------------------- proof

# -c and not -n: -n means "ignore php.ini" and would silently defeat the
# very thing being checked, reporting a good runtime as broken.
# The runtime is asked to load its own configuration and report the drivers it
# ended up with. A DLL present on disk that fails to load — wrong architecture,
# wrong thread-safety, missing C++ runtime — looks identical to a working one
# until the application tries to reach the database.
$loaded = & (Join-Path $Destination 'php.exe') -c (Join-Path $Destination 'php.ini') -r 'echo implode(",", array_filter(get_loaded_extensions(), fn($e) => str_contains(strtolower($e), "sqlsrv")));' 2>&1 | Out-String

if ($loaded -match 'sqlsrv') {
    Write-Log "Runtime verified: $($loaded.Trim()) loaded." -Level OK
} else {
    Write-Log 'The SQL Server driver is on disk but did not load.' -Level FAIL
    Write-Log 'Usually the Visual C++ Redistributable is missing on this build machine.' -Level INFO
    exit 3
}

Write-Log "PHP $Version staged and ready to package." -Level OK
