# 11 — Test Cases

Two layers:

- **Automated** — `backend/tests/Feature`, run with `php artisan test`.
  52 tests, 248 assertions, all passing. They cover the rules most likely to
  regress: import replacement, HHT idempotency, variance calculation, immediate
  adjustment, stock take, RBAC and error shape.
- **Manual** — the walkthrough below. **Executed in full on 2026-08-25 by the
  project team: all screens and the complete end-to-end business flow passed,
  with no functional, UI, navigation or validation issues found.** Repeat it
  after any frontend change, since the frontend has no automated tests.

Automated tests run on SQLite, which also confirms the schema and queries carry
no engine-specific SQL — useful evidence for the SQL Server target.

SQLite does *not* enforce string lengths, so `SchemaFitsStatusValuesTest` reads
each status column's declared length straight from its migration and checks that
every value the application writes fits. That check was added after a real
defect: `completed_with_errors` is 21 characters and the column held 20, which
every SQLite test accepted and MySQL rejected.

Status values: `Pass` | `Fail` | `Blocked` | `Not Run`

---

## 1. Automated coverage

| Test ID | File | Scenario | Status |
| --- | --- | --- | --- |
| AT-HHT-001 | HhtSubmissionTest | A completed count creates an audit and its lines | Pass |
| AT-HHT-002 | HhtSubmissionTest | Resending the same submission creates no duplicate | Pass |
| AT-HHT-003 | HhtSubmissionTest | The same audit number is accepted on different devices | Pass |
| AT-HHT-004 | HhtSubmissionTest | A second count under one device's audit number is refused (409) | Pass |
| AT-HHT-005 | HhtSubmissionTest | An unknown device is rejected with a readable message | Pass |
| AT-HHT-006 | HhtSubmissionTest | A negative physical quantity is rejected | Pass |
| AT-HHT-007 | HhtSubmissionTest | A product missing from the stock file is flagged as unknown | Pass |
| AT-STOCK-001 | StockImportTest | Importing replaces the shop's existing stock | Pass |
| AT-STOCK-002 | StockImportTest | Another shop's stock is untouched | Pass |
| AT-STOCK-003 | StockImportTest | Invalid rows are reported; valid rows still import | Pass |
| AT-STOCK-004 | StockImportTest | A missing required column changes nothing | Pass |
| AT-STOCK-005 | StockImportTest | A wholly invalid file leaves existing stock intact | Pass |
| AT-STOCK-006 | StockImportTest | A user without `stock.import` is refused | Pass |
| AT-STOCK-007 | StockImportTest | Header wording is matched flexibly | Pass |
| AT-ADJ-001 | StockAdjustmentTest | Variance is physical minus system | Pass |
| AT-ADJ-002 | StockAdjustmentTest | Saving an adjustment updates system stock immediately | Pass |
| AT-ADJ-003 | StockAdjustmentTest | An adjusted line is marked and its variance cleared | Pass |
| AT-ADJ-004 | StockAdjustmentTest | The same line cannot be adjusted twice | Pass |
| AT-ADJ-005 | StockAdjustmentTest | A product missing from the stock file cannot be adjusted | Pass |
| AT-ADJ-006 | StockAdjustmentTest | A Shop User cannot post an adjustment | Pass |
| AT-TAKE-001 | StockAdjustmentTest | A stock take creates no item master record | Pass |
| AT-VERIFY-001 | VerificationAndReportTest | Correcting a count recomputes variance and is logged | Pass |
| AT-VERIFY-002 | VerificationAndReportTest | A user without the permission cannot edit a line | Pass |
| AT-VERIFY-003 | VerificationAndReportTest | A negative quantity is refused during verification | Pass |
| AT-VERIFY-004 | VerificationAndReportTest | Verifying the audit marks every outstanding line | Pass |
| AT-VAR-001 | VerificationAndReportTest | Variance summarises short, excess and matched lines | Pass |
| AT-REPORT-001 | VerificationAndReportTest | All nine reports run, Excel and PDF are valid files | Pass |
| AT-REPORT-002 | VerificationAndReportTest | An unknown report key returns a readable error | Pass |
| AT-AUTH-001 | AuthAndAccessTest | Valid credentials return a token and permissions | Pass |
| AT-AUTH-002 | AuthAndAccessTest | Invalid credentials are refused without a token | Pass |
| AT-AUTH-003 | AuthAndAccessTest | A deactivated user cannot sign in | Pass |
| AT-AUTH-004 | AuthAndAccessTest | An unauthenticated API request is refused | Pass |
| AT-RBAC-001 | AuthAndAccessTest | A Shop User sees only their assigned shops | Pass |
| AT-RBAC-002 | AuthAndAccessTest | A Supervisor sees every shop | Pass |
| AT-RBAC-003 | AuthAndAccessTest | User management is closed to non-administrators | Pass |
| AT-RBAC-004 | AuthAndAccessTest | The last active administrator cannot be deactivated | Pass |
| AT-SYS-001 | AuthAndAccessTest | A failure returns a readable message, not a trace | Pass |
| AT-OUT-001 | AuthAndAccessTest | A final output is not uploaded until the user asks | Pass |
| AT-SCHEMA-001…014 | SchemaFitsStatusValuesTest | Every status value fits its declared column (14 columns) | Pass |

