# 15 — Assumptions and Dependencies

Everything below is **our decision or an outstanding dependency**, not a
confirmed business rule. The confirmed rules live in `01-BRD.md` §6 and are
implemented exactly as stated.

Status: `Open` — needs the client · `Decided` — our call, documented ·
`Resolved` — settled.

---

## 1. Dependencies on the client

### D-01 · Microsoft SQL Server access — **Resolved 2026-08-26**

**Situation.** SQL Server is the target database. The build machine had neither a
SQL Server instance nor the PHP `sqlsrv` / `pdo_sqlsrv` extensions, so the
application was originally developed against MySQL.

**How it was closed.** SQL Server 2022 was run in a container and the application
executed against it from a PHP 8.2 container carrying Microsoft ODBC Driver 18.
Migrations, the full demonstration seed and all 52 feature tests were run against
the real engine, producing the same data as MySQL (4 users, 3 shops, 14 audits,
107 counted lines, 37 stock records).

**Three genuine defects were found and fixed** — none of which any MySQL or
SQLite run could have surfaced. See §4 of this document and `16-CHANGELOG.md`.

**Still outstanding for the client's own environment.** The validation ran against
SQL Server 2022 in a container. Before go-live the same steps should be repeated
against the client's actual instance, since collation, an existing security
policy or a non-default schema could still differ. That is a deployment
verification, not a development risk: the application is now known to run on the
engine.

**Note on the developer machine.** The host PHP 8.2 build now carries the
`sqlsrv` and `pdo_sqlsrv` extensions, but connecting from the host additionally
needs Microsoft ODBC Driver 18, whose installer requires administrator rights
that were not available. Per-user driver registration does not work: Windows
reads ODBC *driver* registrations only from `HKLM`. Installing that driver with
admin rights is the one remaining step for host-native SQL Server development.

### D-02 · OneDrive credentials — **Code Ready / Live Verification Blocked**

**Situation.** No Azure app registration was available, and none has been
supplied. Every credential in the environment is still a `YOUR_…` placeholder.

**What we did.** The Microsoft Graph driver is written and now covered by
21 automated tests that fake Graph at the network boundary — client credentials
flow, token caching and invalidation, simple upload under 4 MB, chunked upload
session above it, the nine Graph error codes worth distinguishing, RBAC, the
explicit-share rule, re-share refusal, and assertions that no secret or token
reaches a response, the database or the log. A demonstration driver remains
active meanwhile.

Hardened on 2026-08-26 while preparing for production (see
`16-CHANGELOG.md` v0.2.3): a half-filled configuration is now refused before any
request is sent, a failed sign-in logs the reason Azure gave, and the access
token is cached rather than fetched for every upload.

**Needed.** Tenant ID, client ID, client secret, `Files.ReadWrite.All` as an
**application** permission with **tenant administrator consent**, and the
destination drive id or user principal.

**What cannot be verified without them.** That the tenant exists, that consent
was actually granted, that the secret is valid and unexpired, and that the
destination drive is writable. No test can stand in for a real tenant, and none
of the tests here claims to.

**Effort once available.** One environment change, then the twelve-step live
verification in `09-ONEDRIVE-INTEGRATION.md` §9. No code change expected.

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

### D-06 · Production environment — **Partly decided 2026-08-27**

**Decided.** The first client deployment is a **single on-premises PC, single
user** — one machine running the database, the API and the frontend together.
No server, no cloud instance. SQL Server Express is sufficient. The application
needs no change to run this way; see the *Deployment profile* section at the top
of `12-DEPLOYMENT-GUIDE.md` for what the profile does and does not require.

**Still open.**

| # | Item | Why it matters |
| --- | --- | --- |
| 1 | **Do the handheld terminals submit to this PC?** | Decides whether inbound network access, a device-trusted certificate and an always-on machine are needed at all |
| 2 | Windows edition on the PC | IIS requires Windows 10/11 Pro or better |
| 3 | Certificate source, if devices submit | Internal CA, or a public certificate for a name that resolves on the LAN. A self-signed certificate is rejected by devices unless installed on each |
| 4 | Where off-machine backup copies go | On one PC this is the difference between a recoverable failure and a total loss — see D-08 |

