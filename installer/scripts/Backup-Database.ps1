<#
.SYNOPSIS
    Backs up the PharmaVerify database.

.DESCRIPTION
    The pharmacy PC is usually the only place this data exists. A stock count is
    hours of somebody walking shelves, and once the disk goes there is nothing
    to restore from and no way to reconstruct it except counting again.

    Run before an upgrade, on a schedule, and whenever an administrator asks.

    Backups are timestamped and never overwritten, so a backup taken after a
    problem cannot destroy the good one taken before it.

.PARAMETER Destination
    Where to write. Defaults to ProgramData, which upgrades do not replace.

.PARAMETER RetentionDays
    Delete backups older than this. 0 keeps everything.

.PARAMETER Label
    Added to the file name, e.g. "before-upgrade".

.EXAMPLE
    .\Backup-Database.ps1 -Label before-upgrade
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [string] $Destination,
    [int]    $RetentionDays = 30,
    [string] $Label = 'manual'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

if (-not $Destination) { $Destination = Join-Path $script:DataRoot 'backups' }

$envPath = Join-Path $script:DataRoot 'config\.env'
if (-not (Test-Path $envPath)) {
    Write-Log "No configuration found at $envPath. Is PharmaVerify installed?" -Level FAIL
    exit 1
}

# Read straight from the application's own configuration, so a backup can never
# be taken from a different database than the one in use.
$settings = @{}
foreach ($line in Get-Content $envPath) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*"?([^"]*)"?\s*$') {
        $settings[$Matches[1]] = $Matches[2]
    }
}

$connection = $settings['DB_CONNECTION']
$database   = $settings['DB_DATABASE']
$dbHost     = $settings['DB_HOST']

if (-not $database) {
    Write-Log 'The configuration does not name a database.' -Level FAIL
    exit 1
}

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
}

# Sortable, and unique to the second. Two backups in the same minute do not
# collide, which matters when several are taken while chasing a problem.
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$safeLabel = ($Label -replace '[^A-Za-z0-9\-]', '-')

Write-Log "Backing up '$database' ($connection)." -Level INFO

switch ($connection) {

    'sqlsrv' {
        $file = Join-Path $Destination "pharmaverify-$stamp-$safeLabel.bak"

        # SQL Server writes the file itself, as the service account. The path
        # must therefore be one that account can reach — a mapped drive or a
        # user profile folder will fail even though it looks fine from here.
        $instance = if ($dbHost -and $dbHost -ne '127.0.0.1' -and $dbHost -ne 'localhost') { $dbHost } else { '.\SQLEXPRESS' }

        $query = @"
BACKUP DATABASE [$database]
TO DISK = N'$file'
WITH FORMAT, INIT, COMPRESSION, NAME = N'PharmaVerify $stamp',
     STATS = 10;
"@

        if ($PSCmdlet.ShouldProcess($database, 'Back up')) {
            # -E is Windows authentication: the database password stays out of
            # the process list, where any user on the machine could read it.
            $output = & sqlcmd -S $instance -E -b -Q $query 2>&1 | Out-String

            if ($LASTEXITCODE -ne 0) {
                Write-Log 'Backup failed.' -Level FAIL
                # The server's own words, minus anything that looks like a
                # credential. They name the cause; a generic message does not.
                Write-Log (($output -replace 'PASSWORD\s*=\s*''[^'']*''', "PASSWORD='***'") -split "`n" | Select-Object -Last 3 | Out-String).Trim() -Level INFO
                exit 2
            }
        }
    }

    'mysql' {
        $file = Join-Path $Destination "pharmaverify-$stamp-$safeLabel.sql"

        if (-not (Get-Command mysqldump -ErrorAction SilentlyContinue)) {
            Write-Log 'mysqldump was not found. It ships with MySQL; add it to PATH.' -Level FAIL
            exit 2
        }

        if ($PSCmdlet.ShouldProcess($database, 'Back up')) {
            # The password goes in an environment variable rather than on the
            # command line: arguments are visible to every user on the machine.
            $env:MYSQL_PWD = $settings['DB_PASSWORD']
            try {
                & mysqldump --host=$dbHost --user=$($settings['DB_USERNAME']) --single-transaction --routines --result-file=$file $database 2>&1 | Out-Null
                $failed = $LASTEXITCODE -ne 0
            } finally {
                Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
            }

            if ($failed) {
                Write-Log 'Backup failed.' -Level FAIL
                exit 2
            }
        }
    }

    default {
        Write-Log "Backups are not implemented for '$connection'." -Level FAIL
        exit 2
    }
}

if (-not $WhatIfPreference) {
    if (-not (Test-Path $file)) {
        Write-Log 'The backup command reported success but produced no file.' -Level FAIL
        exit 3
    }

    $sizeMb = [math]::Round((Get-Item $file).Length / 1MB, 1)

    # A backup far smaller than expected usually means an empty or wrong
    # database. Better to question it now than to discover it during a restore.
    if ($sizeMb -lt 0.05) {
        Write-Log "The backup is only $sizeMb MB, which is smaller than expected. Check it names the right database." -Level WARN
    }

    Write-Log "Backup written: $file ($sizeMb MB)" -Level OK
}

# ------------------------------------------------------------------ retention

if ($RetentionDays -gt 0) {
    $cutoff = (Get-Date).AddDays(-$RetentionDays)
    $old = Get-ChildItem $Destination -File -Filter 'pharmaverify-*' -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt $cutoff }

    # The newest is never deleted, whatever the retention setting says. A
    # machine left off for two months would otherwise come back, run a
    # scheduled clean-up, and remove the only backup it has.
    $newest = Get-ChildItem $Destination -File -Filter 'pharmaverify-*' -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1

    $removable = $old | Where-Object { $newest -and $_.FullName -ne $newest.FullName }

    foreach ($item in $removable) {
        if ($PSCmdlet.ShouldProcess($item.Name, 'Remove old backup')) {
            Remove-Item $item.FullName -Force
        }
    }

    if ($removable) {
        Write-Log "Removed $($removable.Count) backup(s) older than $RetentionDays days." -Level INFO
    }
}

Write-Log "Backup folder: $Destination" -Level INFO