```bash
cd backend
php artisan test
```

---

## 2. Manual test cases

**Execution record — 2026-08-25.** Performed by the project team against the
seeded demonstration data. Every case below passed. No functional, UI,
navigation or validation issues were found, and no defects were raised.

### TC-AUTH-001 — Valid login

**Pre:** Seeded demo data.
**Steps:** Open the application → enter `admin@pharmaverify.com` / `Pharma@2026` → Sign in.
**Expected:** The dashboard opens with populated cards; the user's name and role appear top right.

### TC-AUTH-002 — Invalid login

**Steps:** Enter a valid email and a wrong password → Sign in.
**Expected:** “The email address or password is incorrect.” No navigation, no token stored.

### TC-AUTH-003 — Session expiry

**Steps:** Sign in, clear the stored token in the browser, click any menu entry.
**Expected:** Returned to the sign-in screen. No broken page or raw error.

### TC-SHOP-001 — Create a shop

**Steps:** Master → Shops → Add Shop → complete the form → Create.
**Expected:** Snackbar confirmation; the shop appears in the list as Active.

### TC-SHOP-002 — Duplicate shop code

**Steps:** Add a shop using an existing code.
**Expected:** “This shop code is already in use.” inline in the dialog; nothing created.

### TC-ITEM-001 — Create an item

**Steps:** Master → Items → Add Item → complete → Create.
**Expected:** Snackbar; item listed; searchable by code, barcode and description.

### TC-DEV-001 — Device code reused across shops

**Steps:** Register `HHT-01` in shop A, then `HHT-01` in shop B.
**Expected:** Both accepted. Registering `HHT-01` twice in the *same* shop is refused.

### TC-STOCK-001 — Import stock

**Steps:** Master → Item Stock Import → choose a shop and a valid file → Import → confirm.
**Expected:** Progress, then a summary showing total, imported, failed and previous records replaced.

### TC-STOCK-002 — Replacement, not append *(critical)*

**Pre:** The shop already has stock, note the record count.
**Steps:** Import a different file for the same shop.
**Expected:** Item Stock shows only the new file's rows. The previous rows are gone, not added to. The summary's “replaced” figure equals the previous count.

### TC-STOCK-003 — Invalid rows

**Steps:** Import `database/sample-data/stock-phm001-with-errors.xlsx` for shop PHM001.
**Expected:** 8 rows import, 6 are rejected, status is *Completed with errors*.
Clicking the failed count lists each with its reason:

