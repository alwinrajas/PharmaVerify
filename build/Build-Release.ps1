<#
.SYNOPSIS
    Builds a PharmaVerify release payload for client installation.

.DESCRIPTION
    Produces the tree an installer packages, from a clean checkout, the same way
    every time. The client machine gets no Node, no Composer and no build step —
    everything that needs a toolchain happens here.

    Gates before packaging, because a release that fails its own tests is worth
    less than no release: an installer that reaches a pharmacy is expensive to
    take back.

    Output:
        dist/PharmaVerify-<version>/
            app/        compiled web application, served from the backend
            backend/    Laravel with production dependencies only
            VERSION     what this payload is

.PARAMETER Version
    Semantic version for this release, e.g. 1.0.0.

.PARAMETER SkipTests
    Skips the test gates. For iterating on packaging only — never for a build
    that will be handed to anyone.

.EXAMPLE
    .\Build-Release.ps1 -Version 1.0.0
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version,

    [switch] $SkipTests
)

$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend  = Join-Path $RepoRoot 'backend'
$Frontend = Join-Path $RepoRoot 'frontend'
$DistRoot = Join-Path $RepoRoot 'dist'
# Fixed name, because PharmaVerify.iss refers to it. Versioning lives in the
# installer file name, not in the staging path.
$Payload  = Join-Path $DistRoot 'payload'
$Installer = Join-Path $RepoRoot 'installer'

function Write-Step { param([string] $Text) Write-Host "`n=== $Text" -ForegroundColor Cyan }
function Write-Ok   { param([string] $Text) Write-Host "  [ok] $Text" -ForegroundColor Green }
function Fail       { param([string] $Text) Write-Host "  [fail] $Text" -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------- toolchain

Write-Step "Checking the build machine"

foreach ($tool in @('php', 'composer', 'node', 'npm')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        Fail "$tool is not on PATH. It is needed to build a release, though not to run one."
    }
}
Write-Ok "php, composer, node and npm found"

# ------------------------------------------------------------------- gates

if (-not $SkipTests) {
    Write-Step "Backend tests"
    Push-Location $Backend
    try {
        & php artisan test
        if ($LASTEXITCODE -ne 0) { Fail "Backend tests failed. Nothing was packaged." }
    } finally { Pop-Location }
    Write-Ok "backend suite green"

    Write-Step "Front end: types and tests"
    Push-Location $Frontend
    try {
        & npx tsc -b --noEmit
        if ($LASTEXITCODE -ne 0) { Fail "TypeScript check failed." }

        & npx vitest run
        if ($LASTEXITCODE -ne 0) { Fail "Front-end tests failed." }
    } finally { Pop-Location }
    Write-Ok "types clean, front-end suite green"
} else {
    Write-Host "`n  [warn] Tests skipped. Do not ship this payload." -ForegroundColor Yellow
}

# --------------------------------------------------------------- the build

Write-Step "Building the web application"

# npm ci deletes node_modules outright, which fails while a dev server holds a
# handle on it. That surfaces as an EBUSY stack trace naming one arbitrary file,
# which says nothing about the cause — so name it here instead.
$devServers = Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains('vite') -and $_.CommandLine.Contains($Frontend) }

if ($devServers) {
    $pids = ($devServers | ForEach-Object { $_.ProcessId }) -join ', '
    Fail @"
A development server is running and holds the dependency folder open.

Stop it and run this again. Process id(s): $pids

A release is meant to be built from a clean checkout, so this is a guard rather
than something to work around.
"@
}

Push-Location $Frontend
try {
    & npm ci
    if ($LASTEXITCODE -ne 0) { Fail "npm ci failed. See the npm output above." }

    & npm run build
    if ($LASTEXITCODE -ne 0) { Fail "Front-end build failed." }
} finally { Pop-Location }

$BuiltIndex = Join-Path $Frontend 'dist/index.html'
if (-not (Test-Path $BuiltIndex)) { Fail "The build produced no index.html." }
Write-Ok "web application compiled"

Write-Step "Installing production dependencies"
Push-Location $Backend
try {
    # --no-dev matters: dev dependencies carry test fixtures and tooling that
    # have no business on a pharmacy PC.
    & composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) { Fail "composer install failed." }
} finally { Pop-Location }
Write-Ok "vendor tree built without dev dependencies"

# ------------------------------------------------------------- the payload

Write-Step "Assembling the payload"

if (Test-Path $Payload) { Remove-Item $Payload -Recurse -Force }
New-Item -ItemType Directory -Path $Payload -Force | Out-Null

# The backend, minus everything that is ours to build rather than to ship.
$excluded = @(
    '.env',                 # generated per installation, never packaged
    'storage\logs',
    'storage\framework\cache',
    'storage\framework\sessions',
    'storage\framework\views',
    'tests',
    '.phpunit.result.cache'
)

