# 16 — Changelog

Format: version, date, change, module, reason.

---

## v0.1.0 — 2026-08-25

Initial two-day build. The complete business flow from master data through to
the OneDrive share, with documentation written alongside the code.

### Foundation

| Change | Module | Reason |
| --- | --- | --- |
| Repository structure: `backend/`, `frontend/`, `docs/`, `database/` | All | Requirement §51 |
| Laravel 12 API with Sanctum token authentication | Backend | Serves both the SPA and the HHT devices |
| React 19 + TypeScript + Vite + Material UI 7 | Frontend | Requirement §3 |
| Centralised design system in `frontend/src/theme` | Frontend | One product, not a set of forms |
| Permission-based RBAC via spatie/laravel-permission | Backend | Requirement §23 — permissions, not hard-coded roles |
| Shop scoping through the `ScopesToUserShops` trait | Backend | A Shop User sees only their assigned shops |
| `ApiExceptionRenderer` translating every exception into a readable message | Backend | Requirement §38 — no stack traces, SQL errors or raw exceptions |
| Activity logging via spatie/laravel-activitylog | Backend | Requirement §17 — audit trail with old and new values |

### Database

| Change | Module | Reason |
| --- | --- | --- |
| 14 application tables plus the package tables | Database | Requirement §31 |
| `audits` unique on (shop_id, device_id, audit_number) | Database | Audit number alone is not unique — §7 |
| `hht_submissions` unique on (shop, device, audit number, submission uid) | Database | Duplicate submission protection — §35 |
| `item_stocks` unique on (shop_id, product_code, batch) | Database | Multi-shop stock — §12 |
| `devices` unique on (shop_id, device_code) | Database | A device code belongs to its shop |
| Verification state carried on `audit_lines` | Database | Combining a technically redundant entity — §31 |
| Variance persisted on `audit_lines`, always server-computed | Database | Report performance without a second source of truth |
| `batch` defaults to `''` rather than null | Database | Unique-index behaviour is then identical on SQL Server and MySQL |
| Generated SQL Server schema script | Database | The target engine is SQL Server; see docs/15 |

### Master

| Change | Module | Reason |
| --- | --- | --- |
| Shops: list, create, edit, view, activate/deactivate, search, filter | Shops | Requirement §9 |
| Items: list, create, edit, activate/deactivate, search, filter | Items | Requirement §10 |
| HHT Devices as a master screen | Devices | Submission identity needs registered devices — docs/15 A-06 |

### Stock

| Change | Module | Reason |
| --- | --- | --- |
| Excel import with flexible header matching | Stock Import | Client files vary in wording |
| Row-level validation with a reason per rejected row | Stock Import | Requirement §36 |
| **Stock replacement inside one transaction** | Stock Import | Requirement §11 — replace, never append; atomic |
| Import summary: total, imported, failed, replaced, by whom, when | Stock Import | Requirement §11 |
| Item Stock screen with shop, product, barcode, batch, expiry and status filters | Item Stock | Requirement §13 |

### Verification flow

| Change | Module | Reason |
| --- | --- | --- |
| `POST /api/hht/submissions` with a documented contract | HHT | Requirement §34 |
| Two-layer idempotency: submission uid, then payload hash | HHT | Requirement §35 — a retry must not duplicate |
| In-app HHT Simulator | HHT | Requirement §6 — a demonstration mechanism while the device is unavailable |
| Audit list and detail with header, summary and lines | Stock Audit | Requirement §14, §15 |
| Verification worklist and permission-gated line editing | Verification | Requirement §16, §17 |
| Variance screen with direction filters and a summary | Variance | Requirement §20 |
| **Adjustment posts immediately, with no approval step** | Adjustment | Requirement §18 |
| Adjustment history retained | Adjustment | Requirement §18 |
| Batch adjustment where one refusal does not discard the rest | Adjustment | Practical for month-end |
| **Stock Take never creates an Item Master record** | Stock Take | Requirement §19 |
| Stock Take candidates surfaced from counted unknown items | Stock Take | Makes the flow discoverable |

### Output

