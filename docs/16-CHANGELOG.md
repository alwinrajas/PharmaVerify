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
## Template for later entries

```
## vX.Y.Z — YYYY-MM-DD

| Change | Module | Reason |
| --- | --- | --- |
|  |  |  |
```
