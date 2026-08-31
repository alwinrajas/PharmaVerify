# PharmaVerify — Release Notes

Semantic versioning: `MAJOR.MINOR.PATCH`.

---

## 1.0.3 — unreleased

Fixes the three faults observed installing 1.0.2 on a real machine, and adds
the product branding.

- **HTTP 500.19 on every request.** The web server configuration named the URL
  Rewrite module while the module was not installed — an unrecognised
  configuration section takes the whole site down, not just deep links. The
  installer now installs the component itself (from a bundled Microsoft MSI,
  with a download fallback for unstaged builds), and a rewrite-bearing
  configuration is only ever written once the module verifiably exists.
  Without it, a minimal configuration still serves the front page and the
  health check names exactly what is missing.
- **`pharmaverify.local` stopped resolving.** Two causes: uninstalling 1.0.1
  removed every hosts line mentioning the name — including one the user had
  written by hand — and the 1.0.2 install's hostname step crashed
  ("Stream was not readable") before writing the replacement. Uninstall now
  removes only the line the installer itself tagged; the hosts file is read
  and written with static file IO plus a short retry, and a failure logs the
  full exception type instead of a one-line warning.
- **The installation stopped silently after the web-server step.** Under
  strict mode, reading `$LASTEXITCODE` before any native command has run is a
  terminating error; the variable is now primed at start, and the whole
  orchestrator is wrapped so nothing can ever again stop without a FAIL line
  in the log (exit 9, with its own plain-language dialog).
- **Branding.** The installer, Windows Apps entry, desktop and Start-menu
  shortcuts now carry the PharmaVerify shield icon instead of the generic
  document icon.

## 1.0.2 — built 31 Aug 2026, superseded

Fixes the defect that made 1.0.1 install its files and configure nothing.

- **The configuration step never ran in 1.0.1.** The installer launched it via
  `powershell.exe -File` with a `ConvertTo-SecureString` expression as an
  argument — but `-File` passes arguments as literal text, so password binding
  failed before the first log line; and PowerShell returns exit code 0 for that
  failure, so the wizard continued to "Installation Complete". The result was a
  machine with all files present, nothing configured, and the framework
  placeholder on screen. Configuration now runs from the installer's code
  section with its exit code read and acted on; the password travels in a
  process-scoped environment variable, never on a command line.
- **A configuration failure now says so.** Exit codes map to plain-language
  messages naming the failed step, the log path, and the script to re-run
  without reinstalling. "Installation Complete" is no longer reachable past a
  failed configuration.
- **The web server is configured before the database**, so a machine whose
  database step fails still serves the real application and a health report
  naming the one thing left to fix — instead of nothing at all.
- **The framework welcome page is gone.** The root fallback is now a
  PharmaVerify page that states the actual situation — the built web
  application is missing from `public/` — and what to do, for a client PC and
  for a development checkout separately. The health check recognises it by a
  dedicated marker.
- **The release pipeline restores the checkout.** Packaging strips dev
  dependencies from the backend in place; the pipeline now reinstalls them
  afterwards, so a successful release no longer leaves the development machine
  unable to run its own tests.

## 1.0.1 — built 31 Aug 2026, superseded

Identical delivery layer to 1.0.2 minus the fixes above. Installs files
correctly but configures nothing; do not reuse the artifact.

## 1.0.0 — unreleased

First release packaged for client installation. Everything below is the delivery
layer; the application itself is unchanged apart from one routing fix.

### Installation

- **Windows installer** (Inno Setup 6). Wizard covering system check, install
  location, database, network, summary, progress and completion. Requires
  administrator rights, taken once at the start rather than failing part way in.
- **PHP 8.3.33 (non-thread-safe, x64) bundled**, with Microsoft's SQL Server
  driver 5.13.3. The client installs no PHP, no Composer and no Node.
- **IIS + FastCGI** as the production web server, so the application survives
  logout and reboot without a third-party service wrapper.
- **SQL Server Express** as the database. The installer creates the database and
  login, applies migrations, and seeds roles and the first administrator **only
  into a database with no users**.
- **Firewall** rule for TCP 8000, Private profile only, created idempotently —
  an existing correct rule is left alone, an incorrect one corrected rather than
  duplicated.