$BackendOut = Join-Path $Payload 'backend'
robocopy $Backend $BackendOut /E /NFL /NDL /NJH /NJS /NP `
    /XD 'tests' 'node_modules' '.git' `
    /XF '.env' '.phpunit.result.cache' | Out-Null

if ($LASTEXITCODE -ge 8) { Fail "Copying the backend failed." }

# Writable trees are created empty. Their contents belong to an installation,
# not to a release, and shipping one machine's logs to another is a leak.
foreach ($dir in @('storage\logs', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'bootstrap\cache')) {
    $target = Join-Path $BackendOut $dir
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    New-Item -ItemType Directory -Path $target -Force | Out-Null
}

# The compiled application, served from the same origin as the API.
$AppOut = Join-Path $Payload 'app'
robocopy (Join-Path $Frontend 'dist') $AppOut /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Fail "Copying the web application failed." }

Write-Ok "payload assembled at $Payload"

# ------------------------------------------------------------ verification

Write-Step "Verifying the payload"

$mustExist = @(
    'backend\artisan',
    'backend\public\index.php',
    'backend\vendor\autoload.php',
    'app\index.html'
)
foreach ($rel in $mustExist) {
    if (-not (Test-Path (Join-Path $Payload $rel))) { Fail "Missing from payload: $rel" }
}

# A packaged .env would carry one machine's database password to every client.
if (Test-Path (Join-Path $Payload 'backend\.env')) {
    Fail "A .env file reached the payload. It must be generated per installation."
}

# Dev dependencies are the usual route by which test fixtures ship.
if (Test-Path (Join-Path $Payload 'backend\vendor\phpunit')) {
    Fail "Dev dependencies are present. Re-run composer install with --no-dev."
}

Write-Ok "structure, secrets and dependencies verified"

@"
PharmaVerify $Version
Built $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
"@ | Set-Content -Path (Join-Path $Payload 'VERSION') -Encoding UTF8

# ------------------------------------------------------------- the installer

Write-Step "Restoring the development checkout"

# The payload needed a production vendor tree, and the only place composer
# builds one is in the backend itself - which leaves the CHECKOUT stripped of
# its test tooling after every release. That is exactly how a dev machine ends
# up with "Command 'test' is not defined" an hour after a successful build.
# The payload has its copy by now, so the checkout gets its dev tree back.
Push-Location $Backend
try {
    & composer install --no-interaction | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail "Could not restore dev dependencies to the checkout." }
} finally { Pop-Location }
Write-Ok "checkout restored to a development state"

Write-Step "Compiling the installer"

$iscc = @(
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $iscc) {
    Fail "Inno Setup 6 was not found. Install it, or run winget install JRSoftware.InnoSetup"
}

# The runtime is not in source control - it is fetched by Get-PhpRuntime.ps1 -
# so its absence is a setup mistake rather than a code fault, and the message
# says which command fixes it.
$phpRuntime = Join-Path $Installer 'runtime\php\php.exe'
if (-not (Test-Path $phpRuntime)) {
    Fail @"
The PHP runtime has not been staged, so the installer would ship without one.

Run this first:
    installer\scripts\Get-PhpRuntime.ps1

It also needs the SQL Server driver added by hand; that script explains where.
"@
}

# Not fatal, unlike the PHP runtime check above: Install-WebServer.ps1 falls
# back to downloading the URL Rewrite component itself when it is not
# bundled, so a build without it still works on a client with internet
# access. It just would not work offline, which is worth a warning here
# rather than a surprise on a pharmacy PC with no connection.
$rewriteMsi = Join-Path $Installer 'runtime\redist\rewrite_amd64_en-US.msi'
if (-not (Test-Path $rewriteMsi)) {
    Write-Host "`n  [warn] The URL Rewrite component is not staged. Offline installations will need internet access to fetch it." -ForegroundColor Yellow
    Write-Host "  [warn] Staging it is one command: installer\scripts\Get-Redistributables.ps1`n" -ForegroundColor Yellow
}

$iss = Join-Path $Installer 'PharmaVerify.iss'
& $iscc "/DAppVersion=$Version" $iss

if ($LASTEXITCODE -ne 0) { Fail "The installer did not compile." }

$artifact = Join-Path $DistRoot "PharmaVerify-Setup-v$Version.exe"
if (-not (Test-Path $artifact)) { Fail "The compiler reported success but produced no installer." }

$artifactMb = [math]::Round((Get-Item $artifact).Length / 1MB, 1)
Write-Ok "installer compiled: $artifactMb MB"

$size = [math]::Round((Get-ChildItem $Payload -Recurse -File | Measure-Object Length -Sum).Sum / 1MB, 1)

Write-Host "`n  PharmaVerify $Version" -ForegroundColor Green
Write-Host "  Installer: $artifact"
Write-Host "  Payload:   $Payload ($size MB)`n"
