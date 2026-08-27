# 18 — Backup and Recovery

For the engineer who has to take the backups, and for the one who has to restore
them at three in the morning.

**PharmaVerify does not back itself up.** The application holds no scheduler, no
backup agent and no retention policy. Everything below is a procedure to be run
by the infrastructure that hosts it. §9 states plainly who owns what.

---

## 1. What has to be backed up

Three things, and only one of them is the database.

| # | What | Where | Recoverable without a backup? |
| --- | --- | --- | --- |
| 1 | **The database** | SQL Server, database `PharmaVerify` | **No.** Counts, verifications, adjustments and the audit trail exist nowhere else |
| 2 | **Generated final outputs** | `backend/storage/app/private/final-output/` | Partly — see below |
| 3 | **`backend/.env`** | On the server, never in git | **No.** Holds `APP_KEY`, database and Microsoft 365 credentials |

### On the database

This is the whole business record. `audit_lines` carries every counted quantity,
`stock_adjustments` every correction with its before and after, and
`activity_log` the trail of who changed what. None of it can be reconstructed
from anywhere else, because the HHT devices discard a count once the server has
acknowledged it.

### On the generated files

`final_outputs.file_path` points at a workbook on disk. If the file is lost but
the row survives, the download and the OneDrive share both fail.

A final output **can** be regenerated from its audit, so this is a
*reduced-service* loss rather than a permanent one — unless the audit itself has
been closed and the file was the evidence handed to the client. Treat it as
needing backup.

`imports/` holds the uploaded source files, referenced by
`stock_imports.stored_path`. They are useful for tracing a disputed import.
**Nice to have, not critical** — the stock they produced is in the database.

`onedrive-demo/` exists only under the demonstration driver. **Never back it up
in production.**

### On `.env`

Nothing in the database is encrypted with `APP_KEY`, so losing it does not
destroy data. It does invalidate active sessions and signed URLs, and the file
also carries the database password and the Microsoft 365 client secret. Keep a
copy in a secrets manager or a sealed envelope — **not** in the same place as
the backups, and never in git.

### What must NOT be backed up

`vendor/`, `node_modules/`, `frontend/dist/`, `storage/framework/cache`,
`storage/framework/sessions`, `storage/framework/views`. All are rebuildable
from the repository, and including them makes restores slower and larger for no
benefit.

---

## 2. SQL Server backup approach

**Set the database to the FULL recovery model.** It is the only model that
supports log backups, and without them the best you can do is restore to last
night.

```sql
ALTER DATABASE PharmaVerify SET RECOVERY FULL;
```

> Under `SIMPLE` recovery the transaction log is truncated at each checkpoint
> and point-in-time restore is impossible. A stock verification system records
> irreversible adjustments all day; losing an afternoon of them is not
> acceptable, so `FULL` is the right model here.

Three backup types, working together:

| Type | What it captures | Restores |
| --- | --- | --- |
| **Full** | The entire database | The baseline |
| **Differential** | Everything changed since the last *full* | Faster than replaying every log |
| **Transaction log** | Every committed transaction since the last log backup | Point in time, and truncates the log |

Scripts are in `database/scripts/`:

| Script | Purpose |
| --- | --- |
| `backup-sqlserver.sql` | Full, differential and log backup statements |
| `restore-sqlserver.sql` | Restore to a point in time |
| `verify-restore.sql` | Post-restore checks against PharmaVerify's own tables |
| `Backup-PharmaVerify.ps1` | Scheduled-task wrapper for the above |

---

## 3. Full backup procedure

### 3.1 Database

```sql
BACKUP DATABASE PharmaVerify
    TO DISK = 'D:\Backups\PharmaVerify\PharmaVerify_FULL_20260826.bak'
    WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;
```

`CHECKSUM` makes SQL Server validate pages as it writes, so a corrupt backup is
noticed at backup time rather than at restore time. `COMPRESSION` typically
reduces size substantially and shortens the restore.

Always verify immediately — an unverified backup is a hope, not a backup:

