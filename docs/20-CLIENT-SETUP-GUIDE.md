# 20 — Client Setup Guide

Everything needed to take PharmaVerify from a clean Windows PC to a pharmacy
counting stock on handheld terminals: server, database, web application,
network, and the handhelds themselves.

This guide covers a **site installation**. For hosting under IIS or Nginx,
HTTPS, security headers and token lifetimes, see
[`12-DEPLOYMENT-GUIDE.md`](12-DEPLOYMENT-GUIDE.md), which this document points
at rather than repeats.

---

## Before you start

PharmaVerify runs on an ordinary Windows PC in the pharmacy back office. There
is no dedicated server requirement — but that PC becomes the system of record
for stock counts, so treat it accordingly.

### What you need on site

| Item | Requirement |
|---|---|
| Windows PC | Windows 10/11 or Server. Stays powered on during counting hours. Reachable on the store network. |
| PHP | `8.2` or later, with `pdo_mysql` (or `pdo_sqlsrv`), `mbstring`, `openssl`, `zip`, `gd`, `fileinfo` |
| Composer | 2.x |
| Node.js | 20 LTS or later, to build the web application once |
| Database | MySQL 8 **or** SQL Server 2019+ / Express |
| Handhelds | Android 7.0 (API 24) or later, on the same Wi-Fi as the PC |
| Store Wi-Fi | Client isolation **off** — handhelds must be able to reach the PC |

> ### ⛔ Read Phase 6 first
>
> The handheld app ships with a fixed list of server addresses it is permitted
> to contact over plain HTTP. **A client PC on any other address will be
> refused by the phone before a single request is sent**, and changing the
> server address inside the app does not fix it. Phase 6 explains the three
> ways round this. Decide which one you are using *before* you install
> anything.

---

## Phase 1 — Prepare the PC

Install the runtimes and confirm each one answers before moving on. Every later
phase assumes these work.

```powershell
# Run each and confirm a version prints
php -v          # expect 8.2 or later
composer -V     # expect 2.x
node -v         # expect v20 or later
```

If `php` is not recognised, add the PHP folder to the system `PATH`. Then
confirm the extensions are enabled:

```powershell
php -m
```

Look for `pdo_mysql` (or `pdo_sqlsrv`), `mbstring`, `openssl`, `zip`, `gd` and
`fileinfo`. Any that are missing are enabled by uncommenting the matching
`extension=` line in `php.ini` and restarting the terminal.

> **⚠ SQL Server only.** The `pdo_sqlsrv` driver is not bundled with PHP.
> Download the Microsoft Drivers for PHP for SQL Server matching your exact PHP
> version, thread-safety and architecture, place the DLLs in the PHP `ext`
> folder, and add the `extension=` lines. A mismatch on any of those three
> fails silently — the extension simply never loads.

---

## Phase 2 — Create the database

One empty database and one account that owns it. PharmaVerify creates every
table itself.

```sql
-- MySQL
CREATE DATABASE pharmaverify
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'pharmaverify'@'localhost'
  IDENTIFIED BY 'choose-a-strong-password';

GRANT ALL PRIVILEGES ON pharmaverify.* TO 'pharmaverify'@'localhost';
FLUSH PRIVILEGES;
```

On SQL Server, create a database named `pharmaverify` and a login with
`db_owner` on it. The schema is written to work on both engines; nothing
engine-specific needs changing.

---

## Phase 3 — Install the backend

This creates the schema, the roles and permissions, and the first administrator
account.

```powershell
cd backend

composer install --no-dev --optimize-autoloader

copy .env.example .env
php artisan key:generate
```

### Edit `.env`

Set at minimum the following. `APP_DEBUG` must be `false` on a client machine —
with it on, an error page will show database credentials and file paths to
whoever is standing at the PC.

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://PC-IP-OR-HOSTNAME:8000

DB_CONNECTION=mysql          # or sqlsrv
DB_HOST=127.0.0.1
DB_PORT=3306                 # 1433 for SQL Server
DB_DATABASE=pharmaverify
DB_USERNAME=pharmaverify
DB_PASSWORD=the-password-you-chose

