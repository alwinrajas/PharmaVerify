<#
.SYNOPSIS
    Publishes the local hostname PharmaVerify is reached by, or removes it.

.DESCRIPTION
    The handheld app permits plain HTTP only to addresses compiled into it. One
    of those is the name `pharmaverify.local`, which is why a name is preferred
    over an IP: a name works at every site without rebuilding the APK, and keeps
    working when DHCP moves the PC.

    The hosts entry this writes is `127.0.0.1  <hostname>  # PharmaVerify` —
    loopback, not the LAN IP. That is a deliberate change from earlier
    versions: a LAN-IP hosts entry goes stale the instant DHCP moves this PC,
    and from then on it silently points the browser at whatever machine picked
    up the old address — a wrong answer that looks exactly like a right one.
    127.0.0.1 can never go stale; it always means "this machine". The hosts
    file only ever affected this PC anyway — Android devices never read it,
    they need the router's DNS or the LAN IP directly.

.PARAMETER Hostname
    The name to publish. Must be one the handheld app already permits.

.PARAMETER Port
    Port PharmaVerify listens on. Used only to build the endpoint URLs reported
    at the end; the hosts file itself carries no port.

.PARAMETER HostsPath
    Path to the hosts file. Defaults to the real Windows one. Parameterised so
    the line-transform in this script can be tested against a copy of the file
    without needing an elevated session or touching the real one.

.PARAMETER Remove
    Strips the PharmaVerify entry instead of writing it. Used by the
    uninstaller.

.EXAMPLE
    .\Set-Hostname.ps1
.EXAMPLE
    .\Set-Hostname.ps1 -Remove
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [string] $Hostname = 'pharmaverify.local',
    [int]    $Port = 8000,
    [string] $HostsPath = "$env:SystemRoot\System32\drivers\etc\hosts",
    [switch] $Remove
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

# Elevation is only demanded for the file elevation actually protects. A test
# copy passed via -HostsPath needs no administrator rights, and gating it
# anyway would defeat the very reason the path is a parameter: exercising the
# line transform without an elevated session or a real hosts file at risk.
$script:TargetIsSystemHosts = ($HostsPath -eq "$env:SystemRoot\System32\drivers\etc\hosts")
$script:CanWriteTarget = (-not $script:TargetIsSystemHosts) -or (Test-Administrator)

function Test-PharmaVerifyHostsLine {
    <#
        .SYNOPSIS
            True for a line this script owns and should replace on rewrite.

        .DESCRIPTION
            Two kinds of line are ours: any line tagged with the `# PharmaVerify`
            marker comment (however it got there), and any line whose hostname
            tokens include $Hostname (which catches an old, wrong-IP entry from
            before this line existed, or a duplicate). Matching both in one pass
            is what lets install both dedupe repeats and correct a stale entry
            without a second pass.
    #>
    param(
        [Parameter(Mandatory)] [AllowEmptyString()] [string] $Line,
        [Parameter(Mandatory)] [string] $HostnameToMatch
    )

    if ($Line -match '#\s*PharmaVerify\s*$') { return $true }

    # Wrapped in @(): under StrictMode, Windows PowerShell 5.1 throws on
    # .Count when a pipeline unwraps to a single string or to nothing —
    # PowerShell 7 tolerates both, so only a real 5.1 run catches this.
    $tokens = @($Line -split '\s+' | Where-Object { $_ -ne '' })
    if ($tokens.Count -lt 2) { return $false }

    # Token 0 is the IP; everything after it is a hostname/alias for that line.
    return [bool] ($tokens[1..($tokens.Count - 1)] | Where-Object { $_ -eq $HostnameToMatch })
}

function Test-PharmaVerifyTaggedHostsLine {
    <#
        .SYNOPSIS
            True only for a line this script itself wrote (carries the
            `# PharmaVerify` tag).

        .DESCRIPTION
            EVIDENCE: an uninstall once deleted a hosts entry the user had
            added by hand, years-of-habit style, because removal collapsed
            every line that merely mentioned the hostname. The uninstaller
            may only take back what the installer itself wrote, so removal
            matches on the tag alone - never on hostname text, which a person
            is free to have typed in themselves. (Install mode keeps matching
            on both the tag and the hostname via Test-PharmaVerifyHostsLine
            above - that dedupes its own repeats and corrects a stale entry,
            and is correct because it only ever runs against lines it is
            about to replace with its own canonical one.)
    #>
    param([Parameter(Mandatory)] [AllowEmptyString()] [string] $Line)

    return [bool] ($Line -match '#\s*PharmaVerify\s*$')
}

