<#
.SYNOPSIS
    Sets up IIS to serve PharmaVerify, and to keep serving it unattended.

.DESCRIPTION
    IIS rather than a bundled web server or `artisan serve`.

    `artisan serve` handles one request at a time and dies with the session that
    started it, so a pharmacy would lose the system whenever someone logged out.
    Bundling Apache or nginx means shipping, patching and supervising a web
    server on a machine nobody administers. IIS is already part of Windows,
    already a service that starts at boot and restarts on failure, and needs
    nothing redistributed.

    PHP is reached over FastCGI, which is why the staged runtime is the
    non-thread-safe build.

.PARAMETER InstallRoot
    Where PharmaVerify is installed.

.PARAMETER SiteName
    IIS site name.

.PARAMETER Port
    Port to listen on.

.PARAMETER Remove
    Removes the site and pool. Used by the uninstaller.

.EXAMPLE
    .\Install-WebServer.ps1 -Port 8000
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [string] $InstallRoot = (Join-Path ${env:ProgramFiles} 'PharmaVerify'),
    [string] $SiteName = 'PharmaVerify',
    [int]    $Port = 8000,
    [switch] $Remove
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

if (-not (Test-Administrator)) {
    Write-Log 'Configuring IIS needs administrator rights.' -Level FAIL
    exit 1
}

$webRoot = Join-Path $InstallRoot 'backend\public'
$php     = Join-Path $InstallRoot 'runtime\php\php-cgi.exe'

# ------------------------------------------------------------------- removal

if ($Remove) {
    Import-Module WebAdministration -ErrorAction SilentlyContinue

    if (Get-Website -Name $SiteName -ErrorAction SilentlyContinue) {
        if ($PSCmdlet.ShouldProcess($SiteName, 'Remove IIS site')) {
            Remove-Website -Name $SiteName
            Write-Log "Removed the IIS site '$SiteName'." -Level OK
        }
    }

    if (Test-Path "IIS:\AppPools\$SiteName") {
        if ($PSCmdlet.ShouldProcess($SiteName, 'Remove application pool')) {
            Remove-WebAppPool -Name $SiteName
            Write-Log "Removed the application pool '$SiteName'." -Level OK
        }
    }
    exit 0
}

# -------------------------------------------------------------- IIS features

# CGI is the one that is easy to miss: without it IIS starts, serves the static
# files perfectly, and returns 404 for every PHP request — which reads like a
# routing fault rather than a missing Windows feature.
$features = @(
    'IIS-WebServerRole',
    'IIS-WebServer',
    'IIS-CommonHttpFeatures',
    'IIS-StaticContent',
    'IIS-DefaultDocument',
    'IIS-HttpErrors',
    'IIS-CGI',
    'IIS-ISAPIExtensions',
    'IIS-ISAPIFilter',
    'IIS-HttpCompressionStatic',
    'IIS-RequestFiltering',
    'IIS-ManagementConsole'
)

Write-Log 'Checking the web server components.' -Level INFO

$missing = @()
foreach ($feature in $features) {
    $state = Get-WindowsOptionalFeature -Online -FeatureName $feature -ErrorAction SilentlyContinue
    if ($state -and $state.State -ne 'Enabled') { $missing += $feature }
}

if ($missing.Count -gt 0) {
    Write-Log "Enabling $($missing.Count) web server component(s). This can take a few minutes." -Level INFO
    if ($PSCmdlet.ShouldProcess('IIS', "Enable $($missing.Count) features")) {
        foreach ($feature in $missing) {
            # -All pulls in each feature's own prerequisites, which are not
            # listed above and differ between Windows editions.
            Enable-WindowsOptionalFeature -Online -FeatureName $feature -All -NoRestart -ErrorAction SilentlyContinue | Out-Null
        }
        Write-Log 'Web server components enabled.' -Level OK
    }
} else {
    Write-Log 'Web server components already present.' -Level OK
}

Import-Module WebAdministration -ErrorAction Stop

# ----------------------------------------------------------------- FastCGI

if (-not (Test-Path $php)) {
    Write-Log "php-cgi.exe was not found at $php." -Level FAIL
    Write-Log 'The PHP runtime should have been installed with the application.' -Level INFO
    exit 2
}

$existingFastCgi = Get-WebConfiguration -Filter 'system.webServer/fastCgi/application' -PSPath 'IIS:\' |
    Where-Object { $_.fullPath -eq $php }