| Row | Reason |
| --- | --- |
| 10 | System stock must be a number. |
| 11 | Product code is required. |
| 12 | System stock cannot be negative. |
| 13 | Product description is required. |
| 14 | Duplicate of row 2. The same product and batch cannot appear twice in one file. |
| 15 | This row belongs to a different shop. The import is running for PHM001. |

### TC-STOCK-004 — Fatal import leaves stock intact *(critical)*

**Steps:** Import a file missing the System Stock column.
**Expected:** A readable message naming the missing column. The shop's previous stock is unchanged, and no import record is created.

### TC-STOCK-005 — Multi-shop stock

**Steps:** Import the same product for three shops. Filter Item Stock by each.
**Expected:** Three separate records. Barcode alone does not identify stock.

### TC-HHT-001 — Submit a count

**Steps:** HHT Submissions → HHT Simulator → choose shop and device, set an unused audit number → Load 8 products → Apply a variance spread → Share / Submit.
**Expected:** Banner naming the audit created. The submission appears as *Accepted*; the audit appears in Stock Audit.

### TC-HHT-002 — Duplicate submission *(critical)*

**Steps:** Press Share / Submit again without changing anything.
**Expected:** *Duplicate submission ignored.* Stock Audit still shows one audit; the line count is unchanged.

### TC-HHT-003 — Same audit number on another device

**Steps:** In the Simulator, keep the audit number and choose a different device of the same shop → Submit.
**Expected:** Accepted, creating a second, separate audit.

### TC-HHT-004 — Unknown device

**Steps:** Post a submission naming a device that does not belong to the shop (via the API).
**Expected:** 422, “The device in this submission is not registered against shop …”.

### TC-AUDIT-001 — Audit detail

**Steps:** Stock Audit → open an audit.
**Expected:** Header shows audit number, shop, device, counted by, dates and status. Summary shows lines, short, excess, matched, pending, net variance.

### TC-VERIFY-001 — Verify a line

**Steps:** Open the verify dialog on a line, change the physical quantity, add a remark, Save and verify.
**Expected:** Variance recalculates as physical − system; the line becomes Verified; a snackbar confirms.

### TC-VERIFY-002 — Audit trail

**Steps:** Administration → Activity Log after TC-VERIFY-001.
**Expected:** An entry under `verification` naming the user, time, field, old value and new value.

### TC-VERIFY-003 — Negative quantity refused

**Steps:** Enter a negative physical quantity and save.
**Expected:** “A physical quantity cannot be negative.” Nothing changes.

### TC-VAR-001 — Variance calculation *(critical)*

**Steps:** Find a line with system 100 and physical 95.
**Expected:** Variance shows −5 in red. A line with physical 103 shows +3 in green. An equal line shows 0 in grey.

### TC-VAR-002 — Variance filters

**Steps:** Filter Variance by short, excess, matched and all in turn.
**Expected:** The list and the summary figures agree with each selection.

### TC-ADJ-001 — Immediate adjustment *(critical)*

**Pre:** Note the Item Stock quantity for the product.
**Steps:** Variance → Adjust on a line with variance −5 → optional reason → Post adjustment.
**Expected:** No approval screen. Item Stock immediately shows the physical quantity. Stock Adjustment lists the change with before → after.

### TC-ADJ-002 — No double posting

**Steps:** Try to adjust the same line again.
**Expected:** The action is unavailable in the interface, and the API refuses with “…has already been adjusted…”.

### TC-ADJ-003 — Batch adjustment

**Steps:** Select several variance lines → Adjust N selected → Post.
**Expected:** All post; anything refused is listed separately with its reason.

### TC-TAKE-001 — Record a stock take *(critical)*

**Pre:** An audit containing a product not in the stock file (the Simulator can add one).
**Steps:** Stock Take → Record on the candidate → complete → Record stock take.
**Expected:** “…The item master has not been changed.” Master → Items is unchanged; the entry appears in Stock Take.

