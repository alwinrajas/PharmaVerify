# PROJECT STATUS — PharmaVerify

**Project:** Pharmacy Stock Verification Web Application
**Window:** 2 working days (accelerated delivery)
**Version:** 0.2.1
**Last updated:** 2026-08-26

Statuses: `Not Started` | `In Progress` | `Completed` | `On Hold` | `Blocked` | `Needs Clarification`

---

## Summary

The complete business flow works end to end: master data → stock import →
HHT submission → audit → verification → variance → adjustment / stock take →
reports → final output → OneDrive.

- Backend: 70 feature tests, 369 assertions, **all passing on SQL Server 2022,
  MySQL and SQLite**.
- Frontend: 66 tests passing, type-check clean, production build succeeds.
- **Browser UI validation completed** by the project team on 2026-08-25 — all
  screens and the full end-to-end flow exercised, no issues found.
- Documentation: `README`, `PROJECT-STATUS` and `docs/01`–`17` complete.
- Every P0 item is complete except the Stock Report import, which is built and
  tested but **on hold** pending client confirmation (D-07).
- Six items sit with the client. Four block something (D-02, D-03, D-04, D-07);
  two are decided-for-now defaults (D-05, D-06). See the bottom of this page.

---

## Foundation

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| Project structure | Completed | P0 | `backend/` · `frontend/` · `docs/` · `database/` |
| Laravel backend | Completed | P0 | Laravel 12, API only, Sanctum tokens |
| React frontend | Completed | P0 | React 19 + TS + Vite + MUI 7 |
| Database connection | Completed | P0 | Verified on SQL Server 2022, MySQL and SQLite from one schema |
| Authentication | Completed | P0 | Login, logout, session restore, inactive-account guard |
| RBAC | Completed | P1 | 33 permissions, 3 roles, enforced server side |
| Shop scoping | Completed | P1 | `ScopesToUserShops` applied across every scoped model |
| Premium UI shell | Completed | P0 | Sidebar, top bar, page header, reusable DataTable |
| Design system | Completed | P1 | Single theme: colour, type, spacing, component defaults |
| Error handling | Completed | P0 | Every exception translated into a readable message, including 429 |

## Master

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| Shops | Completed | P0 | CRUD, activate/deactivate, search, filter, sort |
| Items | Completed | P0 | CRUD, activate/deactivate, search, filter, sort |
| Devices | Completed | P0 | Unique per shop; underpins submission identity |
| Item Stock Import (flat file) | Completed | P0 | Excel, flexible headers, row validation, **atomic replace** |
| **Stock Report Import** | **On Hold** | P0 | Built and tested against the real 152,000-row file (8,910 rows across 2 shops in ~71s); all 8 tests pass on all three engines. **Awaiting client confirmation** on the quantity column, warehouse-to-shop mapping, Item Master sync and price source — see docs/17 §6. No further changes until confirmed |
| Import error reporting | Completed | P0 | Row, column, value and reason per rejection |
| Item Stock | Completed | P0 | All filters, expiry highlighting, summary figures |

## Verification flow

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| HHT Submission API | Completed | P0 | Documented contract, two-layer idempotency |
| HHT Simulator | Completed | P0 | Same endpoint as a real device; demonstrates duplicates |
| Stock Audit list | Completed | P0 | Shop, device, audit number, status and date filters |
| Stock Audit detail | Completed | P0 | Header, summary strip, line table, inline actions |
| Stock Verification | Completed | P0 | Worklist plus permission-gated line editing |
| Verification audit trail | Completed | P1 | User, time, field, old value, new value |
| Variance | Completed | P0 | Direction filters, summary, export |
| Stock Adjustment | Completed | P0 | **Immediate posting, no approval step** |
| Adjustment history | Completed | P0 | Before and after quantities retained |
| Batch adjustment | Completed | P2 | One refusal does not discard the rest |
| Stock Take | Completed | P0 | **Item master never touched**; candidates surfaced |

## Output

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| Report engine | Completed | P0 | One definition drives screen, Excel and PDF |
| Variance Report | Completed | P0 | |
| Audit No. Based Report | Completed | P0 | Always shows shop and device context |
| Individual Shop Based Report | Completed | P0 | |
| Overall Stock Report | Completed | P0 | |
| User Log Report | Completed | P1 | |
| Stock Adjustment Report | Completed | P0 | |
| Detailed Report | Completed | P1 | |
| Variance Summary Report | Completed | P1 | |
| Stock Occurrence Report | Completed | P1 | |
| Excel export | Completed | P1 | Styled, auto-filtered, filters printed in the header |
| PDF export | Completed | P1 | Masthead, colour-coded variance, page numbers |
| Final Output | Completed | P0 | Workbook per audit, generated on demand |
| OneDrive share | Completed | P1 | **Explicit click only**; progress, failure, retry, history |
| OneDrive Graph driver | Completed | P1 | Written in full; untested against a live tenant — see D-02 |

