# PharmaVerify — Administrator Guide

For IT and support staff. The client-facing walkthrough is
[`INSTALLATION-GUIDE.md`](INSTALLATION-GUIDE.md); problems are in
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

---

## Architecture

```
Handheld terminals ──HTTP──> IIS :8000 ──FastCGI──> PHP 8.3 (NTS) ──> SQL Server Express
                                  │
                                  └── serves the compiled web application
```

**One process, not a family.** The application has no queued jobs and no
scheduled tasks (`QUEUE_CONNECTION=sync`, file sessions, file cache), so there
is no worker to supervise. The web server is the whole runtime.

**IIS rather than a bundled server.** `artisan serve` is single-threaded and
dies with the session that started it, so a pharmacy would lose the system at
logout. Bundling Apache or nginx means shipping and patching a web server on a
machine nobody administers. IIS is already part of Windows, already a service
that starts at boot and restarts on failure, and needs nothing redistributed.

**One origin.** The compiled front end is served from the same site as the API,
and asks for a relative `/api`. It therefore follows whatever address it is
reached on and needs no rebuild per site — changing the PC's address changes
nothing on the web side.

---

## Layout on disk

| Path | Contents | Replaced on upgrade |
|---|---|---|
| `C:\Program Files\PharmaVerify\backend` | Laravel application | Yes |
| `C:\Program Files\PharmaVerify\backend\public` | Compiled web app + `web.config` | Yes |
| `C:\Program Files\PharmaVerify\runtime\php` | PHP 8.3 NTS + SQL Server driver | Yes |
| `C:\Program Files\PharmaVerify\scripts` | Maintenance scripts | Yes |
| `C:\ProgramData\PharmaVerify\config\.env` | Machine configuration | **No** |
| `C:\ProgramData\PharmaVerify\logs` | Installation and PHP logs | **No** |
| `C:\ProgramData\PharmaVerify\backups` | Database backups | **No** |

Mutable data is deliberately outside Program Files: that tree is read-only for
standard users and is replaced wholesale on upgrade, which would take the
configuration, logs and backups with it. `.env` is hard-linked into the
application folder so Laravel finds it where it expects.

---

## Runtime versions, and why

**PHP 8.3, not 8.2.** Microsoft's SQL Server driver 5.13.3 dropped PHP 8.2.
Bundling 8.2 would produce a runtime that starts perfectly and cannot reach the
database. The application requires `^8.2`, so 8.3 satisfies it.

**Non-thread-safe.** IIS uses FastCGI, which runs one request per process. The
thread-safe build is for in-process module SAPIs such as Apache's `mod_php`.

The pairing is enforced in `Get-PhpRuntime.ps1`: if the driver release has no
build for the chosen PHP, it fails rather than staging a runtime that cannot
talk to the database.

---

## Maintenance scripts

All in `C:\Program Files\PharmaVerify\scripts`, all runnable on their own so a
single part can be repaired without reinstalling. Run PowerShell as
administrator.

| Script | Purpose |
|---|---|
| `Test-Health.ps1` | 15 checks; exit 0 = healthy |
| `Backup-Database.ps1` | Timestamped backup, with retention |
| `Set-Firewall.ps1` | Create, correct or `-Remove` the rule |
| `Install-WebServer.ps1` | Reconfigure IIS, or `-Remove` |
| `Initialize-Database.ps1` | Create database/login, apply migrations |
| `Set-Environment.ps1` | Rewrite `.env` (needs `-Force`) |
| `Set-Hostname.ps1` | Re-detect the address, publish the hostname |
| `Install-PharmaVerify.ps1` | All of the above, in order |

```powershell
cd "C:\Program Files\PharmaVerify\scripts"
.\Test-Health.ps1
```

---

## Network

**Firewall.** One inbound rule, TCP 8000, **Private profile only**. The rule is
idempotent: an existing correct rule is left alone, an incorrect one is
corrected in place rather than duplicated.

> **The commonest silent fault.** A rule scoped to Private does nothing on a
> network Windows has classified as **Public**. The PC keeps working perfectly
> and no handheld can reach it, with nothing reporting a problem. `Test-Health`
> checks for this explicitly. Fix it in Windows network settings, not by
> widening the rule.

**Address.** `Set-Hostname.ps1` picks the interface Windows would actually route
through, rather than enumerating adapters — a PC with Hyper-V, WSL, a VPN or a
tethered phone has several plausible private addresses and only one is the one
the handhelds share.