- **Machine-specific configuration** generated at install time in
  `C:\ProgramData\PharmaVerify\config\.env`. Nothing is packaged, so no two
  installations share an application key or database password.
- **Health check** at the end of installation, and from the Start menu
  afterwards. Thirteen checks; "Installation Complete" only when the essential
  ones pass.

### Upgrade, uninstall

- Upgrades keep the database, the configuration and the application key.
  Migrations only add.
- Uninstall removes the application, site, service and firewall rule, and
  **keeps the database, configuration, logs and backups**. The uninstaller says
  so before starting.

### Backup

- Timestamped backups with retention. Reads the connection from the
  application's own configuration, uses Windows authentication so no password
  reaches the process list, flags a suspiciously small backup, and **never
  deletes the newest one**.

### Application change

- **SPA routing fix.** `routes/web.php` gained a catch-all so client-side routes
  survive a refresh. Opening `/verification` directly, or pressing refresh on
  it, previously returned 404. The API and `/up` are matched first and are not
  shadowed.

### Network automation

- The installer publishes `pharmaverify.local` on the PC automatically: hosts
  entry `127.0.0.1  pharmaverify.local` (loopback on purpose — it cannot go
  stale when DHCP moves the PC), written after a one-time backup, deduplicated,
  DNS cache flushed, and the name verified to actually resolve rather than
  trusted. Uninstall removes only that entry, surgically.
- The local and handheld addresses are kept apart everywhere: the PC opens the
  site by name; handhelds are shown the detected LAN address, because a Windows
  hosts file never reaches Android.
- Port 8000 in use by another program stops installation with that program
  named — never a silent switch to a different port, which would strand every
  handheld configured with the documented one.
- The health check now also verifies the hostname resolves and that the page
  served is the real application, not the framework placeholder — told apart by
  the SPA mount point, since the placeholder titles itself with the app name.

### Windows PowerShell 5.1 compatibility

The installer runs its scripts with `powershell.exe` (5.1), while development
verification had been running them under PowerShell 7. A behavioural pass under
real 5.1 found and fixed five classes of defect that parse checks cannot catch:
`?.` member access, `RandomNumberGenerator::Fill` (absent from .NET Framework),
`.Count` on pipeline results that unwrap to a scalar under StrictMode,
`Mandatory` string parameters rejecting the empty strings used as log spacing,
and multi-line inline `php -r` code being re-quoted into a parse error (probes
now run from temp files). Em dashes in double-quoted strings of BOM-less
scripts were also replaced — under a non-UTF-8 ANSI code page 5.1 can read one
as a smart quote and truncate the string.

### Documentation

`INSTALLATION-GUIDE.md` (client), `ADMIN-GUIDE.md` (IT),
`TROUBLESHOOTING.md`, `ACCEPTANCE-CHECKLIST.md`.

### Decisions worth recording

**PHP 8.3, not 8.2.** Microsoft's driver 5.13.3 dropped 8.2. Bundling it would
have produced a runtime that starts perfectly and cannot reach the database.
The application requires `^8.2`, so 8.3 satisfies it. The staging script now
fails rather than pairing a PHP with a driver that has no build for it.

**IIS rather than a bundled web server.** `artisan serve` is single-threaded and
dies with the session. Shipping Apache or nginx means patching a web server on a
machine nobody administers. IIS is already in Windows and already a service.

**Hostname before HTTPS.** `pharmaverify.local` is permitted by the handheld app
whatever the PC's address, so it survives DHCP changes and needs no new APK.
HTTPS is the intended end state and needs no APK change either; the steps are
written to `HTTPS-SETUP.txt` at install time.

### Known limitations

- **Not verified on a clean machine.** No clean Windows VM was available. The
  installer compiles and produces a valid versioned executable; it has not been
  run against a machine without PHP, Composer or Node.
- **Not verified on handheld hardware.** No Android terminal was available.
  Pairing, submission and report sharing are covered by automated tests and the
  server side is verified over a real network, but no on-device screen has been
  observed.
- **Cleartext HTTP** between handhelds and the PC until a site moves to HTTPS,
  including the pairing exchange.
- **Single point of failure.** One PC holds the database, application and files.
  The backup schedule is not optional.
