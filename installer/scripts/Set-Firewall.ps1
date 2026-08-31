<#
.SYNOPSIS
    Allows handheld terminals to reach PharmaVerify, and nothing else.

.DESCRIPTION
    Opens one inbound TCP port on private networks only.

    Scoped deliberately narrowly. The pharmacy PC is an ordinary back-office
    machine that will meet other networks — a phone hotspot, a hotel, an
    engineer's laptop sharing a connection — and a rule that follows it onto
    those exposes the stock system to strangers. Private-only means the rule
    stops applying the moment the machine leaves the store network.

    Safe to run again. An existing correct rule is reported and left alone; an
    existing rule with the wrong settings is corrected rather than duplicated,
    because duplicates are how a firewall ends up with a permissive rule nobody
    remembers creating.

.PARAMETER Port
    The port PharmaVerify listens on.

.PARAMETER RuleName
    Display name of the rule. Keep it recognisable in the firewall list.

.PARAMETER Remove
    Removes the rule instead of creating it. Used by the uninstaller.

.EXAMPLE
    .\Set-Firewall.ps1 -Port 8000
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [int]    $Port = 8000,
    [string] $RuleName = 'PharmaVerify',
    [switch] $Remove
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

if (-not (Test-Administrator)) {
    Write-Log 'Firewall changes need administrator rights. Run this elevated.' -Level FAIL
    exit 1
}

$existing = Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue

# ------------------------------------------------------------------- removal

if ($Remove) {
    if ($existing) {
        if ($PSCmdlet.ShouldProcess($RuleName, 'Remove firewall rule')) {
            $existing | Remove-NetFirewallRule
            Write-Log "Removed the firewall rule '$RuleName'." -Level OK
        }
    } else {
        Write-Log "No firewall rule named '$RuleName' to remove." -Level INFO
    }
    exit 0
}

# ------------------------------------------------------------------ inspection

if ($existing) {
    # Compare what is there against what is wanted before touching anything.
    # A rule that is already right is left exactly as it is, so re-running the
    # installer does not churn the firewall.
    $filter  = $existing | Get-NetFirewallPortFilter
    $correct =
        $existing.Enabled  -eq 'True' -and
        $existing.Direction -eq 'Inbound' -and
        $existing.Action    -eq 'Allow' -and
        $existing.Profile -match 'Private' -and
        $existing.Profile -notmatch 'Public' -and
        $filter.LocalPort  -eq "$Port" -and
        $filter.Protocol   -eq 'TCP'

    if ($correct) {
        Write-Log "Firewall already allows TCP $Port on private networks." -Level OK
        exit 0
    }

    Write-Log "A rule named '$RuleName' exists but does not match what is needed. Correcting it." -Level WARN

    if ($PSCmdlet.ShouldProcess($RuleName, 'Correct firewall rule')) {
        # Corrected in place rather than removed and recreated: the rule keeps
        # its identity, and there is no window where the port is closed.
        $existing | Set-NetFirewallRule `
            -Enabled True `
            -Direction Inbound `
            -Action Allow `
            -Profile Private

        $existing | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter `
            -Protocol TCP `
            -LocalPort $Port

        Write-Log "Corrected '$RuleName' to allow TCP $Port on private networks." -Level OK
    }
    exit 0
}

# -------------------------------------------------------------------- creation

if ($PSCmdlet.ShouldProcess($RuleName, "Allow inbound TCP $Port on private networks")) {
    New-NetFirewallRule `
        -DisplayName $RuleName `
        -Description 'Lets handheld terminals on the store network submit stock counts to PharmaVerify.' `
        -Direction Inbound `
        -Protocol TCP `
        -LocalPort $Port `
        -Action Allow `
        -Profile Private `
        -Enabled True | Out-Null

    Write-Log "Firewall now allows TCP $Port on private networks." -Level OK
}

# ------------------------------------------------------- the usual silent trap

# The rule above applies only to networks Windows considers Private. On a
# network marked Public it is inert: the PC keeps working perfectly and no
# handheld can reach it, with nothing anywhere reporting a problem.
$lan = Get-LanAddress

if ($lan -and $lan.NetworkProfile -eq 'Public') {
    Write-Log "The active network '$($lan.AdapterName)' is set to Public, so this rule will not apply and handhelds will be blocked. Set that network to Private in Windows settings." -Level WARN
} elseif ($lan) {
    Write-Log "Active network: $($lan.AdapterName) - $($lan.IPAddress) ($($lan.NetworkProfile))." -Level INFO
}