| Change | Module | Reason |
| --- | --- | --- |
| One report engine driving all nine reports | Reports | Requirement §21 — a column is added once, not three times |
| Excel export via PhpSpreadsheet | Reports | Requirement §21 |
| PDF export via dompdf | Reports | Requirement §21 |
| Final output workbook per audit | Final Output | Requirement §25 |
| **OneDrive upload only on an explicit click** | OneDrive | Requirement §26 |
| Graph and demonstration drivers behind one interface | OneDrive | The flow is demonstrable before credentials arrive — docs/15 D-02 |
| Upload progress, success, failure, retry and history | OneDrive | Requirement §26 |

### Administration

| Change | Module | Reason |
| --- | --- | --- |
| User management with role and shop assignment, reset, activate/deactivate | Users | Requirement §22 |
| Last-administrator guard | Users | Prevents locking the system out |
| Application settings; secrets never exposed | Settings | Requirement §24 |
| Activity Log screen | Activity | Requirement §17 |

### Quality

| Change | Module | Reason |
| --- | --- | --- |
| 52 backend feature tests, 248 assertions | Tests | Requirement §42 — the rules most likely to regress |
| Manual browser walkthrough executed in full, all cases passed | Tests | Requirement §42 — every screen and the complete end-to-end flow verified by the project team on 2026-08-25; no issues found. Closes limitation L-04 |
| **Fixed:** `stock_imports.status` widened from 20 to 40 characters | Stock Import | `completed_with_errors` is 21 characters. SQLite accepted it, MySQL rejected it with a 500. Found by importing the sample file with deliberate errors |
| Added `SchemaFitsStatusValuesTest` | Tests | SQLite does not enforce string lengths, so the class of bug above was invisible to the suite. The guard reads each column's declared length from its migration |
| Sample stock files, generated by `pharmaverify:sample-stock` | Tooling | The demonstration and the manual walkthrough both need a real file to import |
| `pharmaverify:sqlsrv-schema` command | Tooling | Renders the migrations as SQL Server DDL without needing the driver installed |
| Realistic pharmacy demonstration data | Seeders | Requirement §43 — no `Test 1` or `Lorem Ipsum` |
| Demonstration audits with repeated numbers across devices | Seeders | Makes the identity rule visible in the demonstration |
| Documentation `01`–`16`, README, PROJECT-STATUS | Docs | Requirement §55–§73 |

### Deliberate omissions

Purchase, sales, supplier, procurement, purchase order, warehouse, goods
receipt and transfer modules; an approval workflow for adjustments; automatic
OneDrive upload; continuous HHT synchronisation. All excluded by the
requirement.

---

## v0.1.1 — 2026-08-26

SQL Server validation (dependency D-01). The application was executed against a
real SQL Server 2022 instance for the first time: migrations, the full
demonstration seed and all 52 feature tests. **Three defects surfaced that no
MySQL or SQLite run could have caught.**

| Change | Module | Reason |
| --- | --- | --- |
| **Fixed:** foreign keys reworked so every table has exactly one delete path | Database | SQL Server rejects multiple cascade / set-null paths between two tables — *“may cause cycles or multiple cascade paths”*. The schema **could not be created at all** on the target engine. MySQL and SQLite accept it silently. See `04-DATABASE-DESIGN.md` §3 and `15` A-15 |
| **Fixed:** `encrypt` and `trust_server_certificate` enabled in `config/database.php` | Config | Laravel ships both commented out, so the `DB_ENCRYPT` and `DB_TRUST_SERVER_CERTIFICATE` values documented in `.env.sqlsrv.example` and the deployment guide were never read. Anyone following the guide against an instance with a self-signed certificate — the default — would have been unable to connect |
| **Fixed:** `price` cast to float in the two bulk inserts | HHT / Stock Import | A column given an int in one row and a decimal string in another makes the SQL Server driver infer the parameter type from one row and reject the rest: *“Conversion failed when converting the nvarchar value '27.4000' to data type int”*. Seeding and any HHT submission containing an unknown product failed. MySQL coerces silently |
| Assertions on model columns relaxed from `assertSame` to `assertEquals` | Tests | SQL Server returns `bigint` as a string where MySQL returns an int. `assertSame` there asserts the driver's PHP type rather than application behaviour. Only the 16 affected assertions changed; `count()` and JSON assertions were left strict |
| Documentation updated to match | Docs | `04` cascade behaviour rewritten, `15` D-01 and L-01 closed with A-15 added, `11`, `12`, `README` and `PROJECT-STATUS` brought in line |

