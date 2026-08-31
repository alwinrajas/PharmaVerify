<#
.SYNOPSIS
    Prepares the SQL Server database PharmaVerify stores stock counts in.

.DESCRIPTION
    Finds a SQL Server instance, creates the database and login if they are
    missing, and applies the schema.

    Nothing here is destructive. An existing PharmaVerify database is adopted,
    never rebuilt: by the time anyone runs an upgrade that database holds
    counted stock, audits and adjustments that exist nowhere else. Migrations
    only add what is missing.

    Production seeders only. The demo seeder writes sample shops and audits that
    are indistinguishable from real ones once staff start work.

.PARAMETER InstallRoot
    Where the application files are.

.PARAMETER SqlInstance
    Instance to use, e.g. ".\SQLEXPRESS". Detected when omitted.

.PARAMETER DbName / DbUser / DbPassword
    The database and the login PharmaVerify connects as.

.PARAMETER SkipSchema
    Creates the database but does not apply migrations.

.EXAMPLE
    .\Initialize-Database.ps1 -DbPassword $secure
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [string] $InstallRoot = (Join-Path ${env:ProgramFiles} 'PharmaVerify'),
    [string] $SqlInstance,
    [string] $DbName = 'pharmaverify',
    [string] $DbUser = 'pharmaverify',
    [Parameter(Mandatory)] [securestring] $DbPassword,
    [switch] $SkipSchema
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'Common.ps1')

# ------------------------------------------------------------------ discovery

function Find-SqlInstance {
    <#
        .SYNOPSIS
            The SQL Server instance to use, or $null.

        .DESCRIPTION
            Reads the registry rather than guessing at service names, because
            the service name and the instance name are not the same thing and
            a machine may carry several instances from different products.
            Prefers SQLEXPRESS, which is what the installer sets up.
    #>
    $key = 'HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\Instance Names\SQL'
    if (-not (Test-Path $key)) { return $null }

    $instances = (Get-ItemProperty $key -ErrorAction SilentlyContinue).PSObject.Properties |
        Where-Object { $_.Name -notlike 'PS*' } |
        Select-Object -ExpandProperty Name

    if (-not $instances) { return $null }

    $preferred = $instances | Where-Object { $_ -eq 'SQLEXPRESS' } | Select-Object -First 1
    $name = if ($preferred) { $preferred } else { $instances | Select-Object -First 1 }

    return $(if ($name -eq 'MSSQLSERVER') { '.' } else { ".\$name" })
}

function Invoke-Sql {
    <#
        .SYNOPSIS
            Runs T-SQL against the instance using Windows authentication.

        .DESCRIPTION
            Uses sqlcmd, which ships with SQL Server, so the installer needs no
            PowerShell module the client may not have.
    #>
    param(
        [Parameter(Mandatory)] [string] $Instance,
        [Parameter(Mandatory)] [string] $Query
    )

    $output = & sqlcmd -S $Instance -E -b -Q $Query 2>&1 | Out-String
    return [pscustomobject]@{
        Success = $LASTEXITCODE -eq 0
        Output  = $output.Trim()
    }
}

# --------------------------------------------------------------------- checks

if (-not (Test-Administrator)) {
    Write-Log 'Database setup needs administrator rights.' -Level FAIL
    exit 1
}

if (-not (Get-Command sqlcmd -ErrorAction SilentlyContinue)) {
    Write-Log 'sqlcmd was not found. It is installed with SQL Server; install SQL Server Express first.' -Level FAIL
    exit 2
}

if (-not $SqlInstance) { $SqlInstance = Find-SqlInstance }

if (-not $SqlInstance) {
    Write-Log 'No SQL Server instance was found on this machine.' -Level FAIL
    Write-Log 'Install SQL Server Express (free), then run the installer again.' -Level INFO
    exit 2
}

Write-Log "Using SQL Server instance $SqlInstance." -Level INFO

$probe = Invoke-Sql -Instance $SqlInstance -Query 'SELECT @@VERSION'
if (-not $probe.Success) {
    Write-Log "Could not connect to $SqlInstance. Check the service is running." -Level FAIL
    Write-Log $probe.Output -Level INFO
    exit 3
}

$version = ($probe.Output -split "`n" | Select-Object -First 1).Trim()
Write-Log "Connected: $version" -Level OK

# ------------------------------------------------------------- the database

$exists = Invoke-Sql -Instance $SqlInstance -Query "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.databases WHERE name = '$DbName';"
$databaseExists = $exists.Success -and ($exists.Output -match '\b1\b')

if ($databaseExists) {
    # Adopted, not rebuilt. This is the branch an upgrade takes, and the
    # database on the other side of it is the pharmacy's stock history.
    Write-Log "An existing '$DbName' database was found. It will be used as it is; no data is removed." -Level OK
} else {
    if ($PSCmdlet.ShouldProcess($DbName, 'Create database')) {
        $create = Invoke-Sql -Instance $SqlInstance -Query "CREATE DATABASE [$DbName];"
        if (-not $create.Success) {
            Write-Log "Could not create the database: $($create.Output)" -Level FAIL
            exit 4
        }
        Write-Log "Created the database '$DbName'." -Level OK
    }
}

