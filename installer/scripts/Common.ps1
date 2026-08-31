<#
    Shared helpers for the PharmaVerify installation scripts.

    Dot-sourced by the others; not run on its own.

    The logging here is deliberately careful about what it writes. An
    installation log is read by whoever is fixing a problem, and is routinely
    emailed to support with no thought about what is in it — so a database
    password or a pairing code written once lives on in inboxes indefinitely.
    Write-Secret exists so a script never has to choose between logging
    something useful and logging something safe.
#>

Set-StrictMode -Version Latest

# Mutable data lives outside Program Files: that tree is read-only for standard
# users and is replaced wholesale on upgrade, which would take the logs and the
# configuration with it.
$script:DataRoot = Join-Path $env:ProgramData 'PharmaVerify'
$script:LogRoot  = Join-Path $script:DataRoot 'logs'
$script:LogFile  = Join-Path $script:LogRoot  'install.log'

function Initialize-PharmaVerifyLog {
    <#
        .SYNOPSIS
            Makes sure there is somewhere to write before anything else runs.
    #>
    if (-not (Test-Path $script:LogRoot)) {
        New-Item -ItemType Directory -Path $script:LogRoot -Force | Out-Null
    }
}

function Write-Log {
    <#
        .SYNOPSIS
            One line to the console and the installation log.

        .PARAMETER Level
            INFO, OK, WARN or FAIL. Colours the console; recorded verbatim in
            the log so it can be searched afterwards.
    #>
    param(
        # AllowEmptyString because scripts log blank lines as visual spacing —
        # and Mandatory alone rejects '' outright, which turned every such
        # spacer into a parameter-binding error that killed the caller.
        [Parameter(Mandatory)] [AllowEmptyString()] [string] $Message,
        [ValidateSet('INFO', 'OK', 'WARN', 'FAIL')] [string] $Level = 'INFO'
    )

    Initialize-PharmaVerifyLog

    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp [$Level] $Message" | Add-Content -Path $script:LogFile -Encoding UTF8

    $colour = switch ($Level) {
        'OK'   { 'Green' }
        'WARN' { 'Yellow' }
        'FAIL' { 'Red' }
        default { 'Gray' }
    }

    $prefix = switch ($Level) {
        'OK'   { '  [ok]   ' }
        'WARN' { '  [warn] ' }
        'FAIL' { '  [fail] ' }
        default { '  ' }
    }

    Write-Host "$prefix$Message" -ForegroundColor $colour
}

function Write-Secret {
    <#
        .SYNOPSIS
            Records that something was set, without recording what it was.

        .DESCRIPTION
            For passwords, tokens and pairing codes. The log says the step
            happened, which is what makes a log useful, and says nothing that
            would let a reader use it.
    #>
    param([Parameter(Mandatory)] [string] $What)

    Write-Log "$What was set (value not recorded)" -Level OK
}

function Test-Administrator {
    <#
        .SYNOPSIS
            True when running elevated.

        .DESCRIPTION
            Firewall rules, services and Program Files all need it. Checked up
            front so the failure is one clear sentence rather than a partial
            installation that stops halfway with an access-denied error.
    #>
    $identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-LanAddress {
    <#
        .SYNOPSIS
            The address handhelds should use to reach this PC.

        .DESCRIPTION
            Picks the interface that actually carries traffic off this machine,
            by asking which one Windows would route through. Enumerating
            adapters and guessing gets this wrong on the machines that matter:
            a PC with Hyper-V, WSL, a VPN or a phone tethered to it has several
            plausible-looking private addresses, and only one of them is the
            one the handhelds share.

            Returns $null when there is no usable network rather than guessing.
    #>
    try {
        # 1.1.1.1 is never contacted. Asking for the route to a public address
        # is how you find out which interface is the default one.
        $route = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction Stop |
            Sort-Object RouteMetric, ifMetric |
            Select-Object -First 1

        if (-not $route) { return $null }

        $address = Get-NetIPAddress -InterfaceIndex $route.ifIndex -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object { $_.IPAddress -ne '127.0.0.1' } |
            Select-Object -First 1

        if (-not $address) { return $null }

        # An APIPA address (169.254.*) means DHCP failed to hand out a real
        # one. Handing that to handhelds sends them to an address that routes
        # nowhere, which is worse than saying no network was found at all.
        if ($address.IPAddress -like '169.254.*') { return $null }

        $adapter = Get-NetAdapter -InterfaceIndex $route.ifIndex -ErrorAction SilentlyContinue
        $profile = Get-NetConnectionProfile -InterfaceIndex $route.ifIndex -ErrorAction SilentlyContinue

        return [pscustomobject]@{
            IPAddress      = $address.IPAddress
            InterfaceIndex = $route.ifIndex
            AdapterName    = if ($adapter) { $adapter.Name } else { 'Unknown' }
            NetworkProfile = if ($profile) { $profile.NetworkCategory } else { 'Unknown' }
        }
    } catch {
        return $null
    }
}

function Test-PortListening {
    <#
        .SYNOPSIS
            True when something is accepting connections on the port.

        .DESCRIPTION
            Checks the listener rather than making a request, so it answers
            "is the service up" without depending on the application being
            healthy enough to reply.
    #>
    param([Parameter(Mandatory)] [int] $Port)

    $listeners = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    return [bool] $listeners
}

function Get-PortOwner {
    <#
        .SYNOPSIS
            Who, if anyone, is listening on a port.

        .DESCRIPTION
            Exists so the installer can tell "the port is taken by our own
            previous installation — fine, adopt it" from "the port is taken by
            something else — stop and say which program, never silently pick
            another port".

            Returns $null when nothing listens on the port.
    #>
    param([Parameter(Mandatory)] [int] $Port)

    $listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
        Select-Object -First 1

    if (-not $listener) { return $null }

    $processId = $listener.OwningProcess
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    $processName = if ($process) { $process.ProcessName } else { 'Unknown' }

    # PID 4 is the System process, which is what HTTP.SYS listens as on behalf
    # of IIS. That alone does not prove the listener is *our* site — any IIS
    # site could own the port — so it only counts as ours when a PharmaVerify
    # site also exists with a binding on this port. If the IIS management
    # tooling is not present, that cannot be confirmed either way, so it is
    # treated as someone else's rather than guessed at.
    $isOurSite = $false
    if ($processId -eq 4) {
        try {
            Import-Module WebAdministration -ErrorAction Stop
            $site = Get-Website -Name 'PharmaVerify' -ErrorAction SilentlyContinue
            if ($site) {
                $isOurSite = [bool] ($site.Bindings.Collection | Where-Object { ($_.bindingInformation -split ':')[1] -eq "$Port" })
            }
        } catch {
            $isOurSite = $false
        }
    }

    return [pscustomobject]@{
        ProcessId   = $processId
        ProcessName = $processName
        IsOurSite   = $isOurSite
    }
}

function Test-BoundToAllInterfaces {
    <#
        .SYNOPSIS
            True when the port is reachable from other machines.

        .DESCRIPTION
            A service bound only to 127.0.0.1 looks perfectly healthy from the
            PC it runs on and is invisible to every handheld in the building.
            That failure is silent and wastes an afternoon, so it is checked
            explicitly rather than inferred from the port being open.
    #>
    param([Parameter(Mandatory)] [int] $Port)

    $listeners = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    if (-not $listeners) { return $false }

    return [bool] ($listeners | Where-Object { $_.LocalAddress -in @('0.0.0.0', '::') })
}