FRONTEND_URL=http://PC-IP-OR-HOSTNAME:8000
CORS_ALLOWED_ORIGINS="${FRONTEND_URL}"
```

### Build the schema

```powershell
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=UserSeeder --force
php artisan db:seed --class=AppSettingSeeder --force

php artisan config:cache
php artisan route:cache
```

> **⚠ Change the seeded password immediately.** The seeder creates an
> administrator: `admin@pharmaverify.com` / `Pharma@2026`. These are published
> in this document and in the source repository. Sign in, change the password,
> and create named accounts for the real staff before the system holds anything
> that matters.

> **✓ Demo data.** Do **not** run `DemoAuditSeeder` on a client machine. It
> writes sample shops, audits and variances that are indistinguishable from
> real counts once staff start using the system.

---

## Phase 4 — Build the web application

The front end compiles to static files. Node is needed for the build only, not
to run the system.

```powershell
cd frontend
npm ci
npm run build
```

This produces `frontend/dist`. For the single-PC profile the simplest
arrangement is to let the backend serve it: copy the contents of `dist` into
`backend/public`. For a larger site, serve `dist` from IIS or Nginx and point it
at the backend — see [`12-DEPLOYMENT-GUIDE.md`](12-DEPLOYMENT-GUIDE.md), which
covers both web servers in detail.

---

## Phase 5 — Put it on the store network

Three things stop handhelds reaching the PC, and all three are silent. Do them
in order and test after each.

### 1. Give the PC a fixed address

Reserve the PC's IP on the router by MAC address, or set a static address. If it
moves, every handheld stops working until they are reconfigured — and on a DHCP
lease that can happen overnight with nobody touching anything.

### 2. Bind to the network, not just the PC

By default the development server listens only on `127.0.0.1`, which no other
machine can reach. It must listen on all interfaces:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Confirm it is bound correctly — you want `0.0.0.0:8000`, not `127.0.0.1:8000`:

```powershell
netstat -ano | findstr :8000
```

> **⚠ This is a development server.** `artisan serve` is single-threaded and
> does not restart itself. It is fine for evaluation and for a single shop
> counting on one or two handhelds. For daily production use, host the
> application under IIS or Nginx with PHP-FPM so it survives reboots and handles
> concurrent devices — see [`12-DEPLOYMENT-GUIDE.md`](12-DEPLOYMENT-GUIDE.md).

### 3. Open the port in Windows Firewall

Run in an elevated PowerShell. `-Profile Private` keeps the rule off public
networks:

```powershell
New-NetFirewallRule -DisplayName "PharmaVerify 8000" `
  -Direction Inbound -Protocol TCP -LocalPort 8000 `
  -Action Allow -Profile Private
```

### 4. Prove it from the handheld

Open the phone's browser and visit `http://PC-IP:8000`. You should see the
PharmaVerify sign-in page. **Do not continue until this works** — every later
step depends on it, and testing it in a browser separates a network problem from
an app problem.

If the page does not load, the causes in order of likelihood are: Wi-Fi client
isolation enabled on the router; the firewall rule missing or on the wrong
profile; the server bound to loopback; the phone on a different network (guest
Wi-Fi, or mobile data).

---

## Phase 6 — Set up the handheld terminals

Each terminal is registered in the web application, paired once with a
single-use code, and thereafter submits counts directly over the store network.

> ### ⛔ The cleartext restriction — read before installing the APK
>
> Android refuses plain HTTP by default. The app permits it only for an explicit
> list of addresses compiled into the APK. Today that list is
> `pharmaverify.local`, `192.168.1.42`, `192.168.43.64` and loopback.
>
> If the client's PC is on any other address — which it almost certainly is —
> the handheld will fail with *"Cleartext HTTP traffic to … not permitted"*.
> This happens inside Android before any request leaves the phone, so **setting
> the server address in the app's Settings does not help.** Android's
> configuration matches hostnames only; it does not understand IP ranges, so
> "allow the whole 192.168.x.x network" cannot be expressed.

