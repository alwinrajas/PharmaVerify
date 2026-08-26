# 02 — Requirements Specification

**Status values:** `Planned` | `In Progress` | `Implemented`
**Priority values:** `P0` (mandatory) | `P1` (required) | `P2` (polish)

---

## Authentication (AUTH)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| AUTH-001 | User login with email and password | P0 | Valid credentials return an API token and the user profile with permissions | Planned |
| AUTH-002 | Reject invalid login | P0 | Invalid credentials return 422 with a friendly message and no token | Planned |
| AUTH-003 | Logout | P0 | Token is revoked and subsequent calls return 401 | Planned |
| AUTH-004 | Inactive users cannot log in | P0 | A deactivated user is refused with a clear message | Planned |
| AUTH-005 | Record last login | P1 | `last_login_at` updates on successful login | Planned |

## Roles and Permissions (RBAC)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| RBAC-001 | Permission-based authorisation, not hard-coded roles | P1 | Access decisions read permissions; roles are collections of permissions | Planned |
| RBAC-002 | Backend enforcement | P0 | A user without the permission receives 403 from the API even if the UI is bypassed | Planned |
| RBAC-003 | Shop scoping for Shop Users | P1 | A Shop User sees only data for shops assigned to them | Planned |
| RBAC-004 | Three seeded roles | P1 | Administrator, Supervisor, Shop User exist with the documented matrix | Planned |

## Shops (SHOP)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| SHOP-001 | List shops with search, filter, sort, pagination | P0 | List returns paginated results honouring filters | Planned |
| SHOP-002 | Create shop | P0 | Shop code is unique; record is created with status Active | Planned |
| SHOP-003 | Edit shop | P0 | Changes persist and are logged | Planned |
| SHOP-004 | View shop | P0 | Detail shows all fields including created and modified dates | Planned |
| SHOP-005 | Activate / deactivate shop | P0 | Status toggles without deleting data | Planned |

## Items (ITEM)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| ITEM-001 | List items with search and filters | P0 | Search matches product code, barcode and description | Planned |
| ITEM-002 | Create item | P0 | Product code is unique; UOM and price are captured | Planned |
| ITEM-003 | Edit item | P0 | Changes persist and are logged | Planned |
| ITEM-004 | Activate / deactivate item | P0 | Status toggles | Planned |

## Devices (DEV)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| DEV-001 | Register HHT devices per shop | P0 | Device code is unique within a shop | Planned |
| DEV-002 | Activate / deactivate device | P1 | An inactive device is refused at submission | Planned |

## Item Stock Import (STOCK)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| STOCK-001 | Import the business Stock Report (XLS/XLSX) | P0 | The three-sheet report is imported and summarised; see docs/17 | Implemented |
| STOCK-002 | Replace existing stock | P0 | Previous stock for every shop the report covers is removed and replaced atomically, never appended; shops it does not mention are untouched | Implemented |
| STOCK-003 | Validate workbook, sheets and columns | P0 | Wrong extension, a missing sheet or a missing column is rejected before any write | Implemented |
| STOCK-004 | Validate rows | P0 | Empty values, invalid quantity, invalid date and duplicate rows are reported per row with a reason | Planned |
| STOCK-005 | Import summary | P0 | Total, successful, failed, imported by, imported date and status are shown | Planned |
| STOCK-006 | Failed import leaves stock intact | P0 | A fatal error rolls back; previous stock is still queryable | Planned |
| STOCK-007 | Multi-shop stock | P0 | The same product in three shops produces three separate stock records | Planned |
| STOCK-008 | Item Stock screen | P0 | Shop, product, barcode, batch, expiry and status filters with pagination and sorting | Implemented |
| STOCK-009 | Handle a large Stock Report | P0 | ~152,000 rows import within the default memory limit using chunked reading and bulk writes | Implemented |
| STOCK-010 | Sync the Item Master from the report | P0 | Products introduced by the report are created and known ones refreshed; none removed | Implemented |
| STOCK-011 | Match shops by warehouse code | P0 | `INVENTLOCATIONID` resolves to a shop through its AX location; unmatched codes are reported per row | Implemented |

## HHT Submission (HHT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| HHT-001 | Receive a completed HHT audit | P0 | A valid submission creates an audit header and its lines | Planned |
| HHT-002 | Duplicate submission protection | P0 | Re-sending the same submission returns the existing audit and creates no duplicate | Planned |
| HHT-003 | Shop + Device + Audit Number identity | P0 | The same audit number on different devices of a shop is accepted as separate audits | Planned |
| HHT-004 | Validation | P0 | Unknown shop, unknown device, empty items or negative quantity are rejected with a clear message | Planned |
| HHT-005 | Submission list and detail | P0 | Submissions are listed with shop, device, audit number, status and received date | Planned |
| HHT-006 | Demo submission mechanism | P0 | An in-app simulator can post a realistic submission for demonstration | Planned |