Verified after the changes: 52 tests pass on **SQL Server**, on **MySQL** and on
**SQLite**; the MySQL API walkthrough and both sample stock imports still behave
exactly as before.

---

## v0.2.0 — 2026-08-26

The business **Stock Report** (`Stock report.xlsx`) becomes the official stock
import. It is a Dynamics AX export of three sheets — about 152,000 rows — that
have to be joined, and it covers every branch it was run for.

**The previous importer could not process it at all.** It exhausted the 512 MB
memory limit inside PhpSpreadsheet before reaching validation, because it loaded
all three sheets *with styling* when it needed one sheet's data. Even given
unlimited memory it would have rejected the file: none of the Dynamics column
names matched its alias table, and it read a single sheet so could never have
produced description, unit, price or barcode.

| Change | Module | Reason |
| --- | --- | --- |
| `StockReportReader` — loads one sheet, only the needed columns, no styling, 25,000 rows at a time | Stock Import | Opening the file went from 67.5 s / 516 MB to 7.3 s / 62 MB. Peak for a whole import is now ~180 MB reading, ~270 MB overall, so it fits a default limit and stays bounded as reports grow |
| `StockReportImportService` — joins the three sheets | Stock Import | `stock` gives quantities, `Item Master` the product details, `all batches` the barcodes |
| Workbook validation before any processing | Stock Import | Requirement 6/7: sheet names first, then required columns per sheet |
| Shops matched by warehouse code | Shops | New `shops.ax_location_id`. One upload updates every shop the report names; the shop picker became an optional filter |
| Item Master synced from the report | Stock Import | Confirmed with the business — products the report introduces are created and known ones refreshed. Supersedes the earlier rule; recorded as BR-13 |
| Upload limit raised to 100 MB, execution limit lifted for the endpoint | API | The reference file is 6.6 MB and takes ~107 s end to end |
| Frontend: optional shop, multi-shop summary, honest processing message | Stock Import | The result now reports shops updated and products synced alongside the row counts |
| 8 feature tests for the new workflow | Tests | Multi-shop import, replace-not-append, repeat import, unmapped warehouse, row validation, missing sheet, item sync, and that the flat file still needs a shop |

**Two failure modes worth knowing**, both found while building this:

- Join keys carry stray whitespace. Matching `ITEMID|INVENTBATCHID` without
  trimming yields **no barcodes at all**, silently — it looks like a successful
  import with every barcode blank.
- `EXPDATE` is an Excel day serial, and reading without styling means it arrives
  as a plain number. Detecting it by cell format, as the old importer did, stops
  working the moment styling is skipped.

Verified against the real file: 8,910 of 8,913 rows imported across two shops in
~71 s; the three rejections are one genuine duplicate and two rows whose
`EXPDATE` is `1`. A shop the report does not mention kept its stock untouched.
The older flat single-sheet import still works and is still covered by its tests.

---

## v0.2.1 — 2026-08-26

Security hardening, a frontend test foundation, and a documentation
reconciliation. No business rule changed and the Stock Report module stayed on
hold throughout.

| Change | Module | Reason |
| --- | --- | --- |
| **API rate limiting** | Security | Laravel 12 does not throttle the API group on its own and `throttleApi()` was never called, so every endpoint — sign-in included — accepted unlimited attempts. Sign-in is now 5 a minute per email *and* origin, plus 20 a minute per origin against spraying; authenticated traffic is 300 a minute per user; stock import is 6 per 10 minutes. Every limit is keyed to a caller, never counted globally |
| 429 in the standard envelope | API | `ThrottleRequestsException` implements `HttpExceptionInterface`, so without its own arm it returned Laravel’s wording and lost the `Retry-After` header. It now returns the application envelope with the seconds to wait, and reveals neither which limit was hit nor whether an account exists |
| 10 rate-limiting tests | Tests | Lockout, per-account isolation, envelope shape, no technical leakage, no account enumeration, per-user throttling, HHT retries surviving, stock import usable |
| **66 frontend tests** | Tests | The frontend had no automated coverage at all. Vitest, Testing Library and MSW now cover `DataTable`, `useTableQuery`, `AuthContext`, the route guards, and the Adjust, Verify and Stock Take dialogs. Closes limitation L-03 |
| API documentation corrected | Docs | `docs/05` described an endpoint that no longer existed — `shop_id` required and a 20 MB cap, against the real `nullable` and 100 MB, with the three-sheet path missing entirely |
| Requirement statuses reconciled | Docs | 73 of 80 rows still read “Planned” for features with a route, a service and passing tests |
| Stock Report dependency renamed to **D-07** | Docs | `D-05` had come to mean two different things: the OneDrive folder structure in `docs/15`, and the Stock Report confirmation in `PROJECT-STATUS.md` |