function Read-HostsFileLines {
    <#
        .SYNOPSIS
            Reads the hosts file, retrying past a transient lock.

        .DESCRIPTION
            EVIDENCE: an elevated install once crashed here with "Stream was
            not readable" while updating the hosts file - a path Defender
            watches closely - inside a hidden-window elevated process. The
            one-line warning that caught it swallowed the exception type, and
            no hosts entry was written. [System.IO.File]::ReadAllLines plus a
            short retry rides out a transient scanner lock; a final failure
            rethrows so the caller sees the real exception type instead of a
            vague one-liner.

            Returns an empty array, without retrying, when the file simply
            does not exist - matching the old Get-Content -ErrorAction
            SilentlyContinue behaviour for a missing file, which is not the
            transient-lock condition this retry exists for.
    #>
    param([Parameter(Mandatory)] [string] $Path)

    if (-not (Test-Path $Path)) { return @() }

    $attempt = 0
    while ($true) {
        $attempt++
        try {
            return @([System.IO.File]::ReadAllLines($Path))
        } catch {
            if ($attempt -ge 3) { throw }
            Start-Sleep -Milliseconds 300
        }
    }
}

function Write-HostsFileLines {
    <#
        .SYNOPSIS
            Writes the hosts file, retrying past a transient lock.

        .DESCRIPTION
            See Read-HostsFileLines above for the evidence. ASCII to match
            what the file has always been written as; [string[]] on the
            parameter so a single-line result from an upstream @() wrap is
            never silently written as a character array.
    #>
    param(
        [Parameter(Mandatory)] [string]   $Path,
        [Parameter(Mandatory)] [AllowEmptyCollection()] [string[]] $Lines
    )

    $attempt = 0
    while ($true) {
        $attempt++
        try {
            [System.IO.File]::WriteAllLines($Path, $Lines, [System.Text.Encoding]::ASCII)
            return
        } catch {
            if ($attempt -ge 3) { throw }
            Start-Sleep -Milliseconds 300
        }
    }
}

function Clear-LocalDnsCache {
    <#
        .SYNOPSIS
            Flushes the resolver cache so a changed hosts file takes effect
            immediately instead of after whatever the negative-cache TTL is.
    #>
    try {
        Clear-DnsClientCache -ErrorAction Stop
        Write-Log 'DNS cache cleared.' -Level OK
    } catch {
        # Clear-DnsClientCache needs the DNS Client module, which is not on
        # every SKU. ipconfig ships with every Windows install and does the
        # same job.
        & ipconfig /flushdns *> $null
        Write-Log 'DNS cache cleared (ipconfig fallback).' -Level OK
    }
}

# ------------------------------------------------------------------- removal

if ($Remove) {
    if (-not $script:CanWriteTarget) {
        Write-Log 'Not running elevated; the hosts entry was left as it is.' -Level WARN
        exit 0
    }

    if (-not (Test-Path $HostsPath)) {
        Write-Log 'No hosts file found; nothing to remove.' -Level INFO
        exit 0
    }

    if ($PSCmdlet.ShouldProcess($HostsPath, "Remove the $Hostname entry")) {
        # Tagged lines only - see Test-PharmaVerifyTaggedHostsLine above for
        # why this must not also match on hostname text.
        $lines = @(Read-HostsFileLines -Path $HostsPath)
        $kept = @($lines | Where-Object { -not (Test-PharmaVerifyTaggedHostsLine -Line $_) })

        Write-HostsFileLines -Path $HostsPath -Lines $kept
        Write-Log "Removed the $Hostname entry from the hosts file." -Level OK

        # The backup is deliberately NOT restored here. It captured the state
        # of the file before this installation's very first change, and by
        # uninstall time the user may have added other, unrelated hosts lines
        # of their own — restoring the backup would silently discard those.
        # Removal is surgical instead: only the two classes of line this
        # script owns are stripped, and everything else the file has
        # accumulated since is left alone.
        $backupPath = Join-Path (Split-Path -Parent $HostsPath) 'hosts.pharmaverify.backup'
        if (Test-Path $backupPath) {
            Write-Log "The pre-installation backup is left in place at $backupPath." -Level INFO
        }
    }

    Clear-LocalDnsCache
    exit 0
}

