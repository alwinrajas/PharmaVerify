# PharmaVerify — Acceptance Checklist

What must be true before a release goes to a pharmacy.

Each item is marked with its current state:

| | |
|---|---|
| **VERIFIED** | Exercised in this environment and observed to work |
| **PARTIAL** | The logic is tested; the whole path is not |
| **NOT VERIFIABLE** | Needs a clean Windows VM or an Android terminal, neither available |

**An installer that compiles is not a tested installer.** Everything below
marked NOT VERIFIABLE has to be walked on real hardware before a client
installation.

---

## Build

| # | Check | State |
|---|---|---|
| 1 | Backend tests pass | **VERIFIED** — 235 passed, 1075 assertions |
| 2 | Front-end tests pass | **VERIFIED** — 122 passed |
| 3 | TypeScript compiles clean | **VERIFIED** |
| 4 | Front end builds | **VERIFIED** |
| 5 | Payload excludes `.env` | **VERIFIED** — pipeline check |
| 6 | Payload excludes tests | **VERIFIED** — pipeline check |
| 7 | Payload excludes dev dependencies | **VERIFIED** — check works; it **caught a real failure** on a hand-staged payload |
| 8 | PHP runtime stages with driver | **VERIFIED** — 8.3.33 NTS, `sqlsrv,pdo_sqlsrv` loaded |
| 9 | Installer compiles | **VERIFIED** — valid PE, version metadata correct |
| 10 | Failing tests block a release | **PARTIAL** — gates written, not exercised by a deliberate failure |

---

## Clean install

| # | Check | State |
|---|---|---|
| 11 | Installs on a PC with no PHP | **NOT VERIFIABLE** |
| 12 | Installs on a PC with no Composer | **NOT VERIFIABLE** |
| 13 | Installs on a PC with no Node | **NOT VERIFIABLE** |
| 14 | System check reports missing SQL Server | **PARTIAL** — probe written; this machine has no SQL Server to test against |
| 15 | Wizard pages accept and validate input | **NOT VERIFIABLE** |
| 16 | Database and login created | **NOT VERIFIABLE** — no SQL Server here |
| 17 | Migrations apply | **PARTIAL** — schema confirmed portable, no MySQL-only syntax |
| 18 | Seeders run only into an empty database | **PARTIAL** — logic written and reviewed |
| 19 | Demo seeder never runs | **VERIFIED** — not in the seeder list |
| 20 | IIS site and pool created | **NOT VERIFIABLE** — no IIS on this machine |
| 21 | FastCGI registered | **NOT VERIFIABLE** |
| 22 | Firewall rule created, Private only | **PARTIAL** — logic written; elevation refusal path verified |
| 23 | Shortcuts created | **NOT VERIFIABLE** |
| 24 | Health check runs and reports | **VERIFIED** — run against a live instance |
| 25 | Application opens in a browser | **PARTIAL** — dev instance verified, not an installed one |

---

## Network automation (added with the hostname work)

| # | Check | State |
|---|---|---|
| 68 | Hosts entry `127.0.0.1  pharmaverify.local` written, backed up first | **PARTIAL** — transform verified against fixture copies under PowerShell 5.1 (clean file, existing untagged entry, stale wrong-IP entry, duplicates); the write to the real system hosts file needs an elevated run |
| 69 | Duplicate/stale entries collapse to one canonical line; unrelated lines untouched | **VERIFIED** — fixture suite, byte-for-byte on unrelated lines |
| 70 | Uninstall removes only the PharmaVerify entry | **VERIFIED** — remove-mode fixture: zero entries left, unrelated intact |
| 71 | Hostname resolves to 127.0.0.1 and the site answers by name | **VERIFIED** on this machine — Test-NetConnection to pharmaverify.local:8000 succeeded at 127.0.0.1; `/up` 200 by name |
| 72 | Port conflict refused with the owning program named, never a silent port change | **VERIFIED** — Get-PortOwner against a live foreign listener reported `php` with its pid; free port returns nothing |
| 73 | Health check tells the real UI from the placeholder page | **VERIFIED** both directions — FAIL against the placeholder, PASS with the built index.html in place; `<title>` proved useless as a marker (APP_NAME appears on both pages), `id="root"` is the discriminator |
| 74 | Every script runs under Windows PowerShell 5.1, which the installer invokes | **VERIFIED** — parse checks on all 10 scripts under powershell.exe, plus live 5.1 runs; five classes of 5.1-only defect found and fixed (`?.`, `RandomNumberGenerator::Fill`, `.Count` on unwrapped pipelines, empty-string Mandatory params, multi-line inline `php -r` argument mangling) |
| 75 | LAN endpoint reported separately from the local one for the handhelds | **VERIFIED** — JSON output carries both; the report warns that the hosts file never reaches Android |

## Installed-machine fixes (verified against the real 1.0.2 installation)