Network egress to Microsoft Graph is still required if OneDrive sharing is used,
whichever way item 1 is answered.

### D-09 · Access token lifetime — **Open**

**Situation.** Tokens issued at sign-in have never expired. A token on a lost or
stolen handheld terminal therefore stays valid until an administrator revokes it,
and nothing in the audit trail marks its use as unusual — the token is genuine.

**What we did.** Built the expiry as configuration rather than choosing a value.
Two windows are set independently, `AUTH_TOKEN_WEB_EXPIRY_MINUTES` and
`AUTH_TOKEN_DEVICE_EXPIRY_MINUTES`, and both are unset by default, so behaviour
is unchanged until somebody decides otherwise. The mechanism is covered by tests,
including that an unset or zero value means *no expiry* rather than *expire
immediately*.

**Why it is not simply switched on.** The two callers are not alike. A browser
session can be re-established in seconds. A handheld terminal that expires part
way through a stock take interrupts a count in progress, and that is an
operational cost the counting team must weigh, not a technical detail.

**Needed.**

| # | Item | Why it cannot be decided here |
| --- | --- | --- |
| 1 | Web session window | Balance of convenience against exposure |
| 2 | Device window | Depends on how long a count runs and how devices are managed |
| 3 | Whether a lost device is revoked by hand instead | An operational process question |

**Effort once decided.** Two environment values. No code change.

### D-08 · Backup execution and retention — **Open**

**Situation.** The backup and recovery procedure is written and scripted
(`18-BACKUP-AND-RECOVERY.md`, `database/scripts/`), but **the application runs
none of it**. PharmaVerify has no scheduler, no backup agent and no retention
policy in code, and it would be untrue to imply otherwise.

**What we provide.** The procedure, the SQL Server backup and restore scripts, a
PowerShell wrapper for Task Scheduler, and `verify-restore.sql`, which checks
the application's own invariants after a restore.

**Needed from the client or their DevOps team.**

| # | Item | Why it cannot be decided here |
| --- | --- | --- |
| 1 | Schedule the backups | Needs the hosting environment |
| 2 | Backup storage, off-site copy and encryption at rest | Infrastructure and cost |
| 3 | Monitoring and alerting on backup failure | A silently failing job is trusted |
| 4 | Confirm RPO 15 min and RTO 2 hours are acceptable | A business decision |
| 5 | Statutory retention for pharmacy stock records | A compliance question |
| 6 | Run the pre-production restore drill | Needs the real environment |

**Effort once the environment exists.** Roughly half a day to schedule the jobs
and run the drill. No code change.

### D-07 · Stock Report business decisions — **Open**

**Situation.** The business Stock Report import is built, tested against the real
152,000-row file and covered by 8 tests on all three engines, but **the module is
ON HOLD**: four business decisions behind it are not yet confirmed.

**Needed.**

| # | Decision | Built as | Cost if changed |
| --- | --- | --- | --- |
| 1 | Which column is the system quantity | `LOWERQTY` | ~1 h to use `HIGHERQTY` or keep both — they differ on 2,392 of 8,913 rows |
| 2 | How a warehouse maps to a shop | `shops.ax_location_id`, importing every shop the report names | ~2 h for a different model |
| 3 | Whether the import may sync the Item Master | Yes — creates and refreshes, never deletes | ~2 h to revert to enrichment only |
| 4 | Which price to carry | `SALESPRICE` from Item Master, `COSTPERINVUNIT` as fallback | ~30 min to switch to cost |

Decisions 1 to 3 were confirmed with the business on 2026-08-26 and then placed
back on hold pending final client sign-off. Decision 4 is our judgement and has
not yet been put to the client.

**Effort if all four are confirmed as built.** Nil — the module is finished.

Full detail in `17-STOCK-REPORT-IMPORT.md` §6.

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

### A-15 · One delete path per table, restrict on history — **Decided 2026-08-26**