```sql
RESTORE VERIFYONLY
    FROM DISK = 'D:\Backups\PharmaVerify\PharmaVerify_FULL_20260826.bak'
    WITH CHECKSUM;
```

### 3.2 Files

```powershell
robocopy "C:\inetpub\pharmaverify\backend\storage\app\private\final-output" `
         "D:\Backups\PharmaVerify\files\final-output" /MIR /R:2 /W:5 /LOG+:D:\Backups\PharmaVerify\files.log
```

Note the path: **`storage/app/private/`**, not `storage/app/`. Laravel's `local`
disk is rooted at `storage/app/private`, so a backup of `storage/app/` alone
copies an empty directory.

Add `imports` alongside it if traceability of source files matters to the
business.

### 3.3 Configuration

Copy `backend/.env` to the secrets store whenever it changes. It changes rarely,
so this is an event-driven task, not a scheduled one.

---

## 4. Restore procedure

### 4.1 Stop writes first

Put the application into maintenance mode so nothing writes while the database
is being replaced:

```bash
cd backend
php artisan down --render="errors::503"
```

### 4.2 Restore the database

Restore the full backup, then the most recent differential, then every log
backup in order. Every step except the last uses `NORECOVERY`.

```sql
RESTORE DATABASE PharmaVerify
    FROM DISK = 'D:\Backups\PharmaVerify\PharmaVerify_FULL_20260826.bak'
    WITH NORECOVERY, REPLACE;

RESTORE DATABASE PharmaVerify
    FROM DISK = 'D:\Backups\PharmaVerify\PharmaVerify_DIFF_20260826_1200.bak'
    WITH NORECOVERY;

RESTORE LOG PharmaVerify
    FROM DISK = 'D:\Backups\PharmaVerify\PharmaVerify_LOG_20260826_1215.trn'
    WITH NORECOVERY;

-- The final statement brings the database online.
RESTORE LOG PharmaVerify
    FROM DISK = 'D:\Backups\PharmaVerify\PharmaVerify_LOG_20260826_1230.trn'
    WITH RECOVERY, STOPAT = '2026-08-26T12:25:00';
```

`STOPAT` recovers to a moment — use it when the loss has a known time, such as a
bad import or a mistaken bulk adjustment.

### 4.3 Restore the files

```powershell
robocopy "D:\Backups\PharmaVerify\files\final-output" `
         "C:\inetpub\pharmaverify\backend\storage\app\private\final-output" /MIR /R:2 /W:5
```

Reapply write permissions afterwards — see `12-DEPLOYMENT-GUIDE.md` §3.

### 4.4 Restore configuration if the server is new

Put `.env` back, then:

```bash
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan event:cache
```

### 4.5 Bring it back up

```bash
php artisan up
```

---

## 5. Recovery verification

Never declare a restore finished on the strength of the restore succeeding.

### 5.1 Schema

```bash
cd backend
php artisan migrate:status
```

Every migration must read **Ran**. A `Pending` row means the backup predates a
deployment — apply the outstanding migrations with `php artisan migrate --force`
before letting users in.

### 5.2 Data

Run `database/scripts/verify-restore.sql`. It checks that the tables exist, that
the reference data is present, and — most usefully — that the business
invariants still hold:

- every `audit_line` belongs to an audit that exists
- every posted adjustment still points at its audit line
- `variance_qty` still equals `physical_qty − system_qty` on every line
- shops, roles and permissions are populated

### 5.3 Application

| Check | Expected |
| --- | --- |
| Sign in as an administrator | Succeeds |
| Dashboard | Figures match what was expected at the recovery point |
| Stock Audit → open a recent audit | Lines present with their quantities |
| Stock Adjustment history | The last known adjustment is there |
| Item Stock for one shop | Row count matches the last import |
| Final Output → Download | The file downloads, proving files and rows agree |
| Activity Log | Entries continue up to the recovery point |

The last one matters most: a database restored without its files leaves rows
whose downloads fail, and only an actual download proves the two are in step.

---

## 6. Recommended frequency

Recommendations, not settings the application enforces. Confirm them against the
client's own policy.