## Administration

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| User Management | Completed | P1 | Roles, shop assignment, reset, activate/deactivate |
| Last-administrator guard | Completed | P1 | Prevents locking the system out |
| Settings | Completed | P1 | Application settings only; no secrets exposed |
| Activity Log | Completed | P1 | Filterable audit trail |
| Dashboard | Completed | P0 | Cards, variance breakdown, recent activity, quick actions |

## Quality

| Module | Status | Priority | Notes |
| --- | --- | --- | --- |
| Demo seed data | Completed | P0 | 3 shops · 22 products · 9 devices · 14 audits · 107 counted lines |
| Backend feature tests | Completed | P1 | 70 tests, 369 assertions, all passing on all three engines |
| API rate limiting | Completed | P0 | Login throttled per email+origin, authenticated traffic per user, stock import guarded. 429 uses the standard envelope. Verified live against the file cache store |
| Documentation accuracy | Completed | P1 | docs/02, 03, 05, 13, 14 reconciled against the code on 2026-08-26 |
| Frontend automated tests | Completed | P1 | 66 Vitest tests: DataTable, useTableQuery, AuthContext, route guards, and the Adjust, Verify and Stock Take dialogs. Closes limitation L-03 |
| Frontend type checking | Completed | P1 | Clean |
| Frontend production build | Completed | P1 | Succeeds; ~830 KB, ~248 KB gzipped |
| API integration walkthrough | Completed | P0 | Full flow exercised over HTTP against the running server |
| Browser UI verification | Completed | P0 | Manual walkthrough performed by the project team on 2026-08-25. All screens and the full end-to-end flow exercised; no functional, UI, navigation or validation issues found |
| SQL Server verification (core) | Completed | P0 | Migrations, full seed and all 52 core tests run against SQL Server 2022 on 2026-08-26. Three defects found and fixed |
| SQL Server verification (with Stock Report) | Completed | P0 | Re-run 2026-08-26 after the filtered-index fix: **all 60 pass on SQL Server, MySQL and SQLite**, with `migrate:fresh` verified on each. The fix was a compatibility change only; no Stock Report business rule was altered |
| Live OneDrive verification | Blocked | P1 | Needs the Azure app registration — D-02 |
| Documentation | Completed | P1 | README, PROJECT-STATUS, docs/01–17 |

---

## Verified behaviour

Each confirmed by an automated test and by the API walkthrough:

| Rule | Evidence |
| --- | --- |
| Import replaces rather than appends | `StockImportTest` — previous rows gone, `replaced_records` correct |
| A failed import changes nothing | `StockImportTest` — stock intact after a fatal error |
| Another shop's stock is untouched | `StockImportTest` |
| A retried submission creates no duplicate | `HhtSubmissionTest` — same audit id returned |
| One audit number, several devices | `HhtSubmissionTest` — two separate audits |
| Variance = physical − system | `StockAdjustmentTest`, `VerificationAndReportTest` |
| Adjustment posts immediately | `StockAdjustmentTest` — stock changed, no approval state |
| A line cannot be adjusted twice | `StockAdjustmentTest` |
| Stock take leaves the item master alone | `StockAdjustmentTest` |
| A Shop User is scoped and refused server side | `AuthAndAccessTest` — 403, not a filtered view |
| Nothing uploads before the Share click | `AuthAndAccessTest` |
| No stack traces reach the user | `AuthAndAccessTest` |
| All nine reports run and export | `VerificationAndReportTest` — valid XLSX and PDF |
| Every status value fits its column | `SchemaFitsStatusValuesTest` — 14 columns checked against their migrations |

### Defect found and fixed during verification

`stock_imports.status` was declared as 20 characters, but the application writes
`completed_with_errors` — 21. SQLite accepts an over-long string, so the whole
test suite passed while MySQL returned a 500 on any import containing invalid
rows. Found by importing the sample file with deliberate errors against MySQL.
The column is now 40 characters, and `SchemaFitsStatusValuesTest` guards all 14
status columns against a repeat.

---

## Open with the client

| # | Item | Blocks | Effort once resolved |
| --- | --- | --- | --- |
| D-02 | Azure app registration for OneDrive | A live upload test | One environment change |
| D-03 | The real HHT payload specification | Confirming our contract | Half a day if a mapping is needed |
| D-04 | Confirmation of report columns | Nothing — cheap to change | An hour per report |
| **D-07** | **Stock Report business decisions** | **The Stock Report module, currently on hold** | Nil if confirmed as built; see docs/17 §6 for the cost of each alternative |
| D-05 | OneDrive folder structure | Nothing — a default is in use | Minutes, if a different layout is wanted |
| D-06 | Production environment | Deployment, not development | Environment-dependent |

Detail in [docs/15-ASSUMPTIONS-DEPENDENCIES.md](docs/15-ASSUMPTIONS-DEPENDENCIES.md).

---

## Next

1. Confirm the four Stock Report decisions with the client so the module can come off hold (D-07).
2. Rehearse [docs/14](docs/14-DEMO-GUIDE.md) end to end before the client session.
2. Switch OneDrive to the Graph driver once credentials arrive (D-02).
3. Confirm the HHT payload and the report columns with the client (D-03, D-04).
4. Repeat the SQL Server run against the client instance before go-live, and confirm the delete-behaviour change in docs/15 A-15.