SQL Server permits only one cascade or set-null path between two tables. Meeting
that meant choosing, for each reference, whether it cascades, restricts, or drops
its constraint. The result:

- Deleting a shop still removes everything beneath it, by exactly one route each.
- **A user who has history can no longer be deleted.** The application never
  deletes users — it deactivates them — so nothing changes in practice, and it
  strengthens the intent that history survives the person leaving.
- **A device with audits, and a product still held as stock, can no longer be
  deleted.** Both are sensible for an auditing system, but they are a change:
  those endpoints will now return a foreign key error rather than succeeding.
- Denormalised `shop_id` columns and the soft `item_stock_id` / `audit_id`
  references keep their index but hold no constraint, so a little referential
  integrity moves into the application.

Full detail in `04-DATABASE-DESIGN.md` §3. **Worth confirming with the client**:
if they expect to hard-delete a device or an item that has history, that now
needs an explicit cleanup step rather than a cascade.

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
| ~~L-01~~ | ~~Not executed against SQL Server~~ | **Closed 2026-08-26.** Migrations, the full seed and all 52 tests run against SQL Server 2022. Repeat against the client's own instance before go-live — see D-01 |
| L-02 | OneDrive not tested against a live tenant | The Graph path is covered by 21 tests against a faked Graph, but consent, secret validity and the destination drive can only be proven against the client's real tenant — see D-02 |
| ~~L-03~~ | ~~No automated frontend tests~~ | **Closed 2026-08-26**, extended 2026-08-27. 86 Vitest tests cover the shared machinery, the three business dialogs, the Final Output / OneDrive share and the failed-chunk boundary. Individual screens are still covered by the manual walkthrough, which should be repeated after a significant frontend change |
| ~~L-04~~ | ~~The UI was not verified in a browser~~ | **Closed 2026-08-25.** No browser automation was available in the build environment, so the project team performed the manual walkthrough. All screens and the full end-to-end flow were exercised; no functional, UI, navigation or validation issues were found |
| L-05 | Import is synchronous | A very large file ties up the request. Queueing it is straightforward if real files prove large |
| L-06 | No email | Password reset is administrator-driven; there is no self-service reset |
| L-07 | Single application language | English only |
| ~~L-08~~ | ~~Frontend ships as one bundle (~830 KB, ~248 KB gzipped)~~ | **Closed 2026-08-27.** Split at route boundaries: each screen is fetched when it is first opened, taking the initial payload to ~628 KB (~199 KB gzipped) across 45 chunks. Every route, URL and permission is unchanged, and a boundary shows a readable failure if a chunk cannot be fetched |
| L-09 | Dashboard has no charts | The requirement asked not to overload it. The variance breakdown is a proportional bar |
| L-10 | Adjustment history has no reversal action | Correcting means posting another adjustment |
| L-11 | No backup scheduler in the application | The procedure and scripts are supplied, but the infrastructure must run them — see D-08 |
| L-12 | Access tokens do not expire | The mechanism is built and tested but deliberately unset, pending the operational decision in D-09 |
| ~~L-13~~ | ~~An API route reached without an `Accept: application/json` header answers `500` rather than `401`~~ | **Closed 2026-08-27.** Laravel's default guest redirect resolved a `login` route this API does not define, and did so inside the auth middleware before the exception renderer could answer. With no redirect target the refusal reaches the renderer and returns `401` in the standard envelope, whatever the caller asked for |

---

## 5. What to settle first

| Priority | Item | Why |
| --- | --- | --- |
| 1 | D-01 SQL Server | The only dependency that could surface real defects |
| 2 | D-03 HHT payload | Decides whether a mapping layer is needed |
| 3 | D-02 OneDrive credentials | Turns a tested-but-faked flow into a verified one |
| 4 | D-04 Report columns | Cheap to change, but better settled before users see them |
| 5 | A-04 Batch in real files | Affects how real stock files import |
| 6 | D-08 Backup execution | The procedure exists; only the client can schedule and drill it |
| 7 | D-09 Token expiry windows | Tokens never expire today; the mechanism is built and waiting on a duration |
