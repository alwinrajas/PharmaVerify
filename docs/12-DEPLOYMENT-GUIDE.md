# 12 — Deployment Guide

No real secret appears in this document. Every credential is written as a
placeholder such as `YOUR_DATABASE_PASSWORD`.

---

## Deployment profile

**The first client deployment is a single on-premises PC, single user.** Not a
server, not a cloud instance: one machine running the database, the API and the
frontend together, used by one person.

That is a supported way to run PharmaVerify and needs no change to the
application. It does change which parts of this guide apply, and it changes the
risk profile — so the differences are set out here rather than left implicit.

| | Single-PC deployment | Server deployment |
| --- | --- | --- |
| Database | SQL Server **Express** on the same machine is sufficient | Separate instance or server |
| Web server | IIS on Windows 10/11 Pro | IIS, Nginx or Apache |
| Origin | One machine — **no CORS at all** | May be split across hostnames |
| `TRUSTED_PROXIES` | Not needed — nothing terminates TLS in front | Needed behind a proxy |
| `CACHE_STORE=file` etc. | Correct, and no caveat | Correct only on a single server |
| Backup | **Off-machine copy is essential** — see below | Off-site copy of the fulls |
| Availability | The PC *is* the system | Depends on the topology |

### What a single PC means for risk

Say it plainly rather than discover it later:

- **The machine is a single point of failure.** If it fails, is stolen, or is
  reinstalled, the entire business record goes with it. There is no second
  server, no replica and no redundant disk unless the client provides one.
- **Backups must leave the machine.** A backup written to the same disk is lost
  in exactly the scenario it exists for. This is the single most important item
  in this profile.
- **Physical security is now part of the security model.** The whole stock
  history sits on one desk machine. Full-disk encryption and a locked screen
  matter here in a way they would not in a server room.
- **The PC must be running** whenever anything needs to reach it — which matters
  only if handheld terminals submit counts to it. See the open question below.

### On SQL Server Express

Express is free and adequate here. Two of its limits are worth knowing:

- **10 GB maximum per database.** For scale: a database holding the full
  demonstration dataset is about 16 MB. Growth comes almost entirely from
  `audit_lines`, one row per counted line per audit, so the ceiling is a
  function of how often counts run and for how many years. It is a real horizon
  rather than an immediate constraint — check the database size annually.
- **No SQL Server Agent.** Express cannot schedule its own backup jobs, so
  scheduling must come from **Windows Task Scheduler** driving the supplied
  `Backup-PharmaVerify.ps1`. That wrapper was written for exactly this.

Standard or Developer edition removes both limits if the client prefers.

> **CLIENT DECISION REQUIRED — do the handheld terminals submit to this PC?**
>
> This is the one question that materially changes the setup, and it is not ours
> to answer.
>
> - **If yes**, the PC must be reachable from the counting area on the local
>   network, must be running whenever counts are submitted, needs an inbound
>   firewall rule, and needs HTTPS with a certificate the devices will trust —
>   see §5.
> - **If no** — a genuinely standalone install where counts arrive another way,
>   or the in-app simulator is used — then no inbound access is required at all
>   and the application can be reached at `localhost`.
>
> Outbound HTTPS is still required either way if OneDrive sharing is used.

---

## 1. Server requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.2 or later — `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`, `gd` |
| Composer | 2.x |
| Node.js | 20 or later (build only; not needed at runtime, and not needed on the PC at all if a built frontend is supplied) |
| Database | Microsoft SQL Server 2017 or later — **Express is sufficient for the single-PC profile** |
| SQL Server driver | Microsoft ODBC Driver 18 **plus** the PHP `sqlsrv` and `pdo_sqlsrv` extensions |
| Web server | IIS, Nginx or Apache with HTTPS |
| Outbound network | `login.microsoftonline.com` and `graph.microsoft.com` on 443, for OneDrive |

### Installing the SQL Server driver

The PHP extension build must match the PHP build exactly — version, architecture
and thread safety. Check with:

```bash
php -i | findstr /C:"PHP Version" /C:"Thread Safety" /C:"Architecture" /C:"extension_dir"
```

For PHP 8.2, x64, thread-safe:

1. Install **Microsoft ODBC Driver 18 for SQL Server**.
2. Download the Microsoft Drivers for PHP for SQL Server and copy
   `php_sqlsrv_82_ts_x64.dll` and `php_pdo_sqlsrv_82_ts_x64.dll` into the
   `extension_dir` reported above.
3. Add to `php.ini`:

   ```ini
   extension=php_sqlsrv_82_ts_x64.dll
   extension=php_pdo_sqlsrv_82_ts_x64.dll
   ```

4. Confirm: `php -m` lists `sqlsrv` and `pdo_sqlsrv`.