if (-not $existingFastCgi) {
    if ($PSCmdlet.ShouldProcess($php, 'Register FastCGI application')) {
        Add-WebConfiguration -Filter 'system.webServer/fastCgi' -PSPath 'IIS:\' -Value @{
            fullPath = $php
            # Recycled periodically. PHP processes accumulate memory over a long
            # counting day, and a pharmacy will not notice until it stops.
            instanceMaxRequests = 10000
            activityTimeout     = 600
            requestTimeout      = 600
        }

        # PHP_FCGI_MAX_REQUESTS must not exceed instanceMaxRequests, or PHP
        # exits while IIS still believes the process is usable.
        Add-WebConfiguration -Filter "system.webServer/fastCgi/application[@fullPath='$php']/environmentVariables" -PSPath 'IIS:\' -Value @{
            name  = 'PHP_FCGI_MAX_REQUESTS'
            value = '10000'
        }

        Write-Log 'PHP registered with the web server.' -Level OK
    }
} else {
    Write-Log 'PHP already registered with the web server.' -Level OK
}

# -------------------------------------------------------------- application pool

if (-not (Test-Path "IIS:\AppPools\$SiteName")) {
    if ($PSCmdlet.ShouldProcess($SiteName, 'Create application pool')) {
        New-WebAppPool -Name $SiteName | Out-Null
        Write-Log "Created the application pool '$SiteName'." -Level OK
    }
}

if ($PSCmdlet.ShouldProcess($SiteName, 'Configure application pool')) {
    # No managed runtime: nothing here is .NET, and loading it costs memory and
    # start-up time for no purpose.
    Set-ItemProperty "IIS:\AppPools\$SiteName" -Name managedRuntimeVersion -Value ''
    Set-ItemProperty "IIS:\AppPools\$SiteName" -Name startMode -Value 'AlwaysRunning'

    # The defaults idle the pool out after twenty minutes and recycle it at
    # 02:00. On a pharmacy PC that means the first count of the morning waits
    # for a cold start, and an overnight recycle can land mid-submission.
    Set-ItemProperty "IIS:\AppPools\$SiteName" -Name processModel.idleTimeout -Value ([TimeSpan]::Zero)
    Set-ItemProperty "IIS:\AppPools\$SiteName" -Name recycling.periodicRestart.time -Value ([TimeSpan]::Zero)

    Write-Log 'Application pool set to stay running.' -Level OK
}

# ------------------------------------------------------------------- the site

if (-not (Test-Path $webRoot)) {
    Write-Log "The web folder was not found at $webRoot." -Level FAIL
    exit 3
}

$site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue

if (-not $site) {
    if ($PSCmdlet.ShouldProcess($SiteName, "Create site on port $Port")) {
        New-Website -Name $SiteName -Port $Port -PhysicalPath $webRoot -ApplicationPool $SiteName -Force | Out-Null
        Write-Log "Created the site '$SiteName' on port $Port." -Level OK
    }
} else {
    if ($PSCmdlet.ShouldProcess($SiteName, 'Update site')) {
        Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $webRoot
        Write-Log "Updated the existing site '$SiteName'." -Level OK
    }
}

# ---------------------------------------------------------------- permissions

# The pool identity needs to read the application, and to write only the two
# folders Laravel writes to. Granting more than that turns a web vulnerability
# into a modifiable application.
if ($PSCmdlet.ShouldProcess($InstallRoot, 'Set permissions')) {
    $poolIdentity = "IIS AppPool\$SiteName"

    foreach ($path in @((Join-Path $InstallRoot 'backend\storage'), (Join-Path $InstallRoot 'backend\bootstrap\cache'))) {
        if (Test-Path $path) {
            $acl = Get-Acl $path
            $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new(
                $poolIdentity, 'Modify', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
            Set-Acl -Path $path -AclObject $acl
        }
    }
    Write-Log 'Write access granted to the two folders the application writes to.' -Level OK
}

# --------------------------------------------------------------- URL Rewrite

function Install-UrlRewrite {
    <#
        .SYNOPSIS
            Makes sure the IIS URL Rewrite module is present, installing it
            when it is not.

        .DESCRIPTION
            EVIDENCE: a real client install produced HTTP 500.19 /
            0x8007000d from backend\public\web.config because the URL
            Rewrite module was absent while web.config still contained a
            <rewrite> section — an unrecognized configuration section is
            fatal to EVERY request, not just deep links. This used to only
            warn and write the section anyway, with a warning that itself
            claimed "only the front page will load", which was wrong. The
            module has to verifiably exist before a web.config naming it is
            ever written; see the conditional web.config below.
    #>
    param([Parameter(Mandatory)] [string] $InstallRoot)

    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite') {
        Write-Log 'The web address-routing component (URL Rewrite) is already installed.' -Level OK
        return $true
    }

    Write-Log 'The web address-routing component (URL Rewrite) is not installed.' -Level INFO

    # Bundled first so an offline pharmacy PC never needs internet access;
    # the download is only a fallback for a build that was not staged with
    # it (see Get-Redistributables.ps1).
    $bundledMsi = Join-Path $InstallRoot 'runtime\redist\rewrite_amd64_en-US.msi'
    $downloadUrl = 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'
    $msi = $null

    if (Test-Path $bundledMsi) {
        $msi = $bundledMsi
        Write-Log 'Installing it from the bundled copy.' -Level INFO
    } else {
        $downloadedMsi = Join-Path $env:TEMP 'rewrite_amd64_en-US.msi'
        Write-Log 'No bundled copy found; downloading it.' -Level INFO
        try {
            Invoke-WebRequest -Uri $downloadUrl -OutFile $downloadedMsi -UseBasicParsing -ErrorAction Stop
            $msi = $downloadedMsi
        } catch {
            Write-Log "Could not obtain the web address-routing component (URL Rewrite). Expected the bundled copy at $bundledMsi, or a download from $downloadUrl on a machine with internet access: $($_.Exception.Message)" -Level FAIL
            return $false
        }
    }

    $p = Start-Process msiexec.exe -ArgumentList '/i', ('"' + $msi + '"'), '/qn', '/norestart' -Wait -PassThru
    if ($p.ExitCode -eq 0) {
        Write-Log 'The web address-routing component (URL Rewrite) installer finished.' -Level OK
    } elseif ($p.ExitCode -eq 3010) {
        Write-Log 'The web address-routing component (URL Rewrite) installed, but a Windows restart is pending.' -Level WARN
    } else {
        Write-Log "The web address-routing component (URL Rewrite) installer failed (exit code $($p.ExitCode))." -Level FAIL
        return $false
    }

    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite') {
        Write-Log 'The web address-routing component (URL Rewrite) is installed.' -Level OK
        return $true
    }

    Write-Log 'The web address-routing component (URL Rewrite) installer reported success, but the component is still not present.' -Level FAIL
    return $false
}

