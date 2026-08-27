# 12 — Deployment Guide

No real secret appears in this document. Every credential is written as a
placeholder such as `YOUR_DATABASE_PASSWORD`.

---

## 1. Server requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.2 or later — `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`, `gd` |
| Composer | 2.x |
| Node.js | 20 or later (build only; not needed at runtime) |
| Database | Microsoft SQL Server 2017 or later |
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

```bash
cd frontend
npm ci
npm run build       # produces frontend/dist
```

Serve `frontend/dist` as static files and route `/api` to Laravel.

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

Required. Tokens travel in the `Authorization` header, and the HHT devices post
completed counts. Redirect HTTP to HTTPS; if Laravel sits behind a proxy that
terminates TLS, configure `TrustProxies` so generated URLs use `https`.

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
| 22 | HHT device accounts created, one per device |
| 23 | Sign-in verified for each role |
| 24 | A test import, submission, adjustment and OneDrive share performed on the live environment |

---

## 8. Backup and recovery

Full procedure, including RPO, RTO, retention and the restore drill:
**`18-BACKUP-AND-RECOVERY.md`**. The essentials:

**PharmaVerify does not back itself up.** It ships no scheduler, no backup
agent and no retention policy. Everything here is run by the hosting
infrastructure.

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
