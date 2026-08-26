# 02 — Requirements Specification

**Status values:** `Planned` | `In Progress` | `Implemented` | `On Hold`
**Priority values:** `P0` (mandatory) | `P1` (required) | `P2` (polish)

**Last reconciled against the code on 2026-08-26.** `Implemented` means the
behaviour exists, is reachable through the API and is covered by at least one
passing feature test. Where a client dependency is still open it is noted under
the module — the feature is built; what is outstanding is confirmation or live
verification, not development.

---

## Authentication (AUTH)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| AUTH-001 | User login with email and password | P0 | Valid credentials return an API token and the user profile with permissions | Implemented |
| AUTH-002 | Reject invalid login | P0 | Invalid credentials return 422 with a friendly message and no token | Implemented |
| AUTH-003 | Logout | P0 | Token is revoked and subsequent calls return 401 | Implemented |
| AUTH-004 | Inactive users cannot log in | P0 | A deactivated user is refused with a clear message | Implemented |
| AUTH-005 | Record last login | P1 | `last_login_at` updates on successful login | Implemented |

## Roles and Permissions (RBAC)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| RBAC-001 | Permission-based authorisation, not hard-coded roles | P1 | Access decisions read permissions; roles are collections of permissions | Implemented |
| RBAC-002 | Backend enforcement | P0 | A user without the permission receives 403 from the API even if the UI is bypassed | Implemented |
| RBAC-003 | Shop scoping for Shop Users | P1 | A Shop User sees only data for shops assigned to them | Implemented |
| RBAC-004 | Three seeded roles | P1 | Administrator, Supervisor, Shop User exist with the documented matrix | Implemented |

## Shops (SHOP)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| SHOP-001 | List shops with search, filter, sort, pagination | P0 | List returns paginated results honouring filters | Implemented |
| SHOP-002 | Create shop | P0 | Shop code is unique; record is created with status Active | Implemented |
| SHOP-003 | Edit shop | P0 | Changes persist and are logged | Implemented |
| SHOP-004 | View shop | P0 | Detail shows all fields including created and modified dates | Implemented |
| SHOP-005 | Activate / deactivate shop | P0 | Status toggles without deleting data | Implemented |

## Items (ITEM)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| ITEM-001 | List items with search and filters | P0 | Search matches product code, barcode and description | Implemented |
| ITEM-002 | Create item | P0 | Product code is unique; UOM and price are captured | Implemented |
| ITEM-003 | Edit item | P0 | Changes persist and are logged | Implemented |
| ITEM-004 | Activate / deactivate item | P0 | Status toggles | Implemented |

## Devices (DEV)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| DEV-001 | Register HHT devices per shop | P0 | Device code is unique within a shop | Implemented |
| DEV-002 | Activate / deactivate device | P1 | An inactive device is refused at submission | Implemented |

## Item Stock Import (STOCK)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| STOCK-001 | Import the business Stock Report (XLS/XLSX) | P0 | The three-sheet report is imported and summarised; see docs/17 | Implemented |
| STOCK-002 | Replace existing stock | P0 | Previous stock for every shop the report covers is removed and replaced atomically, never appended; shops it does not mention are untouched | Implemented |
| STOCK-003 | Validate workbook, sheets and columns | P0 | Wrong extension, a missing sheet or a missing column is rejected before any write | Implemented |
| STOCK-004 | Validate rows | P0 | Empty values, invalid quantity, invalid date and duplicate rows are reported per row with a reason | Implemented |
| STOCK-005 | Import summary | P0 | Total, successful, failed, imported by, imported date and status are shown | Implemented |
| STOCK-006 | Failed import leaves stock intact | P0 | A fatal error rolls back; previous stock is still queryable | Implemented |
| STOCK-007 | Multi-shop stock | P0 | The same product in three shops produces three separate stock records | Implemented |
| STOCK-008 | Item Stock screen | P0 | Shop, product, barcode, batch, expiry and status filters with pagination and sorting | Implemented |
| STOCK-009 | Handle a large Stock Report | P0 | ~152,000 rows import within the default memory limit using chunked reading and bulk writes | Implemented |
| STOCK-010 | Sync the Item Master from the report | P0 | Products introduced by the report are created and known ones refreshed; none removed | Implemented |
| STOCK-011 | Match shops by warehouse code | P0 | `INVENTLOCATIONID` resolves to a shop through its AX location; unmatched codes are reported per row | Implemented |

> **STOCK-001, STOCK-002, STOCK-003 and STOCK-009 to STOCK-011** describe the
> business Stock Report import. It is built and tested, but the module is
> **ON HOLD pending client confirmation (D-05)** of four business decisions —
> see `17-STOCK-REPORT-IMPORT.md` §6. The flat single-sheet import is unaffected
> and fully signed off.

## HHT Submission (HHT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| HHT-001 | Receive a completed HHT audit | P0 | A valid submission creates an audit header and its lines | Implemented |
| HHT-002 | Duplicate submission protection | P0 | Re-sending the same submission returns the existing audit and creates no duplicate | Implemented |
| HHT-003 | Shop + Device + Audit Number identity | P0 | The same audit number on different devices of a shop is accepted as separate audits | Implemented |
| HHT-004 | Validation | P0 | Unknown shop, unknown device, empty items or negative quantity are rejected with a clear message | Implemented |
| HHT-005 | Submission list and detail | P0 | Submissions are listed with shop, device, audit number, status and received date | Implemented |
| HHT-006 | Demo submission mechanism | P0 | An in-app simulator can post a realistic submission for demonstration | Implemented |