---

## v0.2.2 — 2026-08-26

Backup and recovery readiness. Documentation and operational scripts only — no
application code, schema, API behaviour or business rule changed, and the Stock
Report module stayed on hold.

| Change | Module | Reason |
| --- | --- | --- |
| **`docs/18-BACKUP-AND-RECOVERY.md`** | Docs | The deployment guide carried two checklist rows saying a backup should exist, and nothing else: no procedure, no restore steps, no RPO or RTO. The new document covers what to back up, the SQL Server approach, the full and restore procedures, verification, frequency, retention, storage security, the pre-production drill, and who is responsible for each part |
| **Corrected storage path** | Docs | The guide told the reader to back up `storage/app/final-output` and grant it write permission. Laravel's `local` disk is rooted at `storage/app/private`, so that path is empty — a backup job following the documentation would have copied nothing and reported success. Corrected in `docs/12` and `docs/09` |
| `database/scripts/backup-sqlserver.sql` | Ops | Full, differential and log backups with `CHECKSUM` and `RESTORE VERIFYONLY`, plus a query to confirm the schedule is actually running |
| `database/scripts/restore-sqlserver.sql` | Ops | Point-in-time restore, including the orphaned-login re-mapping that otherwise leaves a perfectly restored database the application cannot connect to |
| `database/scripts/verify-restore.sql` | Ops | Read-only checks against a restored database: schema, reference data, referential integrity, and the invariants that matter here — variance equals physical minus system, stock unique per shop/product/batch, one audit per shop/device/audit-number |
| `database/scripts/Backup-PharmaVerify.ps1` | Ops | Task Scheduler wrapper: backs up, verifies, copies the generated files and prunes to a retention window. Exits non-zero so a failed run raises an alert rather than passing unnoticed |
| RPO 15 minutes, RTO 2 hours | Docs | Stated as targets for the client to confirm, with the schedule that achieves them and the honest note that rebuilding a lost server is a different exercise |
| Dependency **D-08** and limitation **L-11** | Docs | The application has no backup scheduler. Recorded as a client dependency rather than implied to be handled |
| Deployment checklist extended to 24 rows | Docs | Recovery model, the three backup types, alerting on failure, the restore drill and `.env` custody were all absent |
| Scripts executed, not just written | Ops | The whole cycle was run against SQL Server 2022: backup, `VERIFYONLY`, restore of full + differential + log into a separate database, then `verify-restore.sql` on the restored copy — 11 checks passing and all 30 tables matching the source row for row. The failure path and a non-default collation were tested too. Recorded, with its limits, in docs/18 §13 |

---

## v0.2.3 — 2026-08-26

Microsoft Graph / OneDrive production readiness (D-02). No business rule, schema,
API contract or UI changed, and the Stock Report module stayed on hold.

