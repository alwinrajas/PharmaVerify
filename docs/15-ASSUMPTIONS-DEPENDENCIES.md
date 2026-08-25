# 15 — Assumptions and Dependencies

Everything below is **our decision or an outstanding dependency**, not a
confirmed business rule. The confirmed rules live in `01-BRD.md` §6 and are
implemented exactly as stated.

Status: `Open` — needs the client · `Decided` — our call, documented ·
`Resolved` — settled.

---

## 1. Dependencies on the client

### D-01 · Microsoft SQL Server access — **Open**

**Situation.** SQL Server is the target database. The build machine has neither a
SQL Server instance nor the PHP `sqlsrv` / `pdo_sqlsrv` extensions, so the
application was developed against MySQL.

**What we did.** The data layer uses Eloquent migrations and the query builder
only — no raw SQL, no MySQL-only functions, no engine-specific types. The
automated test suite runs on SQLite, which is further evidence the schema and
queries carry nothing engine-specific. `database/sql/schema-sqlserver.sql` is
generated from the same migrations and contains real T-SQL, including all four
identity constraints.

**Residual risk.** The application has not been *executed* against SQL Server.
The likely areas to check first are date handling and the `nvarchar(max)` columns
used for JSON.

**Needed.** A reachable SQL Server instance with credentials, and the driver
installed per `12-DEPLOYMENT-GUIDE.md` §1. Then `php artisan migrate --force`,
`php artisan test`, and one pass of the end-to-end walkthrough.

**Effort once available.** Half a day, including fixes.

### D-02 · OneDrive credentials — **Open**

**Situation.** No Azure app registration was available.

**What we did.** The Microsoft Graph driver is fully written — client credentials
flow, simple upload under 4 MB, chunked upload session above it, and readable
handling of the common Graph errors. A demonstration driver is active meanwhile,
copying to local storage and returning the same result shape so progress,
success, failure and retry all work.

**Needed.** Tenant ID, client ID, client secret, `Files.ReadWrite.All` as an
application permission with admin consent, and the destination drive or user.

**Effort once available.** One environment change, then a live upload test. No
code change.

### D-03 · The real HHT payload — **Open**

**Situation.** No specification for the existing Android application was
provided.

**What we did.** Authored a clean contract (`08-HHT-INTEGRATION.md`) covering
submission identity, idempotency, validation and error handling, and built the
simulator against it.

**If the device emits something different.** The mapping belongs in
`HhtSubmissionService::receive()` and nowhere else — the rest of the application
works from the audit and its lines.

**Effort.** Half a day for a field mapping; longer only if the device cannot send
a submission identifier, in which case the payload hash carries idempotency
alone.

### D-04 · Report columns — **Open**

**Situation.** The requirement named nine reports but not their columns.

**What we did.** Chose the columns each report needs to answer its question, and
described every report in one place so a column is added by editing a definition
— the screen, the Excel export and the PDF export all follow.

**Needed.** Confirmation, or a marked-up list.

**Effort.** An hour or so per report.

### D-05 · OneDrive folder structure — **Open**

**Decided for now.** A single configurable folder, default
`PharmaVerify/FinalOutput`, with shop, device and audit number in the file name.

**Alternative if wanted.** Per-shop or per-month sub-folders — a change to
`GraphOneDriveUploader::itemPath()`.

### D-06 · Production environment — **Open**

Hosting, TLS certificates, backup schedule and network egress to Microsoft Graph
are all environment decisions. `12-DEPLOYMENT-GUIDE.md` states what is required.

---

## 2. Decisions we made

### A-01 · Verification has no separate table — **Decided**

Verification state lives on `audit_lines` (`verification_status`, `verified_by`,
`verified_at`). A separate table would carry no field the line does not already
hold, and would add a join to every verification query. The requirement
(§31) explicitly permits combining technically redundant entities.

### A-02 · Variance is derived, not a table — **Decided**

`variance_qty = physical_qty − system_qty`, persisted on the line so reports do
not recompute it per row. It is always recalculated by the server and never
accepted from a request.

### A-03 · System quantity is a snapshot — **Decided**

`audit_lines.system_qty` records what the system held **at the moment of the
count**, rather than joining live to `item_stocks`. A later stock import
therefore cannot rewrite the history of an earlier count.

### A-04 · Stock identity is shop + product + batch — **Decided**

`item_stocks` is unique on `(shop_id, product_code, batch)`. Barcode alone never
identifies stock, per BR-02. Files without a batch column import with an empty
batch, which behaves as one row per product per shop.