**Hostname.** `pharmaverify.local` is preferred because the handheld app permits
it regardless of IP, so it survives DHCP changes and needs no new APK. The
installer writes `127.0.0.1  pharmaverify.local` to the hosts file — loopback,
**not** the LAN IP. A LAN-IP entry goes stale the moment DHCP moves this PC and
then silently points the browser at whatever machine picked up the old
address; 127.0.0.1 can never go stale, because it always means "this
machine". Before the first write, the existing hosts file is backed up to
`hosts.pharmaverify.backup` (only the first backup is kept); any earlier or
duplicate PharmaVerify line is removed in the same pass, the DNS cache is
flushed, and the result is verified by actually resolving the name rather than
trusting the write.

The hosts file only ever affects this PC — **it does nothing for the
handhelds**, which need the name to resolve on the store network. Either add a
DNS entry on the router pointing `pharmaverify.local` at this PC's reserved IP,
or set the handhelds to the LAN IP directly, which the installer detects and
displays and which is also permitted.

---

## Moving to HTTPS

The handheld permits `pharmaverify.local` over either scheme, so this needs no
new APK. Written to `C:\ProgramData\PharmaVerify\HTTPS-SETUP.txt` at install
time; in short:

1. Obtain a certificate for the hostname that the handhelds trust — from the
   client's internal CA, or public for a name that resolves on the LAN. A
   self-signed certificate must be installed on every handheld.
2. `New-WebBinding -Name PharmaVerify -Protocol https -Port 443`, then assign it.
3. Update `APP_URL`, `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` in `.env`.
4. Change the server address on each handheld.

Until then traffic is unencrypted, including the pairing exchange. On an
isolated store network that is a considered trade-off, not an oversight.

---

## Backups

```powershell
.\Backup-Database.ps1 -Label nightly -RetentionDays 30
```

Reads the connection from the application's own `.env`, so a backup can never
be taken from a different database than the one in use. Uses Windows
authentication (`sqlcmd -E`) so no password reaches the process list.

Two safeguards worth knowing: a backup under 50 KB is flagged, because that
usually means an empty or wrong database; and **the newest backup is never
deleted by retention**, so a PC switched off for two months does not come back
and clean away the only copy it has.

**Restore** (deliberately manual — it destroys current data):

```powershell
sqlcmd -S .\SQLEXPRESS -E -Q "ALTER DATABASE pharmaverify SET SINGLE_USER WITH ROLLBACK IMMEDIATE"
sqlcmd -S .\SQLEXPRESS -E -Q "RESTORE DATABASE pharmaverify FROM DISK='C:\ProgramData\PharmaVerify\backups\<file>.bak' WITH REPLACE"
sqlcmd -S .\SQLEXPRESS -E -Q "ALTER DATABASE pharmaverify SET MULTI_USER"
```

Schedule it with Task Scheduler, running as SYSTEM, daily.

---

## Upgrading

Run the new installer. It detects the existing installation and:

- keeps the database — migrations only add what is missing
- keeps `.env`, including the application key (changing it would sign everyone
  out and make existing encrypted values unreadable)
- replaces application files and the runtime
- rebuilds caches, restarts the site, runs the health check

**Take a backup first** — `Backup-Database.ps1 -Label before-upgrade`. The
installer does not force one, and a migration that goes wrong on a database
with no backup is unrecoverable.

Seeders run **only into a database with no users**, so an upgrade never touches
live accounts.

---

## Uninstalling

Removes application files, the IIS site and pool, the firewall rule, shortcuts.

**Keeps the database, `.env`, logs and backups.** A reinstall finds them again.
Removing the data is a separate deliberate act:

```powershell
sqlcmd -S .\SQLEXPRESS -E -Q "DROP DATABASE pharmaverify"
Remove-Item 'C:\ProgramData\PharmaVerify' -Recurse
```

---

## Security notes

- `APP_DEBUG=false`; errors are logged, never displayed.
- `.env` is ACL'd to Administrators, SYSTEM and IIS_IUSRS.
- The pool identity gets write access to `storage` and `bootstrap/cache` only.
- `web.config` hides `.env` and `storage` from the web, and strips
  `X-Powered-By`.
- Installation logs record that secrets were set, never their values.
- The database login has `db_owner` on its own database and no server-level
  rights.
- A device may submit only for the shop it is paired to; the server enforces
  this and returns a `shop_mismatch` code rather than a bare 403.

---

## Building a release

On a build machine with PHP, Composer, Node and Inno Setup 6:

```powershell
installer\scripts\Get-PhpRuntime.ps1          # once per runtime version
build\Build-Release.ps1 -Version 1.0.0
```

The pipeline runs backend tests, TypeScript checks and front-end tests, builds
the front end, installs production-only dependencies, assembles and verifies the
payload, then compiles the installer. It refuses to produce an artifact if the
tests fail, if a `.env` reached the payload, or if dev dependencies are present.

Stop any Vite dev server first — `npm ci` deletes `node_modules` and cannot
while one holds it open. The script detects this and names the process.