| Change | Module | Reason |
| --- | --- | --- |
| **Half-filled configuration is refused before any request** | OneDrive | `isConfigured()` checked only `client_id` for the shipped `YOUR_…` placeholder. Filling in the client id while leaving `ONEDRIVE_TENANT_ID=YOUR_TENANT_ID` counted as configured, so the application POSTed to a tenant that cannot exist and reported “could not sign in” — sending an administrator after a credential problem that was really an unfinished `.env`. All four settings are now checked, and a `YOUR_` prefix counts as missing |
| **A failed sign-in now says why** | OneDrive | The token request returned `null` on any failure and logged nothing. An administrator debugging “could not sign in to Microsoft 365” had nothing at all to work from. The Azure reason — `AADSTS7000215: Invalid client secret provided`, and the like — is now logged, without the secret |
| **The access token is cached** | OneDrive | Every upload performed a fresh client-credentials sign-in. The token is now held for its lifetime less five minutes, keyed by a hash of tenant and client id, which halves the round trips and keeps clear of Azure's token endpoint limits. A `401` from Graph discards it so the next attempt signs in afresh |
| Nine Graph error codes distinguished | OneDrive | Only `accessDenied`, `quotaLimitReached` and `itemNotFound` were recognised. Throttling (`activityLimitReached`) reported the generic “rejected by Microsoft 365”, which reads like a permanent failure when the right advice is to wait and retry. Added throttling, expired sign-in, name conflict, malware, resource modified, invalid request and insufficient storage |
| **21 OneDrive tests** | Tests | The Graph driver had no coverage at all. Graph is faked at the network boundary — no real call, no real credential. Covers missing and placeholder configuration, sign-in failure, successful upload, folder and file-name encoding, four Graph errors, the chunked upload session, token reuse and invalidation, 403 and 401, that generating uploads nothing, re-share refusal, retry after failure, and that no secret or token reaches a response, the database or the log |
| `ONEDRIVE_*` block added to `.env.sqlsrv.example` | Config | It was in `.env.example` only, so anyone following the SQL Server deployment path had no OneDrive settings to fill in |
| docs/09 extended | Docs | Added what counts as configured, why admin consent must be an application permission, the common `AADSTS` codes, a twelve-step live verification procedure, and a security table |
| D-02 restated as **Code Ready / Live Verification Blocked** | Docs | The distinction matters: the code path is built and tested, but no test can prove a tenant exists, consent was granted or a secret is valid |

---

## v0.2.4 — 2026-08-27

Production-readiness cleanup: documentation corrected against the implementation,
the last uncovered critical workflow given frontend tests, and the frontend split
at route boundaries. No business rule, schema, API contract or UI changed, and the
Stock Report module stayed on hold.

| Change | Module | Reason |
| --- | --- | --- |
| **BR-13 and BR-14 marked provisional** | Docs | The BRD is where *confirmed* rules live, and both were stated there unqualified — BR-13 even read “Confirmed 2026-08-26”. They are D-07 decisions that were agreed with the business and then returned to hold for final sign-off, so the BRD was presenting an open client decision as settled. The rules are unchanged; only their status is now accurate, with a note that the quantity column and price source are not recorded as rules at all because they have not been confirmed |
| **19 Final Output / OneDrive frontend tests** | Tests | The explicit-share rule — nothing leaves the application until a user asks — is the single most consequential rule on that screen, and it had no frontend coverage at all. Now covers: rendering the list uploads nothing, opening the confirmation is not consent, dismissing it uploads nothing, an already-uploaded file offers no share, a failed one offers Retry with its reason, permission gating for share and generate, and the 403 / 429 / 502 / network and list-error paths. Verified by mutation: breaking the confirmation gate fails three of them |
| Backend test count corrected to 91 / 448 | Docs | `docs/11`, `README` and `PROJECT-STATUS` still said 70 tests and 369 assertions after the OneDrive work |
| Cross-engine claim re-verified rather than re-worded | Docs | `PROJECT-STATUS` claimed the suite passed on all three engines, but the 21 OneDrive tests had only ever run on SQLite. All 91 were then actually run on SQL Server 2022 and MySQL as well — both green — so the claim is now true rather than merely updated |
| Frontend test count corrected to 86 | Docs | `docs/11`, `README`, `docs/15` and `PROJECT-STATUS` still said 66 |
| `docs/02` and `README` D-02 wording | Docs | Both said the Graph driver was simply “untested”, which understated it — it is tested against a faked Graph and untested against a live tenant. Now reads **Code Ready / Live Verification Blocked** |
| `docs/04` cross-engine claim refreshed | Docs | A bolded “all 60 pass” read as current state and was two changes out of date |
| **Route-level code splitting** | Frontend | Every screen was in the initial bundle, so opening the sign-in page fetched the reports engine, the HHT simulator and the administration screens first. The 19 screens below sign-in are now loaded on demand, taking the initial payload from 832 kB to 628 kB — 248 kB to 199 kB gzipped — across 45 chunks. LoginPage is deliberately not split, being the first thing a signed-out visitor sees. Every route, URL and permission is unchanged, verified by diffing both against the previous file. Closes limitation **L-08** |
| Failed-chunk boundary, with a test | Frontend | Splitting the routes introduced a failure mode that did not exist before: a screen now arrives over the network and that request can fail, which without a boundary leaves a blank page. A failed chunk shows the standard error state with a reload. One test covers it, verified by mutation — removing the boundary fails it |
| The permission check stays outside the lazy component | Frontend | `RequirePermission` renders synchronously and returns its refusal without rendering the lazy child, so a user who may not see a screen does not download it either |