| Backup | Frequency | Rationale |
| --- | --- | --- |
| Full | Daily, outside working hours | The dataset is small — thousands of rows, not millions |
| Differential | Every 6 hours during working hours | Shortens the restore chain |
| Transaction log | **Every 15 minutes** | Sets the RPO, and keeps the log from growing |
| Files (`final-output`) | Daily, after the full backup | Files are written throughout the day but are regenerable |
| `.env` | On change | Rarely changes |

A counting day is the busy period: submissions arrive, verifications and
adjustments follow. Log backups matter most then.

---

## 7. Retention

| Backup | Keep | Why |
| --- | --- | --- |
| Full | 30 days on disk, 12 monthly copies off-site | A stock dispute can surface weeks later |
| Differential | 7 days | Superseded by the next full |
| Transaction log | 7 days | Enough to reach any full backup still held |
| Files | Match the full backup retention | Rows and files must be restorable to the same point |

Pharmacy stock records may fall under a longer statutory retention period.
**Confirm with the client** — it is a compliance question, not a technical one.

---

## 8. RPO and RTO

Targets to agree with the client, achievable with the schedule above.

### RPO — Recovery Point Objective: **15 minutes**

At most fifteen minutes of work is lost, set by the log backup interval.

In practice that could mean a handful of verifications and adjustments. The
counts themselves are safer than that number suggests: a device holds its count
until the server acknowledges it, so a submission lost in the gap can simply be
sent again — that is exactly what the idempotency guarantee is for.

Tightening beyond 15 minutes means log shipping or an availability group, which
is an infrastructure decision with real cost.

### RTO — Recovery Time Objective: **2 hours**

| Step | Estimate |
| --- | --- |
| Decide and authorise | 15 min |
| Restore database (full + differential + logs) | 20–40 min |
| Restore files | 5–15 min |
| Verification (§5) | 20 min |
| Return to service | 10 min |
| Contingency | 20 min |

Both figures assume the backups are on fast local storage and the SQL Server
instance is healthy. **Rebuilding a lost server is a different exercise** and
would take considerably longer — that scenario needs its own plan if the client
wants one.

---

## 9. Who is responsible for what

Being honest about this matters more than any script here.

### PharmaVerify provides

- The procedures and scripts in this document.
- `verify-restore.sql`, which checks the application's own invariants.
- The application verification checklist in §5.3.
- A schema whose migrations are re-runnable and engine-agnostic.
- A `migrate:status` command that reveals whether a backup predates a
  deployment.

### The client or DevOps team owns

- **Scheduling the backups.** The application has no scheduler for this.
- **Backup storage** — capacity, off-site copies, encryption at rest.
- **Retention enforcement and pruning.**
- **Monitoring** that backups ran and succeeded. A silently failing job is worse
  than no job, because it is trusted.
- **Running the restore drill** (§10) and re-running it after significant change.
- **`.env` custody** in a secrets manager.
- **Server rebuild** — operating system, IIS or Nginx, PHP, the ODBC driver.
- **Confirming RPO and RTO** are acceptable to the business.
- **Statutory retention.**

### Explicitly not automated

There is no backup agent inside PharmaVerify, no scheduled task shipped with it
and no retention policy in code. Anything stating otherwise would be untrue.
Use SQL Server Agent, Windows Task Scheduler with the supplied PowerShell
wrapper, or whatever the client already runs.

---

## 10. Pre-production restore drill

**Do this before go-live.** A backup that has never been restored is untested,
and the first restore should not be during an incident.

1. Take a full backup of production, or of a production-like dataset.
2. Restore it to a **separate** instance or database name — never over
   production.
3. Point a scratch copy of the application at the restored database, with its
   own `.env`.
4. Restore the files into that copy.
5. Run `php artisan migrate:status` — every migration **Ran**.
6. Run `database/scripts/verify-restore.sql` — every check passes.
7. Work through §5.3 in a browser.
8. **Record the wall-clock time** from decision to service. That number, not the
   estimate in §8, is your real RTO.