# ----------------------------------------------------------------- the login

$plain = [System.Net.NetworkCredential]::new('', $DbPassword).Password

# Escaped for T-SQL string literals. A password containing a quote would
# otherwise end the statement early and change what is executed.
$escaped = $plain -replace "'", "''"

$loginExists = Invoke-Sql -Instance $SqlInstance -Query "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.server_principals WHERE name = '$DbUser';"
$hasLogin = $loginExists.Success -and ($loginExists.Output -match '\b1\b')

if ($PSCmdlet.ShouldProcess($DbUser, 'Configure database login')) {
    if (-not $hasLogin) {
        # CHECK_POLICY stays on: this login can reach the pharmacy's entire
        # stock history, and a weak password on it is not a saving worth making.
        $sql = "CREATE LOGIN [$DbUser] WITH PASSWORD = '$escaped', DEFAULT_DATABASE = [$DbName], CHECK_POLICY = ON;"
        $made = Invoke-Sql -Instance $SqlInstance -Query $sql
        if (-not $made.Success) {
            Write-Log 'Could not create the database login. If the password was rejected, it did not meet the server policy.' -Level FAIL
            exit 5
        }
        Write-Secret "Database login '$DbUser'"
    } else {
        Write-Log "The login '$DbUser' already exists; its password is left unchanged." -Level OK
    }

    # db_owner on this one database only, and no server-level rights. The
    # application creates and alters its own tables through migrations, which
    # needs ownership here and nothing anywhere else.
    $grant = @"
USE [$DbName];
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = '$DbUser')
    CREATE USER [$DbUser] FOR LOGIN [$DbUser];
ALTER ROLE db_owner ADD MEMBER [$DbUser];
"@
    $granted = Invoke-Sql -Instance $SqlInstance -Query $grant
    if (-not $granted.Success) {
        Write-Log "Could not grant access to the database: $($granted.Output)" -Level FAIL
        exit 6
    }
    Write-Log "'$DbUser' owns the '$DbName' database." -Level OK
}

# ------------------------------------------------------------------- schema

if ($SkipSchema) {
    Write-Log 'Schema step skipped as requested.' -Level INFO
    exit 0
}

$php     = Join-Path $InstallRoot 'runtime\php\php.exe'
$backend = Join-Path $InstallRoot 'backend'

if (-not (Test-Path $php))     { $cmd = Get-Command php -ErrorAction SilentlyContinue; if ($cmd) { $php = $cmd.Source } } # `?.` is PS7+ only; this runs under Windows PowerShell 5.1 (powershell.exe)
if (-not $php)                 { Write-Log 'No PHP runtime found; cannot apply the schema.' -Level FAIL; exit 7 }
if (-not (Test-Path $backend)) { Write-Log 'Application files not found; cannot apply the schema.' -Level FAIL; exit 7 }

if ($PSCmdlet.ShouldProcess($DbName, 'Apply schema')) {
    Push-Location $backend
    try {
        # --force because this is an unattended production run. Migrations only
        # add what is missing; none of them drop or truncate.
        & $php artisan migrate --force
        if ($LASTEXITCODE -ne 0) {
            Write-Log 'Applying the schema failed. The database was left as it was.' -Level FAIL
            exit 8
        }
        Write-Log 'Schema is up to date.' -Level OK

        # Seeded only into a database that has no users yet. Running them again
        # is harmless but pointless, and this keeps a reinstall from touching a
        # live system's accounts.
        # Via a temp file, not -r: 5.1 mangles multi-line inline arguments to
        # native commands (see the same fix in Test-Health.ps1).
        $countFile = Join-Path $env:TEMP 'pharmaverify-usercount.php'
        [System.IO.File]::WriteAllText($countFile, @'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { echo Illuminate\Support\Facades\DB::table("users")->count(); } catch (Throwable $e) { echo "?"; }
'@, [System.Text.UTF8Encoding]::new($false))
        try {
            $userCount = & $php $countFile 2>$null
        } finally {
            Remove-Item $countFile -Force -ErrorAction SilentlyContinue
        }

        if ($userCount -eq '0') {
            # Roles, permissions, the first administrator and application
            # settings. Never DemoAuditSeeder: its sample audits cannot be told
            # apart from real ones once staff start counting.
            foreach ($seeder in @('RolePermissionSeeder', 'UserSeeder', 'AppSettingSeeder')) {
                & $php artisan db:seed --class=$seeder --force *> $null
                if ($LASTEXITCODE -ne 0) {
                    Write-Log "Could not apply $seeder." -Level WARN
                }
            }
            Write-Log 'Roles, permissions and the first administrator account were created.' -Level OK
            Write-Log 'Sign in and change the administrator password before the system is used.' -Level WARN
        } else {
            Write-Log "The database already has $userCount user account(s); existing accounts were left alone." -Level OK
        }
    } finally { Pop-Location }
}

Write-Log 'Database ready.' -Level OK