## Stock Audit (AUDIT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| AUDIT-001 | Audit list with filters | P0 | Filter by shop, audit number, device, date and status | Implemented |
| AUDIT-002 | Audit details | P0 | Header information plus all lines with system, physical and variance quantities | Implemented |
| AUDIT-003 | Item count | P1 | Header shows the number of lines submitted | Implemented |

## Stock Verification (VERIFY)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| VERIFY-001 | Compare system versus physical quantity | P0 | Each line shows both quantities and the variance | Implemented |
| VERIFY-002 | Edit permitted fields on a completed audit | P0 | Only users with `verification.edit` can change a line | Implemented |
| VERIFY-003 | Audit trail for edits | P1 | User, date, time, record, field, old value, new value and action are recorded | Implemented |
| VERIFY-004 | Mark audit verified | P0 | Audit status becomes Verified once its lines are verified | Implemented |

## Variance (VAR)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| VAR-001 | Calculate variance | P0 | variance equals physical minus system; 100 against 95 yields -5 | Implemented |
| VAR-002 | Variance filters | P0 | Shop, audit, product, barcode, batch, date and positive / negative / zero filters | Implemented |
| VAR-003 | Variance summary | P1 | Counts and totals for positive, negative and zero variance | Implemented |

## Stock Adjustment (ADJ)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| ADJ-001 | Immediate adjustment | P0 | Saving the adjustment updates system stock at once; no approval screen exists | Implemented |
| ADJ-002 | Adjustment history | P0 | Old quantity, physical quantity, variance, new quantity, user and time are retained | Implemented |
| ADJ-003 | Permission controlled | P1 | Only users with `adjustments.create` may post | Implemented |
| ADJ-004 | Re-adjustment guard | P1 | An already adjusted line is clearly marked and not silently double-posted | Implemented |

## Stock Take (TAKE)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| TAKE-001 | Record physical stock absent from stock data | P0 | A stock take record is created with shop, barcode, product, quantity, batch, expiry, location, date and user | Implemented |
| TAKE-002 | No automatic Item Master creation | P0 | Item Master is unchanged after a stock take | Implemented |
| TAKE-003 | List and filter stock takes | P1 | Filter by shop, date and product | Implemented |

## Reports (REPORT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| REPORT-001 | Variance Report | P0 | Detailed variance rows with filters | Implemented |
| REPORT-002 | Audit No. Based Report | P0 | Includes shop and device context because audit numbers repeat | Implemented |
| REPORT-003 | Individual Shop Based Report | P0 | Stock information for a selected shop | Implemented |
| REPORT-004 | Overall Stock Report | P0 | Consolidated stock across shops | Implemented |
| REPORT-005 | User Log Report | P1 | User activities and important actions | Implemented |
| REPORT-006 | Stock Adjustment Report | P0 | Adjustment details with before and after quantities | Implemented |
| REPORT-007 | Detailed Report | P1 | Detailed stock and audit information | Implemented |
| REPORT-008 | Variance Summary Report | P1 | Aggregated variance figures | Implemented |
| REPORT-009 | Stock Occurrence Report | P1 | Occurrence of a product across shops and audits | Implemented |
| REPORT-010 | Excel export | P1 | Every report exports to XLSX honouring the active filters | Implemented |
| REPORT-011 | PDF export | P1 | Every report exports to PDF honouring the active filters | Implemented |

> **REPORT-001 to REPORT-009** are implemented and each one runs; **REPORT-010
> and REPORT-011** produce valid XLSX and PDF files, asserted by test. The
> **columns** each report carries were chosen by us and still await client
> sign-off (**D-04**) — a change there is a change of content, not of
> capability.

## Final Output and OneDrive (OUT / ONEDRIVE)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| OUT-001 | Generate final output | P0 | Produces a file with shop, audit number, verification status, adjustment status, record count, generated date and generated by | Implemented |
| ONEDRIVE-001 | Manual share only | P0 | Upload happens only after the user clicks Share to OneDrive | Implemented |
| ONEDRIVE-002 | Progress and result states | P1 | Uploading, success and failure states are shown with the file name | Implemented |
| ONEDRIVE-003 | Retry on failure | P1 | A failed upload can be retried and the attempt count increments | Implemented |
| ONEDRIVE-004 | Upload history | P1 | Previous uploads and their status are listed | Implemented |

> **ONEDRIVE-001 to ONEDRIVE-004** are implemented and tested against the
> demonstration driver, including the rule that nothing uploads before the user
> clicks Share. The Microsoft Graph driver is written in full but has **never
> run against a live tenant**, because the Azure application registration is
> still outstanding (**D-02**).

## User Management and Settings (USER / SET)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| USER-001 | List, create, edit users | P1 | Includes role assignment and shop assignment | Implemented |
| USER-002 | Activate / deactivate user | P1 | Status toggles; inactive users cannot log in | Implemented |
| USER-003 | Reset password | P1 | An administrator can set a new password | Implemented |
| USER-004 | Show last login | P1 | Last login timestamp is visible in the list | Implemented |
| SET-001 | Application settings | P1 | Only safe application settings are exposed; no database credentials | Implemented |

## Cross-cutting (SYS)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| SYS-001 | Friendly errors | P0 | No stack traces, SQL errors or raw exceptions reach the user | Implemented |
| SYS-002 | Consistent JSON API responses | P1 | Every endpoint returns the documented envelope and correct HTTP status | Implemented |
| SYS-003 | Activity logging | P1 | Significant actions are recorded with user and timestamp | Implemented |
| SYS-004 | Reusable data table | P1 | List screens share one table component with search, filter, sort, pagination and empty state | Implemented |
| SYS-005 | Dashboard | P0 | Cards, recent activity and quick actions reflect real data | Implemented |