9. Write down anything that surprised you and fix the procedure.

Repeat after any schema change, a SQL Server upgrade, or a change of hosting.

---

## 11. Backup storage and security

The backups contain the entire business record and every user account.

| Concern | Guidance |
| --- | --- |
| Encryption at rest | Use SQL Server backup encryption or an encrypted volume. A `.bak` is plain by default |
| Off-site copy | At least the monthly fulls. Same-building copies do not survive the building |
| Access | Restrict to the DBA and platform roles; a stolen backup is a stolen database |
| Passwords | Bcrypt-hashed, so not directly readable — but treat them as sensitive anyway |
| Separation | Never store backups on the same volume as the live data files |
| Transport | Encrypt in transit when copying off-site |
| Test data | A restore into a test environment carries **real patient-adjacent stock data** — anonymise or restrict it like production |

---

## 12. Quick reference

```sql
-- Full backup
BACKUP DATABASE PharmaVerify TO DISK = 'D:\Backups\PharmaVerify\FULL.bak'
    WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;

-- Verify it
RESTORE VERIFYONLY FROM DISK = 'D:\Backups\PharmaVerify\FULL.bak' WITH CHECKSUM;

-- Log backup
BACKUP LOG PharmaVerify TO DISK = 'D:\Backups\PharmaVerify\LOG.trn' WITH CHECKSUM;

-- What backups exist?
SELECT database_name, type, backup_start_date, backup_finish_date,
       backup_size / 1048576.0 AS size_mb
FROM msdb.dbo.backupset
WHERE database_name = 'PharmaVerify'
ORDER BY backup_start_date DESC;
```

```bash
# Before a restore
php artisan down

# After a restore
php artisan migrate:status
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan up
```

---

## 13. What has been validated

The scripts are not untested drafts. On **2026-08-26** the whole cycle was run
against **SQL Server 2022**, against the verified PharmaVerify database.

| Step | Result |
| --- | --- |
| `backup-sqlserver.sql` — full backup | 1,010 pages; `RESTORE VERIFYONLY` reported the set valid |
| `backup-sqlserver.sql` — differential | 90 pages |
| `backup-sqlserver.sql` — log backup | 10 pages |
| Compression | 7.96 MB → 0.63 MB |
| Backup history query | Returned all three sets with sizes and durations |
| `restore-sqlserver.sql` — `FILELISTONLY` | Logical names `PharmaVerify` / `PharmaVerify_log`, as the `MOVE` clause assumes |
| Restore chain — full → differential → log, with `MOVE` | All three applied; database recovered |
| Orphaned-login query | Ran correctly; no orphans on a same-server restore |
| `verify-restore.sql` on the restored copy | **All 11 checks PASS**, 6 INFO rows |
| Source vs restored, all 30 tables | Row counts identical in every table |
| Forced failure (a table removed from the expected list) | Correctly reported `FAIL` and named the missing table |
| Non-default collation (`Latin1_General_CI_AS`) | No collation conflict |
| `Backup-PharmaVerify.ps1` | Parses clean under PowerShell 7; comment-based help resolves |

The restore was taken into a **separate database**, never over the source, which
is the practice §10 asks for.

### What this does not prove

- **The timings.** The dataset was small and the restore took seconds. §8's
  two-hour RTO remains an estimate until the drill runs on real data and real
  hardware — that measurement is step 8 of §10.
- **The Windows file-restore step.** The `robocopy` commands were not executed;
  the validation ran in a Linux container, where the paths differ.
- **The client's environment.** Collation, an existing security policy, backup
  device permissions or a non-default schema could still differ. §10 exists for
  exactly that reason.
- **The PowerShell wrapper end to end.** It parses, but `sqlcmd` and `robocopy`
  were not driven through it on a Windows host.

---

## 14. Development and test databases

Only **SQL Server** carries production data and needs this procedure.

MySQL and SQLite are development and test engines. They hold nothing that cannot
be recreated with `php artisan migrate:fresh --seed`, and **must not** be backed
up as though they were production. See `04-DATABASE-DESIGN.md`.