---

## v0.2.5 — 2026-08-27

API security hardening. No business rule, schema, API contract or UI changed,
the Stock Report module stayed on hold, and OneDrive behaviour is untouched.

| Change | Module | Reason |
| --- | --- | --- |
| **Response security headers** | Security | The application set none at all. Every response now carries `X-Content-Type-Options: nosniff` — which matters most on the generated workbooks and PDFs, where a browser second-guessing the content type is the actual risk — plus `X-Frame-Options: DENY` and `Referrer-Policy: no-referrer`. API responses also carry a `Content-Security-Policy` refusing every source, which is correct precisely because the API returns JSON and files and never markup; it is deliberately not applied to the one route that does serve a page |
| `Strict-Transport-Security`, over HTTPS only | Security | Sending it on a plain connection achieves nothing and misrepresents how the response was served. Behind a TLS-terminating proxy it needs `TRUSTED_PROXIES`, which is now supported and documented — that is the one setting here that fails quietly rather than loudly |
| **Hardcoded origins removed from CORS** | Security | `config/cors.php` permanently allowed `http://localhost:5173` and `http://127.0.0.1:5173` in every deployment, production included. Origins now come from `CORS_ALLOWED_ORIGINS` or `FRONTEND_URL` and nothing else; an unconfigured deployment allows no cross-origin request at all. Development access is unchanged — a developer's own `.env` names the origin |
| **Token expiry built, deliberately not enabled** | Security | Tokens have never expired, so one on a lost handheld terminal stays valid until revoked by hand. The window is now configuration, set independently for the web and for devices, because a browser signs in again in seconds whereas a terminal expiring part way through a stock take interrupts a count. Both are **unset**, leaving behaviour exactly as before, and the duration is recorded as an open decision — **D-09**. A zero or empty value reads as *no expiry* rather than *expire immediately*, which is tested |
| 21 security tests | Tests | Headers present on success, failure and downloads; CSP scoped to the API; HSTS only over HTTPS and switchable; production CORS refusing a developer origin while the configured one still works; token windows read correctly and applied per caller; sign-in and rate limiting unaffected; no credential or token in any response header |
| Limitations **L-12** and **L-13** recorded | Docs | Tokens not expiring, and an API route reached without an `Accept` header answering `500` rather than `401` — the latter pre-dates this work and is not reached by the SPA or the devices |

---

## v0.2.6 — 2026-08-27

One defect in the API error contract. No business rule, schema, API contract,
UI or OneDrive behaviour changed, and the Stock Report module stayed on hold.

| Change | Module | Reason |
| --- | --- | --- |
| **Unauthenticated API requests answer `401`, not `500`** | API | Laravel installs a default guest redirect that resolves `route('login')`. This application defines no such route — signing in belongs to the SPA — and the callback is evaluated *inside* the auth middleware, so the routing error was raised before the exception renderer could answer. A caller that did not ask for JSON therefore received `500` with an internal exception name where it should have received `401`. The SPA and the devices always send `Accept: application/json`, so this was only reachable from a browser opening the URL, a health check or a proxy probe. Closes limitation **L-13** |
| 9 tests for the unauthenticated response | Tests | A bare request and a browser-style request both refused; the standard envelope; no exception, trace, route name or file path exposed even with `APP_DEBUG` on; the same answer across several guarded endpoints; and the existing JSON, valid-token and invalid-token paths unchanged. Verified by mutation — removing the fix fails six of the nine, and the three that still pass are exactly the ones asserting nothing else moved |

---
## Template for later entries

```
## vX.Y.Z — YYYY-MM-DD

| Change | Module | Reason |
| --- | --- | --- |
|  |  |  |
```