### Choose one of three remedies

| Option | What to do | Trade-off |
|---|---|---|
| **A · Name the server** *(recommended)* | Make the PC resolvable as `pharmaverify.local` on the store network — a router DNS entry, or a `hosts` entry on each handheld. Enter `http://pharmaverify.local:8000` as the server address. | Already permitted by the shipped APK. No rebuild, and it keeps working if the PC's IP changes. |
| **B · Rebuild the APK** | Add the client's IP to `network_security_config.xml` and build a new debug APK. | Needs a developer and a new APK for every site, and again whenever an address changes. |
| **C · Use HTTPS** *(proper end state)* | Put a certificate the handhelds trust on the PC and serve over `https://`. | The exception disappears entirely. Needs a certificate from the client's internal CA, or a public certificate for a hostname that resolves on the LAN. A self-signed certificate must be installed on every handheld. |

> **✓ Why option A is the one to pick.** It is the only option that survives the
> PC's address changing, needs no developer, and works with the APK you already
> have. Options B and C both remain available later — C is where a permanent
> installation should end up.

### Register and pair each terminal

1. In the web application, open **HHT Devices** and create a device for each
   physical terminal. Give it the code written on the terminal — `HHT-01`,
   `HHT-02` — and assign it to the shop it will count in.
2. Install the APK on the terminal.
3. On the terminal, open **Settings** and enter the server address, exactly as
   chosen above.
4. In the web application, press the **pair** action on that device's row. A
   code is shown **once**; the server keeps only a hash of it.
5. Type the code into the terminal's Settings and press pair.
6. The terminal should show `✓ Paired` and `🟢 Connected to PharmaVerify` with
   the device code, shop, and a last-checked time.

> **⚠ A terminal belongs to one shop.** The pairing binds the terminal to a
> shop, and the server will refuse a count that belongs to a different one. If a
> terminal is moved between branches it must be paired again for the new shop —
> re-pairing revokes its previous token automatically. A terminal that is lost
> or reassigned can be revoked from the **Unpair** action on the devices page.

### What "Paired" and "Connected" each mean

These are separate facts and the terminal reports both. **Paired** is
configuration: this terminal is registered and holds a token. **Connected** is
the network right now. A terminal can be paired but unreachable — which is
normal and safe, because completed counts stay on the terminal and send
themselves when the connection returns.

---

## Phase 7 — Acceptance test

Walk this before handing over. It exercises the whole chain rather than each
piece in isolation.

- [ ] Sign in to the web application from the PC, and again from a second machine on the store network.
- [ ] Import a stock file for one shop. Confirm the row count matches and errors are listed with reasons.
- [ ] Re-import a different file for the same shop — the old rows must be *gone*, not added to. Stock import is a full replacement per shop.
- [ ] On a paired terminal, count a handful of items in that shop and complete the audit.
- [ ] Press **Submit to PharmaVerify**. The card should read *Sending…*, then *Received by PharmaVerify* with a server reference such as `AUD-29082026-0001`.
- [ ] In the web application, open **Audits** — the count should appear on its own within about fifteen seconds, without anyone refreshing, marked **Received** with source **HHT** and the device code.
- [ ] Open the audit and confirm system, physical, loose and variance are correct. Variance is `System − (Physical + Loose)`: positive is short, negative is excess.
- [ ] Press Submit again on the terminal. It must report *already received* with the **same** reference, and no second audit may appear.
- [ ] Turn the terminal's Wi-Fi off, complete another count, and submit. It must say *Saved on this terminal*, not report an error.
- [ ] Turn Wi-Fi back on. The count should send itself and become **Received** with no further action.
- [ ] Confirm exactly one audit exists for that count.
- [ ] Export a report as Excel and as PDF, and share one through the Android share sheet. This is file sharing and is unrelated to submission.
- [ ] Sign in as a shop-level user and confirm only that shop's data is visible.

---

## Troubleshooting

Symptoms seen during real installation, and what each one actually means.