# -------------------------------------------------------------- install mode

$lan = Get-LanAddress

if (-not $lan) {
    # Still worth configuring the local hostname even with no network: it is
    # what lets the PC itself be tested by name. Handhelds simply have nothing
    # to connect to yet, which is said plainly rather than left to be
    # discovered later.
    Write-Log 'No active network was found. The local hostname will still be set up, but handhelds have nothing to connect to yet - connect this PC to the store network.' -Level WARN
} else {
    Write-Log "Network: $($lan.AdapterName) - $($lan.IPAddress) ($($lan.NetworkProfile))" -Level INFO

    # A rule scoped to Private is inert on a Public network. The PC keeps
    # working and no handheld can reach it, with nothing reporting a fault.
    if ($lan.NetworkProfile -eq 'Public') {
        Write-Log 'This network is set to Public, so handhelds will be blocked. Change it to Private in Windows network settings.' -Level WARN
    }
}

# THE KEY DESIGN CHANGE: loopback, not the LAN IP. See the script's own
# description above for why — in short, 127.0.0.1 cannot go stale the way a
# LAN-IP entry does when DHCP moves this PC.
$entry = "127.0.0.1`t$Hostname`t# PharmaVerify"

if ($script:CanWriteTarget) {
    if ($PSCmdlet.ShouldProcess($HostsPath, "Publish $Hostname locally")) {
        # Wrapped in try/catch: a failure here (the same transient-lock
        # condition Read/Write-HostsFileLines retries past, or anything else)
        # must not abort the script. It is caught, logged with its full
        # exception type, and the script continues so the endpoint JSON below
        # is still emitted with HostnameResolvesLocally reflecting reality.
        try {
            $hostsDir = Split-Path -Parent $HostsPath
            $backupPath = Join-Path $hostsDir 'hosts.pharmaverify.backup'

            # Only the first backup is kept — it represents the pre-PharmaVerify
            # state of the file. Overwriting it on a later run or an upgrade would
            # destroy the one copy that shows what was there before PharmaVerify
            # ever touched it.
            if ((Test-Path $HostsPath) -and -not (Test-Path $backupPath)) {
                Copy-Item -Path $HostsPath -Destination $backupPath
                Write-Log "Backed up the hosts file to $backupPath." -Level OK
            }

            $lines = @(Read-HostsFileLines -Path $HostsPath)

            # Drops any line for $Hostname and any PharmaVerify-tagged line, then
            # appends one canonical entry. This dedupes duplicates left by earlier
            # runs and corrects a stale wrong-IP entry, in a single pass.
            $kept = @($lines | Where-Object { -not (Test-PharmaVerifyHostsLine -Line $_ -HostnameToMatch $Hostname) })
            $updated = @($kept) + $entry

            Write-HostsFileLines -Path $HostsPath -Lines $updated
            Write-Log "$Hostname now resolves to 127.0.0.1 on this PC." -Level OK
        } catch {
            Write-Log "Could not update the hosts file: $($_.Exception.GetType().FullName): $($_.Exception.Message)" -Level FAIL
        }
    }
} else {
    # This step is optional in the installer's eyes — installation continues,
    # and the JSON below is still produced either way.
    Write-Log 'Not running elevated; the hosts entry was not written.' -Level WARN
}

Clear-LocalDnsCache

# --------------------------------------------------------------------- verify

