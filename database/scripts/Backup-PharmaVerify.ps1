<#
.SYNOPSIS
    Backs up the PharmaVerify database and its generated files.

.DESCRIPTION
    A wrapper for Windows Task Scheduler or SQL Server Agent. PharmaVerify has
    no scheduler of its own - see docs/18-BACKUP-AND-RECOVERY.md section 9 for
    the responsibility split.

    Full and differential backups also copy the application's files. Log
    backups do not, since they run every 15 minutes.

.PARAMETER Type
    Full, Differential or Log.

.PARAMETER SqlInstance
    SQL Server instance. Defaults to the local default instance.

.PARAMETER BackupRoot
    Directory for the backup files. Must exist and be writable by the account
    running the task AND by the SQL Server service account - SQL Server writes
    the .bak itself, not this script.

.PARAMETER AppRoot
    The deployed application directory, the one containing backend\ and
    frontend\. Used to locate the files to copy.

.PARAMETER RetentionDays
    Delete backups of this type older than this many days. 0 disables pruning.

.EXAMPLE
    .\Backup-PharmaVerify.ps1 -Type Full -RetentionDays 30
    .\Backup-PharmaVerify.ps1 -Type Log -RetentionDays 7
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('Full', 'Differential', 'Log')]
    [string] $Type,

    [string] $SqlInstance   = '.',
    [string] $Database      = 'PharmaVerify',
    [string] $BackupRoot    = 'D:\Backups\PharmaVerify',
    [string] $AppRoot       = 'C:\inetpub\pharmaverify',
    [int]    $RetentionDays = 0
)

$ErrorActionPreference = 'Stop'

$stamp   = Get-Date -Format 'yyyyMMdd_HHmmss'
$logFile = Join-Path $BackupRoot 'backup.log'

function Write-Log {
    param([string] $Message, [string] $Level = 'INFO')
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 's'), $Level, $Message
    Write-Host $line
    Add-Content -Path $logFile -Value $line -ErrorAction SilentlyContinue
}

try {
    foreach ($dir in @($BackupRoot, (Join-Path $BackupRoot 'files'))) {
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    }

    Write-Log "Starting $Type backup of $Database on $SqlInstance"

    # ---------------------------------------------------------------- database
    $ext  = if ($Type -eq 'Log') { 'trn' } else { 'bak' }
    $code = switch ($Type) { 'Full' { 'FULL' } 'Differential' { 'DIFF' } 'Log' { 'LOG' } }
    $file = Join-Path $BackupRoot ('{0}_{1}_{2}.{3}' -f $Database, $code, $stamp, $ext)

    $sql = switch ($Type) {
        'Full'         { "BACKUP DATABASE [$Database] TO DISK = N'$file' WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;" }
        'Differential' { "BACKUP DATABASE [$Database] TO DISK = N'$file' WITH DIFFERENTIAL, INIT, COMPRESSION, CHECKSUM, STATS = 10;" }
        'Log'          { "BACKUP LOG [$Database] TO DISK = N'$file' WITH INIT, COMPRESSION, CHECKSUM;" }
    }

    # -b makes sqlcmd exit non-zero on error, so a failure is not silent.
    & sqlcmd -S $SqlInstance -E -b -Q $sql
    if ($LASTEXITCODE -ne 0) { throw "$Type backup failed (sqlcmd exit $LASTEXITCODE)" }

    # An unverified backup is a hope, not a backup.
    & sqlcmd -S $SqlInstance -E -b -Q "RESTORE VERIFYONLY FROM DISK = N'$file' WITH CHECKSUM;"
    if ($LASTEXITCODE -ne 0) { throw "Backup written but FAILED VERIFICATION: $file" }

    $sizeMb = [math]::Round((Get-Item $file).Length / 1MB, 2)
    Write-Log "$Type backup verified: $file ($sizeMb MB)"

    # ------------------------------------------------------------------- files
    # Note the path: Laravel's local disk is rooted at storage/app/private,
    # so storage/app alone is empty.
    if ($Type -ne 'Log') {
        $source = Join-Path $AppRoot 'backend\storage\app\private\final-output'
        $target = Join-Path $BackupRoot 'files\final-output'

        if (Test-Path $source) {
            & robocopy $source $target /MIR /R:2 /W:5 /NP /NJH /NJS | Out-Null
            # robocopy uses exit codes 0-7 for success; 8 and above are failures.
            if ($LASTEXITCODE -ge 8) { throw "robocopy failed (exit $LASTEXITCODE)" }
            Write-Log "Final output files copied to $target"
        }
        else {
            Write-Log "Final output directory not found: $source" 'WARN'
        }
    }

    # --------------------------------------------------------------- retention
    if ($RetentionDays -gt 0) {
        $cutoff  = (Get-Date).AddDays(-$RetentionDays)
        $pattern = '{0}_{1}_*' -f $Database, $code
        $old     = Get-ChildItem -Path $BackupRoot -Filter $pattern -File |
                   Where-Object { $_.LastWriteTime -lt $cutoff }

        foreach ($f in $old) {
            Remove-Item $f.FullName -Force
            Write-Log "Pruned $($f.Name)"
        }
        if ($old) { Write-Log "Pruned $($old.Count) backup(s) older than $RetentionDays days" }
    }

    Write-Log "$Type backup completed"
    exit 0
}
catch {
    Write-Log $_.Exception.Message 'ERROR'
    # Non-zero tells Task Scheduler the run failed, so the alert fires. A
    # silently failing backup job is worse than none, because it is trusted.
    exit 1
}