| Symptom | Cause and fix |
|---|---|
| *"Cleartext HTTP traffic to … not permitted"* | The PC's address is not in the APK's permitted list. Phase 6 — use option A. Changing the server address in the app will not help. |
| Browser on the phone cannot open the site | Network, not the app. Check Wi-Fi client isolation, the firewall rule, and that the server is bound to `0.0.0.0`. |
| *"Device pairing is no longer valid"* | The token was revoked, expired, or belongs to a different terminal. Pair again. If the terminal was recently paired elsewhere, that revoked this one. |
| *"This terminal is paired to P001 and cannot file a count for P002"* | Not a pairing fault. The count belongs to another shop. Pair the terminal with that shop, or count on a terminal that belongs to it. |
| *"Your session has expired"* on the terminal | Its token was revoked — most often because the device was paired again elsewhere. Issue a fresh pairing code. |
| *PharmaVerify unavailable*, terminal still says Paired | Correct behaviour. The pairing is fine, the network is not. Counts are safe on the terminal and will send themselves. Check the PC is on and the server is running. |
| Counts submit but nothing appears in the web | Check **HHT Submissions** for the record. If it is there, the audit exists — check the shop and date filters on the Audits page. |
| Server stops after the operator logs out of Windows | `artisan serve` dies with the session. Host under IIS or Nginx for daily use. |

---

## Handover checklist

- [ ] Seeded administrator password changed; named accounts created for real staff.
- [ ] `APP_DEBUG=false` confirmed in `.env`.
- [ ] Demo seeder **not** run on this machine.
- [ ] PC has a reserved or static IP address.
- [ ] Firewall rule present, scoped to the private profile.
- [ ] Server starts automatically, or the client knows how to start it.
- [ ] Every terminal registered, assigned to its shop, and paired.
- [ ] Acceptance test in Phase 7 completed and witnessed.
- [ ] Database backup scheduled and a restore tested — see [`18-BACKUP-AND-RECOVERY.md`](18-BACKUP-AND-RECOVERY.md).
- [ ] Client shown how to issue a pairing code and how to unpair a lost terminal.
- [ ] Client told that stock import *replaces* a shop's stock rather than adding to it.

---

## Known limitations

### The handheld app has not been verified on physical hardware

The submission flow, pairing, connection status and report sharing are covered
by automated tests, and the server side has been verified end to end over a real
network. But no physical terminal was available during development, so none of
the on-device screens have been observed running. Budget time for a first-install
shakedown on real hardware, and expect to find presentation issues rather than
logic ones.

### Cleartext HTTP is a stopgap

Option A in Phase 6 gets a site working today, but the traffic between the
handhelds and the PC is unencrypted — including the pairing exchange. On a
private, isolated store network with no guest access this is a considered
trade-off, not an oversight. A permanent installation should move to HTTPS
(option C).

### Single point of failure

One PC holds the database, the application and the files. If it fails, counting
stops and unbacked-up data is gone. The backup schedule is not optional — it is
the only thing standing between a disk failure and a re-count.

### Older documentation

[`19-HHT-DEVICE-INTEGRATION.md`](19-HHT-DEVICE-INTEGRATION.md) opens by stating
there is no live connection between the handhelds and the server. That was true
when it was written and is now out of date — direct API submission is the
primary path and the Excel bridge is the fallback. Read that document for the
export layouts and numbering rules, not for the integration model.

---

## Companion documents

| Document | Covers |
|---|---|
| [`12-DEPLOYMENT-GUIDE.md`](12-DEPLOYMENT-GUIDE.md) | IIS and Nginx hosting, HTTPS, security headers, token lifetimes |
| [`18-BACKUP-AND-RECOVERY.md`](18-BACKUP-AND-RECOVERY.md) | Backup schedule and restore procedure |
| [`13-USER-GUIDE.md`](13-USER-GUIDE.md) | Day-to-day operation for pharmacy staff |
| [`19-HHT-DEVICE-INTEGRATION.md`](19-HHT-DEVICE-INTEGRATION.md) | Export layouts, reference numbering, Excel fallback |