## Stock Audit (AUDIT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| AUDIT-001 | Audit list with filters | P0 | Filter by shop, audit number, device, date and status | Planned |
| AUDIT-002 | Audit details | P0 | Header information plus all lines with system, physical and variance quantities | Planned |
| AUDIT-003 | Item count | P1 | Header shows the number of lines submitted | Planned |

## Stock Verification (VERIFY)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| VERIFY-001 | Compare system versus physical quantity | P0 | Each line shows both quantities and the variance | Planned |
| VERIFY-002 | Edit permitted fields on a completed audit | P0 | Only users with `verification.edit` can change a line | Planned |
| VERIFY-003 | Audit trail for edits | P1 | User, date, time, record, field, old value, new value and action are recorded | Planned |
| VERIFY-004 | Mark audit verified | P0 | Audit status becomes Verified once its lines are verified | Planned |

## Variance (VAR)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| VAR-001 | Calculate variance | P0 | variance equals physical minus system; 100 against 95 yields -5 | Planned |
| VAR-002 | Variance filters | P0 | Shop, audit, product, barcode, batch, date and positive / negative / zero filters | Planned |
| VAR-003 | Variance summary | P1 | Counts and totals for positive, negative and zero variance | Planned |

## Stock Adjustment (ADJ)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| ADJ-001 | Immediate adjustment | P0 | Saving the adjustment updates system stock at once; no approval screen exists | Planned |
| ADJ-002 | Adjustment history | P0 | Old quantity, physical quantity, variance, new quantity, user and time are retained | Planned |
| ADJ-003 | Permission controlled | P1 | Only users with `adjustments.create` may post | Planned |
| ADJ-004 | Re-adjustment guard | P1 | An already adjusted line is clearly marked and not silently double-posted | Planned |

## Stock Take (TAKE)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| TAKE-001 | Record physical stock absent from stock data | P0 | A stock take record is created with shop, barcode, product, quantity, batch, expiry, location, date and user | Planned |
| TAKE-002 | No automatic Item Master creation | P0 | Item Master is unchanged after a stock take | Planned |
| TAKE-003 | List and filter stock takes | P1 | Filter by shop, date and product | Planned |

## Reports (REPORT)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| REPORT-001 | Variance Report | P0 | Detailed variance rows with filters | Planned |
| REPORT-002 | Audit No. Based Report | P0 | Includes shop and device context because audit numbers repeat | Planned |
| REPORT-003 | Individual Shop Based Report | P0 | Stock information for a selected shop | Planned |
| REPORT-004 | Overall Stock Report | P0 | Consolidated stock across shops | Planned |
| REPORT-005 | User Log Report | P1 | User activities and important actions | Planned |
| REPORT-006 | Stock Adjustment Report | P0 | Adjustment details with before and after quantities | Planned |
| REPORT-007 | Detailed Report | P1 | Detailed stock and audit information | Planned |
| REPORT-008 | Variance Summary Report | P1 | Aggregated variance figures | Planned |
| REPORT-009 | Stock Occurrence Report | P1 | Occurrence of a product across shops and audits | Planned |
| REPORT-010 | Excel export | P1 | Every report exports to XLSX honouring the active filters | Planned |
| REPORT-011 | PDF export | P1 | Every report exports to PDF honouring the active filters | Planned |

## Final Output and OneDrive (OUT / ONEDRIVE)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| OUT-001 | Generate final output | P0 | Produces a file with shop, audit number, verification status, adjustment status, record count, generated date and generated by | Planned |
| ONEDRIVE-001 | Manual share only | P0 | Upload happens only after the user clicks Share to OneDrive | Planned |
| ONEDRIVE-002 | Progress and result states | P1 | Uploading, success and failure states are shown with the file name | Planned |
| ONEDRIVE-003 | Retry on failure | P1 | A failed upload can be retried and the attempt count increments | Planned |
| ONEDRIVE-004 | Upload history | P1 | Previous uploads and their status are listed | Planned |

## User Management and Settings (USER / SET)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| USER-001 | List, create, edit users | P1 | Includes role assignment and shop assignment | Planned |
| USER-002 | Activate / deactivate user | P1 | Status toggles; inactive users cannot log in | Planned |
| USER-003 | Reset password | P1 | An administrator can set a new password | Planned |
| USER-004 | Show last login | P1 | Last login timestamp is visible in the list | Planned |
| SET-001 | Application settings | P1 | Only safe application settings are exposed; no database credentials | Planned |

## Cross-cutting (SYS)

| ID | Requirement | Priority | Acceptance Criteria | Status |
| --- | --- | --- | --- | --- |
| SYS-001 | Friendly errors | P0 | No stack traces, SQL errors or raw exceptions reach the user | Planned |
| SYS-002 | Consistent JSON API responses | P1 | Every endpoint returns the documented envelope and correct HTTP status | Planned |
| SYS-003 | Activity logging | P1 | Significant actions are recorded with user and timestamp | Planned |
| SYS-004 | Reusable data table | P1 | List screens share one table component with search, filter, sort, pagination and empty state | Planned |
| SYS-005 | Dashboard | P0 | Cards, recent activity and quick actions reflect real data | Planned |
