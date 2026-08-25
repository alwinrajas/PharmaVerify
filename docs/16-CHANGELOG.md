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

## Template for later entries

```
## vX.Y.Z — YYYY-MM-DD

| Change | Module | Reason |
| --- | --- | --- |
|  |  |  |
```
