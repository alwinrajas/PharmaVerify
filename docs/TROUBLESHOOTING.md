# PharmaVerify — Troubleshooting

Start here:

```
Start menu → PharmaVerify → PharmaVerify Health
```

It runs fifteen checks and names what is wrong. Most of what follows is the
detail behind a check that failed.

Logs live in `C:\ProgramData\PharmaVerify\logs\` — `install.log` for the
installation, `php-error.log` for the application.

---

## Installation

### "SQL Server was not found"

PharmaVerify does not install the database. Install **SQL Server Express**
(free), accept the defaults, then run the installer again.

Confirm afterwards:

```powershell
Get-Service MSSQL* | Select-Object Name, Status
```

### The installer needs administrator rights

Right-click the installer and choose **Run as administrator**. It configures the
firewall, IIS and a service, none of which is possible otherwise.

### "Please use a password of at least 12 characters"

SQL Server's own policy would reject a weak password part way through, by which
time the wizard has been dismissed. It is checked up front instead. Nobody has
to type this password again.

### Installation finished but said "attention required"

The files and database are in place; something else needs fixing. The health
report above the message names it. **Do not reinstall** — that undoes work that
is fine. Fix the named item and run `Test-Health.ps1` again.

---

## The web application

### Only the front page loads; everything else is 404

The **IIS URL Rewrite module** is missing. It is a separate Microsoft download,
not part of Windows. Without it the rewrite rule is ignored and every route
except `/` returns 404, with IIS otherwise looking healthy.

Install from <https://www.iis.net/downloads/microsoft/url-rewrite>, then:

```powershell
.\Install-WebServer.ps1
```

### Every PHP page returns 404, static files work

The **CGI** IIS feature is not enabled. Re-run `Install-WebServer.ps1`, which
enables it.

### "Database connection failed"

```powershell
.\Test-Health.ps1
```

The Database line quotes the driver's own words — *login failed*, *unknown
database*, *server not found* — which name the cause. Check the SQL Server
service is running, and that `.env` names the right instance.

> If a health check reports the database as unreachable while the application
> plainly works, that was a bug in an earlier version: it used `artisan db:show`,
> which queries `performance_schema` and fails on some MySQL and MariaDB builds
> for reasons unrelated to connectivity. Current versions probe the connection
> directly.

### pharmaverify.local does not open on the PC

Either the hosts entry is missing, or something is blocking it.

Run `Set-Hostname.ps1` as administrator from the scripts folder — it backs up
the hosts file first, writes `127.0.0.1  pharmaverify.local`, flushes the DNS
cache and confirms the name actually resolves:

```powershell
.\Set-Hostname.ps1
```

If it still does not resolve afterwards, antivirus or endpoint-protection
software blocking hosts-file edits is the next suspect — check its logs for a
blocked write to `System32\drivers\etc\hosts`.

Verify with:

```powershell
ping pharmaverify.local
```

which should answer from `127.0.0.1`. This name working on the PC says nothing
about the handhelds — they resolve names through the router, not through this
PC's hosts file, so fixing this does not by itself fix a handheld that cannot
reach PharmaVerify.

### The site stops when the operator logs out

That is `artisan serve`, not IIS. A correct installation runs under IIS and
survives logout and reboot. Run `Install-WebServer.ps1`.

---

## Handheld terminals

### "Cleartext HTTP traffic to … not permitted"

The PC's address is not in the list the app permits. **Changing the server
address in the app's Settings does not help** — Android decides this before any
request leaves the phone, and it matches names only, so "allow 192.168.x.x"
cannot be expressed.

Use the hostname instead. Set the server address to
`http://pharmaverify.local:8000` and add a DNS entry on the router pointing that
name at the PC.

### The phone's browser cannot open the site

Network, not the app. In order of likelihood:

1. **Wi-Fi client isolation** is on — the router is preventing devices from
   talking to each other.
2. **The network profile is Public.** The firewall rule is scoped to Private and
   does nothing on a Public network. The PC works, no handheld can reach it, and
   nothing reports a fault. Change it in Windows network settings.
3. The firewall rule is missing — run `Set-Firewall.ps1`.
4. The phone is on guest Wi-Fi or mobile data.

Test the browser before anything else: it separates a network problem from an
app problem.

### "Device pairing is no longer valid"

The token was revoked, expired, or belongs to a different terminal. Issue a new
pairing code from **HHT Devices**.

The usual cause is that the terminal was paired again elsewhere — re-pairing
revokes the previous token by design.

### "This terminal is paired to P001 and cannot file a count for P002"

**Not a pairing fault.** The pairing is fine; the count belongs to another shop.
A terminal may only file for the shop it is paired to.

Either pair the terminal with that shop, or count on a terminal that belongs to
it. Re-pairing for the same shop will not help.

### "PharmaVerify unavailable" but the terminal says Paired

Correct behaviour, and nothing is lost. The pairing is good and the network is
not. Counts stay on the terminal and send themselves when it reconnects.

Check the PC is switched on and the site is running.

### A count was submitted but does not appear on the PC

Check **HHT Submissions** in the web application. If the submission is listed,
the audit exists — check the shop and date filters on the Audits page.

The Audits page refreshes itself about every fifteen seconds. If it never
updates, the browser has lost its connection to the PC; reload the page.

---

## Backups

### "The backup is only 0.0 MB"

Usually an empty or wrong database. Check `DB_DATABASE` in
`C:\ProgramData\PharmaVerify\config\.env` names the database in use.

### Backup fails with "Operating system error 5"

SQL Server writes the file itself, as its service account, so the path must be
one that account can reach. A mapped drive or a user profile folder fails even
though it looks fine from an administrator's session. Use a local path, or grant
the SQL Server service account write access to the target.

---

## Building a release

### "A development server is running"

`npm ci` deletes `node_modules` and cannot while Vite holds it open. Stop the
dev server — the message names the process ids. A release is meant to be built
from a clean checkout.

### "Dev dependencies are present"

The vendor tree was built with dev dependencies. Re-run with
`composer install --no-dev`. The pipeline refuses to package it, because test
fixtures and tooling have no business on a pharmacy PC.

### "Driver 5.13.3 has no build for PHP 8.2"

Microsoft's SQL Server driver dropped PHP 8.2. Use PHP 8.3 or later — the
application requires `^8.2`, so 8.3 satisfies it. Bundling 8.2 would produce a
runtime that starts perfectly and cannot reach the database.

---

## Collecting information for support

```powershell
cd "C:\Program Files\PharmaVerify\scripts"
.\Test-Health.ps1 > "$env:USERPROFILE\Desktop\pharmaverify-health.txt"
Copy-Item C:\ProgramData\PharmaVerify\logs\install.log "$env:USERPROFILE\Desktop\"
```

Both are safe to send: the installation log records that secrets were set, never
their values. Check any screenshot you add for the pairing code, which should
not be shared.
