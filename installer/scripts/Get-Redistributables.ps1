<#
.SYNOPSIS
    Stages the IIS URL Rewrite installer that ships inside the installer.

.DESCRIPTION
    Run on the BUILD machine, not on a client. Downloads Microsoft's official
    URL Rewrite MSI and stages it at installer/runtime/php's sibling,
    installer/runtime/redist, so PharmaVerify.iss can bundle it.

    Why bundling exists: pharmacy PCs are often offline, or sit behind a
    proxy nobody remembers the settings for. Install-WebServer.ps1 needs the
    URL Rewrite module before it can write a web.config that uses it (see the
    EVIDENCE comment there for what happens when it does not have it); at
    install time the bundled copy is preferred and a direct download from
    download.microsoft.com is only a fallback for a machine that does have
    internet access and an installer that was not built with this staged.

.PARAMETER Destination
    Where to stage the MSI. Defaults to installer/runtime/redist.

.PARAMETER Force
    Replaces an already-staged copy.

.EXAMPLE
    .\Get-Redistributables.ps1
#>

[CmdletBinding()]
param(
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
    $Destination = Join-Path (Split-Path -Parent $PSScriptRoot) 'runtime\redist'
}

$msiName = 'rewrite_amd64_en-US.msi'
$msiPath = Join-Path $Destination $msiName

if ((Test-Path $msiPath) -and -not $Force) {
    Write-Log "The URL Rewrite installer is already staged at $msiPath. Use -Force to replace it." -Level OK
    exit 0
}

# Microsoft's own, stable download link for the current URL Rewrite release -
# the same one Install-WebServer.ps1 falls back to on a client machine that
# was not given a bundled copy.
$downloadUrl = 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'

Write-Log "Downloading the URL Rewrite installer." -Level INFO
Write-Log $downloadUrl -Level INFO

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
}

try {
    Invoke-WebRequest -Uri $downloadUrl -OutFile $msiPath -UseBasicParsing -ErrorAction Stop
} catch {
    Write-Log "Could not download the URL Rewrite installer: $($_.Exception.Message)" -Level FAIL
    exit 1
}

# A smaller file is an error page (a proxy login prompt, a 404 page, a
# throttling notice), not the MSI - the download succeeding at the HTTP level
# says nothing about what actually landed on disk. The real installer is
# several megabytes; 4MB is comfortably below that and well above anything an
# error page would ever be.
$sizeMb = [math]::Round((Get-Item $msiPath).Length / 1MB, 2)
if ($sizeMb -lt 4) {
    Write-Log "The downloaded file is only $sizeMb MB - too small to be the real installer. Removing it." -Level FAIL
    Remove-Item $msiPath -Force -ErrorAction SilentlyContinue
    exit 2
}

Write-Log "Staged the URL Rewrite installer at $msiPath ($sizeMb MB)." -Level OK