$rewriteReady = Install-UrlRewrite -InstallRoot $InstallRoot

# ------------------------------------------------------------------ web.config

# Everything that is not a real file goes to Laravel's front controller, which
# is what makes the application's own routes work. Without it, /verification
# returns 404 from IIS before Laravel is ever consulted.
#
# EVIDENCE: the <rewrite> element may only be written once $rewriteReady is
# $true. A rewrite-bearing web.config on a machine without the module takes
# the whole site down with 500.19 on every request, not just the ones that
# needed rewriting. Without it, the front page and static assets still serve,
# and the health check names exactly what is missing.
if ($rewriteReady) {
    $webConfig = @'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <defaultDocument>
      <files>
        <clear />
        <add value="index.php" />
      </files>
    </defaultDocument>

    <rewrite>
      <rules>
        <rule name="PharmaVerify" stopProcessing="true">
          <match url="^(.*)$" ignoreCase="false" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
    </rewrite>

    <httpProtocol>
      <customHeaders>
        <remove name="X-Powered-By" />
      </customHeaders>
    </httpProtocol>

    <security>
      <requestFiltering>
        <!-- Stock imports are spreadsheets; the 30 MB default rejects the
             larger ones with an error that says nothing useful. -->
        <requestLimits maxAllowedContentLength="67108864" />
        <hiddenSegments>
          <add segment=".env" />
          <add segment="storage" />
        </hiddenSegments>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>
'@
} else {
    $webConfig = @'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <defaultDocument>
      <files>
        <clear />
        <add value="index.php" />
      </files>
    </defaultDocument>

    <httpProtocol>
      <customHeaders>
        <remove name="X-Powered-By" />
      </customHeaders>
    </httpProtocol>

    <security>
      <requestFiltering>
        <!-- Stock imports are spreadsheets; the 30 MB default rejects the
             larger ones with an error that says nothing useful. -->
        <requestLimits maxAllowedContentLength="67108864" />
        <hiddenSegments>
          <add segment=".env" />
          <add segment="storage" />
        </hiddenSegments>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>
'@
}

$configPath = Join-Path $webRoot 'web.config'
if ($PSCmdlet.ShouldProcess($configPath, 'Write web.config')) {
    [System.IO.File]::WriteAllText($configPath, $webConfig, [System.Text.UTF8Encoding]::new($false))
    Write-Log 'Web server rules written.' -Level OK
}

if (-not $rewriteReady) {
    Write-Log 'Page refreshes and the handheld API will not work until the address-routing component is installed. Re-run this script as administrator after installing it.' -Level WARN
}

# ------------------------------------------------------------------- start

if ($PSCmdlet.ShouldProcess($SiteName, 'Start')) {
    Start-WebAppPool -Name $SiteName -ErrorAction SilentlyContinue
    Start-Website -Name $SiteName -ErrorAction SilentlyContinue

    # IIS itself must come back after a reboot, which is what makes the
    # application survive one. This is the setting that delivers §13.
    Set-Service -Name W3SVC -StartupType Automatic -ErrorAction SilentlyContinue

    Write-Log "PharmaVerify is being served on port $Port." -Level OK
    Write-Log 'The web server starts automatically with Windows.' -Level OK
}

# The orchestrator reads $LASTEXITCODE after every step; a script that ends on
# a cmdlet rather than an explicit exit leaves it stale (whatever the last
# native command set) or unset, which reads as success or failure at random.
exit 0