| # | Check | State |
|---|---|---|
| 76 | Configuration actually runs from the installer | **VERIFIED** — the 1.0.2 install log shows the full orchestrator run: IIS features enabled, FastCGI registered, pool and site created and started |
| 77 | 500.19 root cause fixed: rewrite config only written when the module exists | **VERIFIED** logic + real-machine evidence; module auto-install itself **NOT VERIFIABLE unelevated** — the next elevated install exercises it |
| 78 | URL Rewrite MSI bundled for offline installs | **VERIFIED** — genuine Microsoft MSI (5.8 MB) staged and packaged via skipifsourcedoesntexist |
| 79 | Uninstall keeps hand-written hosts entries | **VERIFIED** — fixture: tagged line removed, untagged hand-written line and unrelated lines intact |
| 80 | Hosts IO survives transient locks and logs real exceptions | **PARTIAL** — static-IO + retry path verified on fixtures under 5.1; the elevated "Stream was not readable" condition itself cannot be reproduced on demand |
| 81 | Orchestrator can no longer die silently | **VERIFIED** — $LASTEXITCODE primed; whole body wrapped, exit 9 + dialog; unelevated run refuses cleanly through the wrapper |
| 82 | Health names the two live faults on the test machine with remedies | **VERIFIED** — "Local hostname" and "Address routing" both FAIL with actionable text against the real installed tree |
| 83 | Product icon on installer, Apps list, shortcuts | **PARTIAL** — icon generated and wired; visual confirmation needs the next install |

---

## Application

| # | Check | State |
|---|---|---|
| 26 | `/api` routes reachable | **VERIFIED** — 401 unauthenticated |
| 27 | `/up` health endpoint | **VERIFIED** — 200 |
| 28 | Client-side routes survive refresh | **VERIFIED** — `/verification` 200, was 404 |
| 29 | API not shadowed by the catch-all | **VERIFIED** |
| 30 | `APP_DEBUG=false` in generated config | **PARTIAL** — written; generation not run |

---

## Restart and service

| # | Check | State |
|---|---|---|
| 31 | Survives Windows restart | **NOT VERIFIABLE** |
| 32 | Survives logout | **NOT VERIFIABLE** |
| 33 | Web server set to start automatically | **PARTIAL** — `Set-Service W3SVC -StartupType Automatic` written |
| 34 | Pool does not idle out or recycle | **PARTIAL** — written |

---

## Upgrade, reinstall, uninstall

| # | Check | State |
|---|---|---|
| 35 | Existing installation detected | **PARTIAL** — registry probe written |
| 36 | Database preserved on upgrade | **PARTIAL** — adoption branch written |
| 37 | Application key preserved | **PARTIAL** — reuse logic written |
| 38 | Migrations run, nothing dropped | **PARTIAL** |
| 39 | Reinstall reconnects to the existing database | **NOT VERIFIABLE** |
| 40 | Uninstall removes site, pool, firewall, shortcuts | **NOT VERIFIABLE** |
| 41 | Uninstall preserves the database | **PARTIAL** — nothing in the uninstall path touches it; message written |
| 42 | Uninstall states plainly that data is kept | **VERIFIED** — in the compiled installer |

---

## Backup

| # | Check | State |
|---|---|---|
| 43 | Backup produces a timestamped file | **NOT VERIFIABLE** — no SQL Server |
| 44 | Reads the connection from the app's own config | **PARTIAL** |
| 45 | No password in logs or process list | **VERIFIED** by inspection — `sqlcmd -E`, `MYSQL_PWD` |
| 46 | Retention never deletes the newest | **PARTIAL** — logic written and reviewed |
| 47 | Restore documented | **VERIFIED** — in the admin guide |

---

## Handheld terminals

| # | Check | State |
|---|---|---|
| 48 | Pairing code issued and accepted | **VERIFIED** over the LAN, via the API |
| 49 | Device identity confirmed | **VERIFIED** — `/hht/devices/me` 200 |
| 50 | Count submits and is accepted | **VERIFIED** — `accepted`, `AUD-29082026-0041` |
| 51 | Duplicate returns the same reference | **VERIFIED** — `duplicate_ignored`, same ref |
| 52 | Wrong shop refused with a code | **VERIFIED** — 403, `shop_mismatch`, both shops named |
| 53 | Unpair revokes immediately | **VERIFIED** — 401 after |
| 54 | `last_seen` moves on submission | **VERIFIED** — 07:07:51 → 07:07:52 |
| 55 | Web shows the audit without a refresh | **PARTIAL** — polling and snackbar unit-tested, not seen in a browser |
| 56 | Terminal shows Connected | **NOT VERIFIABLE** — no device |
| 57 | Terminal shows Sending → Received | **NOT VERIFIABLE** |
| 58 | Offline count queues and retries | **NOT VERIFIABLE** |
| 59 | Mismatch blocked on the terminal | **NOT VERIFIABLE** |
| 60 | Excel and PDF share through the chooser | **NOT VERIFIABLE** |

---

## Security

| # | Check | State |
|---|---|---|
| 61 | No secrets in installer logs | **VERIFIED** by inspection — `Write-Secret` |
| 62 | `.env` restricted by ACL | **PARTIAL** — written |
| 63 | Pool writes only `storage` and `bootstrap/cache` | **PARTIAL** — written |
| 64 | `.env` and `storage` hidden from the web | **PARTIAL** — in `web.config` |
| 65 | Firewall not opened to Public | **VERIFIED** by inspection |
| 66 | Database login has no server-level rights | **PARTIAL** — `db_owner` on its own database only |
| 67 | Device cannot file for another shop | **VERIFIED** — server-enforced |

---

## Before the first client installation

1. Build a real release: `Get-PhpRuntime.ps1`, then `Build-Release.ps1`. **Do
   not ship a hand-staged payload** — the one built during development contained
   dev dependencies, which is exactly what the pipeline check exists to stop.
2. Install on a clean Windows VM with no PHP, Composer or Node, and walk
   items 11–25.
3. Restart the VM and confirm 31–34.
4. Upgrade over it and confirm 35–39.
5. Uninstall and confirm 40–42, then reinstall and confirm the data returned.
6. Pair a real handheld and walk 56–60.
7. Only then treat the release as production-ready.