### TC-REPORT-001 — Run every report

**Steps:** Reports → open each of the nine in turn.
**Expected:** Each renders with its own columns and filters; summary figures where applicable.

### TC-REPORT-002 — Excel export

**Steps:** Apply a filter on the Variance Report → Excel.
**Expected:** The workbook downloads, opens cleanly, and contains only the filtered rows with the filters stated in the header.

### TC-REPORT-003 — PDF export

**Steps:** Same report → PDF.
**Expected:** The PDF opens, is landscape, shows the masthead and filters, and colours variance.

### TC-OUT-001 — Generate the final output

**Steps:** Final Output → Generate Final Output → choose an audit → Generate.
**Expected:** Listed with its record count and OneDrive status *Not Uploaded*. **Nothing has been uploaded.**

### TC-ONEDRIVE-001 — Share to OneDrive *(critical)*

**Steps:** Click Share on the row → confirm.
**Expected:** Progress, then *Uploaded* with the timestamp and attempt count. A snackbar names the file.

### TC-ONEDRIVE-002 — Failure and retry

**Pre:** `ONEDRIVE_DEMO_FAIL_RATE=1`, `php artisan config:clear`.
**Steps:** Generate a new output and click Share.
**Expected:** Status *Failed* with a readable reason and a Retry button; the attempt count increments. Reset the fail rate to 0 and Retry — it succeeds.

### TC-ONEDRIVE-003 — No re-upload

**Steps:** Try to share an already uploaded file.
**Expected:** The Share button is not offered; the API refuses with “already been shared”.

### TC-RBAC-001 — Shop User scope *(critical)*

**Steps:** Sign in as `annanagar@pharmaverify.com` / `Pharma@2026`.
**Expected:** Only the assigned shop appears in Shops, Item Stock, Audits and Variance. Stock Import, Users and Activity Log are absent from the sidebar.

### TC-RBAC-002 — Backend enforcement *(critical)*

**Steps:** As the Shop User, call `POST /api/adjustments` directly.
**Expected:** `403`, and no stock changes — the interface is not the control.

### TC-RBAC-003 — Supervisor scope

**Steps:** Sign in as `supervisor@pharmaverify.com`.
**Expected:** Every shop visible; can import, verify, adjust and share; Users is absent.

### TC-USER-001 — Create a user

**Steps:** Users → Add User → complete, choose a role, assign shops → Create.
**Expected:** Created and listed; the new user can sign in and sees exactly the assigned shops.

### TC-USER-002 — Deactivate

**Steps:** Deactivate a user, then attempt to sign in as them.
**Expected:** “This account has been deactivated…”. Any existing session is refused.

### TC-USER-003 — Last administrator

**Steps:** Try to deactivate the only active administrator.
**Expected:** Refused with an explanation. The account stays active.

### TC-SYS-001 — No technical errors surface

**Steps:** Throughout the walkthrough, watch every message.
**Expected:** No stack trace, SQL text, exception class or raw API error is ever shown.

### TC-SYS-002 — Empty states

**Steps:** Filter any list so nothing matches.
**Expected:** An explanatory empty state, not a blank table.

---

## 3. Pre-demonstration checklist

| # | Check |
| --- | --- |
| 1 | `php artisan migrate:fresh --seed` has been run and completed |
| 2 | `php artisan test` — all tests pass |
| 3 | Backend responds on its port; frontend responds on its port |
| 4 | Sign-in works for all three demonstration accounts |
| 5 | Dashboard cards show real figures, not zeroes |
| 6 | A stock file is ready for the import step — `database/sample-data/stock-phm001-valid.xlsx` |
| 7 | An unused audit number is ready for the Simulator |
| 8 | `ONEDRIVE_DEMO_FAIL_RATE=0` so the demonstration upload succeeds |
| 9 | At least one variance line is unadjusted, for the adjustment step |
| 10 | At least one stock take candidate exists |