# Checked rather than trusted: GetHostAddresses uses the same resolver path an
# application uses, hosts file included, so a pass here means the browser will
# actually see it too. Resolve-DnsName's behaviour with hosts entries varies
# between Windows builds and is not a reliable stand-in for this.
$resolvesLocally = $false
$resolvedTo = $null
try {
    $resolved = [System.Net.Dns]::GetHostAddresses($Hostname)
    $resolvedTo = ($resolved | ForEach-Object { $_.IPAddressToString }) -join ', '
    $resolvesLocally = [bool] ($resolved | Where-Object { $_.IPAddressToString -eq '127.0.0.1' })
} catch {
    $resolvesLocally = $false
}

if ($resolvesLocally) {
    Write-Log "$Hostname resolves to 127.0.0.1 on this PC." -Level OK
} elseif ($resolvedTo) {
    Write-Log "$Hostname resolves to $resolvedTo, not 127.0.0.1." -Level WARN
} else {
    Write-Log "$Hostname does not resolve." -Level WARN
}

# What matters for the handhelds is whether they can resolve the name, and
# that is decided by the router, not by this machine. Said plainly rather than
# implying the local hosts entry has settled the question.
Write-Log '' -Level INFO
if ($lan) {
    Write-Log "For the handhelds to use the name, it must resolve on the store network:" -Level INFO
    Write-Log "  add a DNS entry on the router: $Hostname -> $($lan.IPAddress)" -Level INFO
    Write-Log "  and reserve $($lan.IPAddress) for this PC so it does not change." -Level INFO
    Write-Log '' -Level INFO
    # A plain hyphen, not an em dash: this line is a double-quoted string in a
    # .ps1 file saved without a BOM, and Windows PowerShell 5.1 reads such a
    # file using the system's ANSI code page when there is no BOM to say
    # otherwise. An em dash's UTF-8 bytes misread that way can decode to a
    # smart quote character, which PowerShell's tokenizer treats as a string
    # terminator - silently truncating the string and breaking the parse.
    Write-Log "Until that is done, set the handhelds to http://$($lan.IPAddress):$Port - also permitted, but it breaks if the address changes." -Level INFO
} else {
    Write-Log 'Handhelds have no address to use yet. Connect this PC to the store network and run this again.' -Level INFO
}

# --------------------------------------------------------------- HTTPS later

# Recorded now so moving to HTTPS later is a configuration change rather than a
# new APK. The handheld permits this name over either scheme, so a certificate
# issued for it can be adopted without touching the app.
$notes = @"
PharmaVerify — moving this site to HTTPS

The handheld application permits '$Hostname' whether it is reached over http or
https, so switching does not require a new APK. What is needed:

  1. A certificate for '$Hostname' that the handhelds trust. Either issued by
     the client's internal certificate authority, or a public certificate for a
     name that resolves on the LAN. A self-signed certificate has to be
     installed on every handheld individually.

  2. Bind it in IIS:
       New-WebBinding -Name PharmaVerify -Protocol https -Port 443
       (then assign the certificate to that binding)

  3. Update the configuration at:
       $($script:DataRoot)\config\.env
     changing APP_URL, FRONTEND_URL and CORS_ALLOWED_ORIGINS to the https
     address, then restart the site.

  4. Change the server address on each handheld to the https address.

Until then, traffic between the handhelds and this PC is unencrypted, including
the pairing exchange. On an isolated store network with no guest access that is
a considered trade-off rather than an oversight.
"@

$notesPath = Join-Path $script:DataRoot 'HTTPS-SETUP.txt'
if ($PSCmdlet.ShouldProcess($notesPath, 'Write HTTPS notes')) {
    if (-not (Test-Path $script:DataRoot)) { New-Item -ItemType Directory -Path $script:DataRoot -Force | Out-Null }
    [System.IO.File]::WriteAllText($notesPath, $notes, [System.Text.UTF8Encoding]::new($false))
}

# ------------------------------------------------------------------- output

$localEndpoint = "http://${Hostname}:$Port"
$lanEndpoint = if ($lan) { "http://$($lan.IPAddress):$Port" } else { $null }

[pscustomobject]@{
    LocalEndpoint           = $localEndpoint
    LanEndpoint             = $lanEndpoint
    Address                 = if ($lan) { $lan.IPAddress } else { '' }
    HostnameResolvesLocally = $resolvesLocally
} | ConvertTo-Json -Compress | Write-Output