**Open point.** Whether the client's real files always carry a batch. If a shop
genuinely holds one product in two batches and the file omits the batch, the
second row is reported as a duplicate.

### A-05 · Batch is empty string, not null — **Decided**

SQL Server treats NULLs as equal in a unique index and permits only one; MySQL
permits many. Defaulting `batch` to `''` makes the constraint behave identically
on both.

### A-06 · Devices are master data — **Decided**

The requirement implies devices without listing a screen. Since submission
identity is Shop + Device + Audit Number, devices must be registered — so a
Devices screen exists under Master.

### A-07 · An audit number cannot be reused on one device — **Decided**

A second, different count under the same shop, device and audit number is refused
with `409`. A *duplicate* of a count already received is accepted as
`duplicate_ignored`. The requirement covers duplicates but not deliberate reuse;
refusing is the safer reading, and it is reversible.

### A-08 · An adjusted line cannot be adjusted twice — **Decided**

Consistent with "no approval workflow": the adjustment is the final word. A
correction is a further adjustment, and both appear in the history.

### A-09 · Products missing from the stock file cannot be adjusted — **Decided**

There is no stock record to adjust. They are flagged on the audit and offered as
Stock Take candidates, which is what TAKE-001 describes.

### A-10 · Demonstration accounts share one password — **Decided**

`Pharma@2026` for every seeded account, so the demonstration is easy to run.
Production seeding excludes demonstration data entirely — see
`12-DEPLOYMENT-GUIDE.md` §3.

### A-11 · Exports capped at 20,000 rows — **Decided**

Guards against an unfiltered export exhausting memory. The file states when it
was capped rather than truncating silently.

### A-12 · Token authentication rather than cookie sessions — **Decided**

Sanctum tokens serve the SPA and the HHT devices identically, and avoid CSRF
handling on the device side.

### A-13 · Excel import via PhpSpreadsheet directly — **Decided**

Rather than a wrapper package. Both the reader and both writers use one library
that is already proven in this environment, keeping the dependency list short.

### A-14 · The HHT simulator is a permanent screen — **Decided**

Kept behind `hht.view` and useful beyond the demonstration for testing the
endpoint. It uses no private path — the same contract as a real device.

---

## 3. Scope explicitly not built

Named in the requirement as out of scope, and absent by design:

Purchase · Sales · Supplier · Procurement · Purchase Order · Warehouse ·
Goods Receipt · Inventory Transfer · Warehouse Transfer · Approval workflow for
adjustments · Automatic OneDrive upload · Continuous HHT synchronisation ·
The Android application itself.

---

## 4. Known limitations of the two-day build

| # | Limitation | Consequence |
| --- | --- | --- |
| L-01 | Not executed against SQL Server | See D-01 |
| L-02 | OneDrive not tested against a live tenant | See D-02 |
| L-03 | No automated frontend tests | The frontend is covered by type-checking, a clean production build and a manual browser walkthrough. A regression there would not be caught automatically, so the walkthrough should be repeated after frontend changes. The backend has 52 feature tests |
| ~~L-04~~ | ~~The UI was not verified in a browser~~ | **Closed 2026-08-25.** No browser automation was available in the build environment, so the project team performed the manual walkthrough. All screens and the full end-to-end flow were exercised; no functional, UI, navigation or validation issues were found |
| L-05 | Import is synchronous | A very large file ties up the request. Queueing it is straightforward if real files prove large |
| L-06 | No email | Password reset is administrator-driven; there is no self-service reset |
| L-07 | Single application language | English only |
| L-08 | Frontend ships as one bundle (~830 KB, ~248 KB gzipped) | Acceptable for an internal application; route-level code splitting is a small change if wanted |
| L-09 | Dashboard has no charts | The requirement asked not to overload it. The variance breakdown is a proportional bar |
| L-10 | Adjustment history has no reversal action | Correcting means posting another adjustment |

---

## 5. What to settle first

| Priority | Item | Why |
| --- | --- | --- |
| 1 | D-01 SQL Server | The only dependency that could surface real defects |
| 2 | D-03 HHT payload | Decides whether a mapping layer is needed |
| 3 | D-02 OneDrive credentials | Turns a demonstrated flow into a live one |
| 4 | D-04 Report columns | Cheap to change, but better settled before users see them |
| 5 | A-04 Batch in real files | Affects how real stock files import |