Use `_nts_` builds instead of `_ts_` where PHP reports Thread Safety as disabled.

---

## 2. Database

```sql
CREATE DATABASE PharmaVerify;
GO
CREATE LOGIN pharmaverify_app WITH PASSWORD = 'YOUR_DATABASE_PASSWORD';
GO
USE PharmaVerify;
CREATE USER pharmaverify_app FOR LOGIN pharmaverify_app;
ALTER ROLE db_owner ADD MEMBER pharmaverify_app;
GO
```

`db_owner` is needed while migrations run. It may be reduced afterwards to
`db_datareader` + `db_datawriter` + execute, provided migrations are then run by
a separate account.

Two ways to create the schema:

- **Migrations (preferred):** `php artisan migrate --force`
- **Script:** apply `database/sql/schema-sqlserver.sql`, generated from the same
  migrations. Regenerate with `php artisan pharmaverify:sqlsrv-schema`.

---

## 3. Backend deployment

```bash
cd backend

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
# edit .env — see the block below

php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=AppSettingSeeder --force

php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Do **not** run `DatabaseSeeder` in production — it loads demonstration shops,
items and audits. Seed only roles and settings, then create the first
administrator:

```bash
php artisan tinker
>>> $u = App\Models\User::create(['name' => 'System Administrator', 'email' => 'admin@yourcompany.com', 'password' => 'YOUR_INITIAL_PASSWORD', 'status' => 'active']);
>>> $u->assignRole(App\Support\Roles::ADMINISTRATOR);
```

Change that password at first sign-in.

### `.env` for production

```dotenv
APP_NAME=PharmaVerify
APP_ENV=production
APP_KEY=                       # php artisan key:generate
APP_DEBUG=false
APP_URL=https://pharmaverify.yourcompany.com
FRONTEND_URL=https://pharmaverify.yourcompany.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=sqlsrv
DB_HOST=YOUR_SQLSERVER_HOST
DB_PORT=1433
DB_DATABASE=PharmaVerify
DB_USERNAME=YOUR_DATABASE_USER
DB_PASSWORD=YOUR_DATABASE_PASSWORD
DB_TRUST_SERVER_CERTIFICATE=true      # only with a self-signed certificate
DB_ENCRYPT=yes

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

