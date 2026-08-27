# Database scripts

Operational scripts for a deployed PharmaVerify instance. **None of these run
automatically** — the application has no backup scheduler. Full procedure,
including RPO, RTO and the responsibility split, is in
[`docs/18-BACKUP-AND-RECOVERY.md`](../../docs/18-BACKUP-AND-RECOVERY.md).

| Script | Purpose |
| --- | --- |
| `backup-sqlserver.sql` | Full, differential and log backup statements, plus a query listing what has been taken |
| `restore-sqlserver.sql` | Restore to a point in time, including the orphaned-login fix that catches most people out |
| `verify-restore.sql` | Read-only checks against a restored database — schema, reference data, referential integrity and PharmaVerify's own business invariants |
| `Backup-PharmaVerify.ps1` | Windows Task Scheduler wrapper: backs up, verifies, copies the generated files and prunes to a retention window |

## Before using them

Edit the paths. Every script assumes `D:\Backups\PharmaVerify` and an
application at `C:\inetpub\pharmaverify`; neither is likely to be right.

`BackupRoot` must be writable by **both** the account running the task and the
SQL Server service account — SQL Server writes the `.bak` itself.

## Scheduling

```powershell
# Daily full, keeping 30 days
schtasks /Create /TN "PharmaVerify Full Backup" /SC DAILY /ST 01:00 /RU SYSTEM ^
  /TR "powershell -NonInteractive -File C:\scripts\Backup-PharmaVerify.ps1 -Type Full -RetentionDays 30"

# Log backup every 15 minutes — this interval is the RPO
schtasks /Create /TN "PharmaVerify Log Backup" /SC MINUTE /MO 15 /RU SYSTEM ^
  /TR "powershell -NonInteractive -File C:\scripts\Backup-PharmaVerify.ps1 -Type Log -RetentionDays 7"
```

The script exits non-zero on failure so the task reports it. **Configure an
alert on that** — a backup job that fails silently is worse than none, because
it is trusted.

## A caution about `verify-restore.sql`

Run it against the **restored** database, never production. It is read-only, so
it cannot damage anything, but pointing it at production tells you nothing about
the restore.

Only SQL Server is covered here. MySQL and SQLite are development and test
engines holding nothing that `php artisan migrate:fresh --seed` cannot recreate.