ONEDRIVE_DRIVER=graph
ONEDRIVE_TENANT_ID=YOUR_TENANT_ID
ONEDRIVE_CLIENT_ID=YOUR_CLIENT_ID
ONEDRIVE_CLIENT_SECRET=YOUR_CLIENT_SECRET
ONEDRIVE_DRIVE_ID=YOUR_DRIVE_ID
ONEDRIVE_FOLDER="PharmaVerify/FinalOutput"
```

`APP_DEBUG=false` is not optional: with it enabled, error responses carry
technical detail.

### Storage permissions

```bash
chmod -R 775 storage bootstrap/cache      # Linux
```

On Windows, grant the application pool identity Modify on `storage` and
`bootstrap/cache`.

These must be writable: `storage/app/private/imports`,
`storage/app/private/final-output`, `storage/framework/*`, `storage/logs`. The
demo OneDrive driver also writes `storage/app/private/onedrive-demo` — not used
in production with the `graph` driver.

**Note the `private` segment.** Laravel's `local` disk is rooted at
`storage/app/private`, so `storage/app` on its own holds nothing. Backup jobs
and permission scripts that target `storage/app/final-output` silently do
nothing — see `18-BACKUP-AND-RECOVERY.md`.

---

## 4. Frontend deployment

**In production, nothing is built.** The release package ships `frontend-dist/`
already compiled — serve it as static files and route `/api` to Laravel. Node.js
is not required on the target machine.

The build below runs on our machine when a release is cut, or on a developer
machine working from the source package:

```bash
cd frontend
npm ci
npm run build       # produces frontend/dist
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name pharmaverify.yourcompany.com;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    root /var/www/pharmaverify/frontend/dist;
    index index.html;
    client_max_body_size 110M;         # Stock Report files up to 100 MB

    # Single-page application: unknown paths return index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        alias /var/www/pharmaverify/backend/public;
        try_files $uri @laravel;
    }

    location @laravel {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/pharmaverify/backend/public/index.php;
        fastcgi_read_timeout 600;      # a large Stock Report takes minutes
    }
}

server {
    listen 80;
    server_name pharmaverify.yourcompany.com;
    return 301 https://$host$request_uri;
}
```

### IIS

1. Site root → `frontend/dist`; enable URL Rewrite to serve `index.html` for
   unmatched paths.
2. Application under `/api` → `backend/public` with the PHP FastCGI handler.
3. Request Filtering → maximum allowed content length ≥ 115343360 (110 MB).
4. `upload_max_filesize = 100M`, `post_max_size = 100M`,
   `max_execution_time = 600`, `memory_limit = 512M` in `php.ini`.
   The Stock Report import lifts its own execution limit, but the web server's
   own timeout still applies — see `17-STOCK-REPORT-IMPORT.md` §5.

Serving the frontend and the API from one origin avoids CORS entirely. Split
across origins, set `FRONTEND_URL` to the frontend origin — `config/cors.php`
reads it.

---

## 5. HTTPS

Required wherever traffic crosses a network. Tokens travel in the
`Authorization` header, and the HHT devices post completed counts. Redirect HTTP
to HTTPS; if Laravel sits behind a proxy that terminates TLS, configure
`TrustProxies` so generated URLs use `https`.

### On the single-PC profile

What is needed depends on the open question in **Deployment profile** above.

| Situation | What HTTPS needs to be |
| --- | --- |
| Devices submit over the local network | **Full HTTPS.** A certificate the devices will trust — either from the client's internal certificate authority, or a public certificate for a hostname that resolves on the LAN. A self-signed certificate will be rejected by the devices unless it is installed on each of them |
| Browser only, on the same machine | Traffic never leaves the PC. `http://localhost` is acceptable, and a self-signed certificate is fine if HTTPS is wanted anyway |

`TRUSTED_PROXIES` is **not** required on this profile — nothing terminates TLS
in front of the application.

Note that `Strict-Transport-Security` is only sent over HTTPS, so on a plain
`localhost` install it simply does not appear. That is correct behaviour, not a
misconfiguration.

### Response security headers

Every response carries `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY` and `Referrer-Policy: no-referrer`. API responses also
carry a `Content-Security-Policy` refusing every source, which is correct because
the API returns JSON and files and never markup.

`Strict-Transport-Security` is sent **only over HTTPS**, since it means nothing
on a plain connection.

> **Behind a TLS-terminating proxy — IIS, Nginx, a load balancer — set
> `TRUSTED_PROXIES`.** Without it the application cannot tell that the original
> request was HTTPS, and HSTS is silently never sent. This is the one setting
> that fails quietly rather than loudly.

```dotenv
TRUSTED_PROXIES="10.0.0.4"          # or "*" behind a single known proxy
SECURITY_HSTS_ENABLED=true
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=true
```

### Cross-origin access

Allowed origins come from configuration alone — no origin is built in.

```dotenv
CORS_ALLOWED_ORIGINS="https://pharmaverify.example.com"
```

Use a comma-separated list for more than one. If neither `CORS_ALLOWED_ORIGINS`
nor `FRONTEND_URL` is set, no cross-origin request is allowed, which is the
right way for this to fail. Development machines reach the API because their own
`.env` names `http://localhost:5173`, not because localhost is permanently
allowed everywhere.

### Access token lifetime

**Tokens do not expire by default**, which is the behaviour to date. Two windows
can be set independently:

```dotenv
# AUTH_TOKEN_WEB_EXPIRY_MINUTES=
# AUTH_TOKEN_DEVICE_EXPIRY_MINUTES=
```

They are separate because the callers are not alike: a browser signs in again in
seconds, whereas a handheld terminal that expires part way through a stock take
interrupts a count in progress.

> **Neither window has been set, and the durations are an open decision — D-09.**
> Leaving them unset means a token on a lost device stays valid until an
> administrator revokes it. Agree the device window with whoever runs the counts
> before enabling it.

---

## 6. OneDrive configuration

See `09-ONEDRIVE-INTEGRATION.md` for the Azure app registration. In short:
register the application, grant `Files.ReadWrite.All` as an **application**
permission with admin consent, put the tenant, client and secret in `.env`, set
`ONEDRIVE_DRIVER=graph`, and identify the destination drive.

Verify at Settings → Integrations: driver `graph`, credentials configured.

---

## 7. Production checklist

| # | Item |
| --- | --- |
| 1 | `APP_ENV=production` and `APP_DEBUG=false` |
| 2 | `APP_KEY` generated and kept safe — losing it invalidates encrypted values |
| 3 | Database credentials are a dedicated account, not `sa` |
| 4 | `php artisan migrate --force` completed without error |
| 5 | Roles and settings seeded; demonstration data **not** seeded |
| 6 | First administrator created and their password changed |
| 7 | `config:cache`, `route:cache`, `event:cache` run |
| 8 | `storage` and `bootstrap/cache` writable |
| 9 | Frontend built and `dist` served |
| 10 | HTTPS enforced, HTTP redirected |
| 11 | Upload limits ≥ 25 MB on the web server and in PHP |
| 12 | `FRONTEND_URL` correct so CORS is not wide open |
| 13 | OneDrive credentials in place, or `demo` deliberately chosen |
| 14 | Outbound access to Microsoft Graph confirmed |
| 15 | Log rotation configured for `storage/logs` |
| 16 | Database in the **FULL** recovery model — required for point-in-time restore |
| 17 | Full, differential and log backups scheduled — see §8 |
| 18 | `storage/app/private/final-output` included in the file backup |
| 19 | Backup failure raises an alert — a silently failing job is worse than none |
| 20 | **Restore drill completed** and the measured recovery time recorded |
| 21 | `.env` copied to a secrets store, held separately from the backups |
| 22 | `CORS_ALLOWED_ORIGINS` set to the real frontend origin — **server profile only**; not needed on a single PC, where there is one origin |
| 23 | `TRUSTED_PROXIES` set if TLS terminates at a proxy — **server profile only**; nothing terminates TLS in front of a single-PC install |
| 24 | Token expiry windows decided and set, or the decision consciously deferred — D-09 |
| 25 | HHT device accounts created, one per device |
| 26 | Sign-in verified for each role |
| 27 | A test import, submission, adjustment and OneDrive share performed on the live environment |

### Single-PC profile — additional items

| # | Item |
| --- | --- |
| 28 | Confirmed whether handheld terminals submit to this PC — this drives 29 to 31 |
| 29 | If devices submit: inbound firewall rule, and a certificate the devices trust |
| 30 | If devices submit: the PC stays powered on and awake during counting hours |
| 31 | If devices submit: the PC has a stable address on the network |
| 32 | Backups scheduled through **Windows Task Scheduler** — Express has no SQL Server Agent |
| 33 | **Backup copies leave the machine.** A backup on the same disk protects against nothing |
| 34 | Full-disk encryption enabled, and the screen locks — the whole stock record is on this PC |
| 35 | Disk has room for the database, the generated files and the local backups |
| 36 | Database size checked against the Express 10 GB ceiling, and a date set to check it again |

---

## 8. Backup and recovery

Full procedure, including RPO, RTO, retention and the restore drill:
**`18-BACKUP-AND-RECOVERY.md`**. The essentials:

**PharmaVerify does not back itself up.** It ships no scheduler, no backup
agent and no retention policy. Everything here is run by the hosting
infrastructure.

> **On the single-PC profile this section matters more, not less.** There is no
> second machine to fall back to, so a backup that never leaves the PC protects
> against nothing that is likely to happen to it. Schedule
> `Backup-PharmaVerify.ps1` through **Windows Task Scheduler** — SQL Server
> Express has no Agent of its own — and copy the result somewhere off the
> machine: a network share, an external disk that is not left permanently
> connected, or the client's own backup system.

Three things need backing up:

| What | Where | Recoverable otherwise? |
| --- | --- | --- |
| The database | SQL Server | **No** — counts, verifications and adjustments exist nowhere else |
| Generated final outputs | `storage/app/private/final-output/` | Regenerable from the audit, but not if it was the evidence handed over |
| `backend/.env` | On the server | **No** — holds `APP_KEY` and every credential |

Set the database to `FULL` recovery, then take daily full backups, six-hourly
differentials and **log backups every 15 minutes** — that interval is the
recovery point objective.

| Target | Value |
| --- | --- |
| RPO | 15 minutes |
| RTO | 2 hours |

Scripts are in [`database/scripts/`](../database/scripts/):
`backup-sqlserver.sql`, `restore-sqlserver.sql`, `verify-restore.sql` and
`Backup-PharmaVerify.ps1` for Task Scheduler.

After any restore, run `verify-restore.sql`, then `php artisan migrate:status`,
then the application checks in `18-BACKUP-AND-RECOVERY.md` §5.3. A pending
migration means the backup predates a deployment.

**Run the restore drill before go-live.** A backup that has never been restored
is untested, and the measured time is the only RTO worth quoting.

---

## 9. Upgrading

```bash
cd backend
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan up

cd ../frontend
npm ci && npm run build
```

Back up the database before every upgrade that includes migrations.

---

## 10. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| “could not find driver” | `pdo_sqlsrv` not enabled, or the wrong TS/NTS or architecture build |
| Sign-in works, every other call is 401 | The `Authorization` header is being stripped by the web server |
| Import fails on a large file | `upload_max_filesize`, `post_max_size` or the web server's request limit |
| Import dies with "Allowed memory size exhausted" | `memory_limit` below 512M. The reader chunks, but the process still needs headroom |
| Import returns 504 | The web server's read timeout is below the time a large Stock Report needs |
| Export times out | Raise `fastcgi_read_timeout` and `max_execution_time`, or narrow the report's filters |
| OneDrive: “has not been configured yet” | `ONEDRIVE_DRIVER=graph` without credentials, or `config:cache` not re-run after editing `.env` |
| Blank page after deployment | The frontend build was not copied, or the SPA rewrite rule is missing |
| Settings changes have no effect | `php artisan config:clear` then `config:cache` |
